<?php

namespace App\Service\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ParametresConge;
use App\Entity\ReversementRetroAgent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;

/**
 * « PEUT-ON CRÉER CETTE FICHE D'ICI, POUR CET UTILISATEUR ? » — UNE SEULE RÉPONSE (Serge,
 * 2026-10-30).
 *
 * Tout point d'entrée qui crée une fiche lit cette décision : la barre d'outils et le menu
 * contextuel d'une liste (principale ou onglet contextuel), l'état vide, les collections des
 * dialogues, le tableau de bord, Ket. Aucun ne décide seul : un bouton qui mènerait à un refus
 * du serveur n'est pas affiché, et le serveur refuse à l'écriture par la même règle.
 *
 * Elle réunit, dans cet ordre :
 * 1. le droit d'ÉCRITURE de l'invité sur l'entité ;
 * 2. son MODE DE CRÉATION :
 *    - écrivain dédié — la fiche ne naît pas du formulaire générique (un reversement : par le
 *      rapport de production ou par Ket, justificatif exigé du même geste) ;
 *    - unique par cabinet — un seul jeu (paramètres des congés) : offert tant qu'il manque ;
 *    - depuis son parent — ParentObligatoire::PARENTS : seulement dans le contexte de CE parent
 *      (l'onglet contextuel dont le lien est le champ du registre) ;
 *    - libre — le reste, parent choisi dans le formulaire compris (ParentObligatoire::PARENT_CHOISI,
 *      exigé à l'écriture).
 */
final class DecisionDeCreation
{
    /** @var array<class-string, string> */
    public const ECRIVAIN_DEDIE = [
        ReversementRetroAgent::class => "Un reversement s'enregistre depuis « Voir le rapport de production » du bénéficiaire, ou par l'assistant : le justificatif du virement est exigé du même geste.",
    ];

    /** @var array<class-string, string> */
    public const UNIQUE_PAR_CABINET = [
        ParametresConge::class => "Le cabinet a déjà ses paramètres de congé : ouvrez-les pour les modifier.",
    ];

    public const RATTACHEMENT = "Cet onglet rattache des fiches existantes : une nouvelle fiche se crée depuis sa rubrique.";

    public const HORS_PERIMETRE ="Vous n'avez pas le droit de créer cette fiche. Le propriétaire de l'espace de travail peut ajuster vos droits.";

    public function __construct(
        private readonly WorkspaceAccessResolver $acces,
        private readonly EntityManagerInterface $em,
        private readonly InviteCourant $inviteCourant,
    ) {}

    /**
     * La création est-elle offerte depuis ce contexte ?
     *
     * @param string|null $champParent le champ qui rattache le contexte à son parent (onglet
     *   contextuel, collection) ; null pour une liste principale ou un bouton sans parent
     * @param bool $verifierLeDroit false quand une collection est gardée par le droit sur son
     *   PARENT (`gardeSurLeParent`) — ce droit-là est jugé par l'appelant
     *
     * @return array{offert: bool, motif: string|null}
     */
    public function pour(string $classe, Entreprise $entreprise, ?string $champParent = null, bool $verifierLeDroit = true): array
    {
        $classe = $this->classe($classe);
        if ($verifierLeDroit) {
            $invite = $this->inviteCourant->pour($entreprise);
            // `false` : contexte système (worker, ligne de commande) — il n'y a pas d'écran.
            if ($invite === null || ($invite instanceof Invite && !$this->acces->can($invite, $this->nomCourt($classe), Invite::ACCESS_ECRITURE))) {
                return self::non(self::HORS_PERIMETRE);
            }
        }
        if (isset(self::ECRIVAIN_DEDIE[$classe])) {
            return self::non(self::ECRIVAIN_DEDIE[$classe]);
        }
        if (isset(self::UNIQUE_PAR_CABINET[$classe]) && $this->autreDuCabinet($classe, $entreprise, null)) {
            return self::non(self::UNIQUE_PAR_CABINET[$classe]);
        }
        $champ = ParentObligatoire::champ($classe);
        if ($champ !== null && $champParent !== $champ) {
            return self::non(ParentObligatoire::PARENTS[$classe]['refus']);
        }

        return ['offert' => true, 'motif' => null];
    }

    /**
     * La décision d'un ONGLET CONTEXTUEL — la même pour sa barre d'outils, son menu et son état
     * vide (au rendu comme après une recherche).
     *
     * @param array{champ?: string|null, nature?: string|null}|null $lien le lien de l'onglet vers
     *   son parent (LienDeCollection) : seule une relation SIMPLE (`to_one`) rattache une fiche
     *   créée ici — sur un lien multiple (les partenaires associés d'un client), le dialogue
     *   n'aurait rien à poser et la fiche naîtrait détachée
     * @param string|null $ajoutImpossible la raison propre au PARENT (`ajoutImpossible` de sa
     *   collection : un invité qui a déjà son rôle Finance)
     *
     * @return array{offert: bool, motif: string|null}
     */
    public function pourUnOnglet(string $classe, Entreprise $entreprise, ?array $lien, ?string $ajoutImpossible = null): array
    {
        if (($lien['nature'] ?? null) !== 'to_one' || empty($lien['champ'])) {
            return self::non(self::RATTACHEMENT);
        }
        if ($ajoutImpossible !== null && trim($ajoutImpossible) !== '') {
            return self::non($ajoutImpossible);
        }

        return $this->pour($classe, $entreprise, $lien['champ']);
    }

    /**
     * Le refus d'une CRÉATION au moment de l'écrire (le droit est déjà jugé par l'appelant) ;
     * null si elle peut l'être.
     *
     * @param array<string, mixed>|null $soumis les données de la requête de l'écran (le parent
     *   doit y être nommé) ; null pour Ket et la reprise
     * @param bool $formulaireGenerique true pour `handleFormSubmission` : l'écrivain dédié y est
     *   refusé, pas dans son propre chemin (rapport de production, Ket)
     */
    public function refusALEcriture(object $entite, Entreprise $entreprise, ?array $soumis, bool $formulaireGenerique): ?string
    {
        $classe = $this->classe($entite);
        if ($formulaireGenerique && isset(self::ECRIVAIN_DEDIE[$classe])) {
            return self::ECRIVAIN_DEDIE[$classe];
        }
        if (isset(self::UNIQUE_PAR_CABINET[$classe]) && $this->autreDuCabinet($classe, $entreprise, $entite)) {
            return self::UNIQUE_PAR_CABINET[$classe];
        }

        return ParentObligatoire::refus($entite, $soumis);
    }

    /** @return array{offert: false, motif: string} */
    private static function non(string $motif): array
    {
        return ['offert' => false, 'motif' => $motif];
    }

    private function autreDuCabinet(string $classe, Entreprise $entreprise, ?object $sauf): bool
    {
        $requete = $this->em->createQueryBuilder()->select('COUNT(x.id)')->from($classe, 'x')
            ->where('x.entreprise = :entreprise')->setParameter('entreprise', $entreprise);
        if ($sauf !== null && method_exists($sauf, 'getId') && $sauf->getId() !== null) {
            $requete->andWhere('x.id <> :id')->setParameter('id', $sauf->getId());
        }

        return (int) $requete->getQuery()->getSingleScalarResult() > 0;
    }

    private function classe(string|object $entite): string
    {
        if (is_object($entite)) {
            return $entite instanceof Proxy ? (string) get_parent_class($entite) : $entite::class;
        }

        return $entite;
    }

    private function nomCourt(string $classe): string
    {
        return substr($classe, (int) strrpos($classe, '\\') + 1);
    }
}
