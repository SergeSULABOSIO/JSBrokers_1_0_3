<?php

namespace App\Ai\Reglage;

use App\Entity\KetReglageJournal;
use App\Entity\Utilisateur;
use App\Repository\PlateformeParametresRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE SEUL CHEMIN PAR LEQUEL UN RÉGLAGE DE KET CHANGE.
 *
 * ── POURQUOI UN SERVICE, ET PAS TROIS MÉTHODES DE CONTRÔLEUR ────────────────
 * Trois choses doivent arriver ENSEMBLE, ou pas du tout : la validation, l'écriture
 * et la ligne de journal. Un contrôleur qui les enchaîne finit par en oublier une
 * sur le quatrième bouton ajouté — et c'est toujours le journal qu'on oublie, parce
 * que c'est le seul dont l'absence ne se voit pas tout de suite. Ici, on ne peut pas
 * écrire sans journaliser : c'est la même méthode.
 *
 * ── LE REFUS EST DUR, PAS COSMÉTIQUE ────────────────────────────────────────
 * Un INVARIANT ou un INDISPENSABLE ne se coupe pas, et pas seulement parce que
 * l'écran n'affiche pas d'interrupteur : la validation est ici, sur le chemin
 * d'écriture, donc un appel direct à la route échoue exactement comme un clic. Même
 * chose pour les bornes d'un seuil. L'écran n'est jamais la garde.
 *
 * ── CE QUE CE SERVICE NE PEUT PAS FAIRE ─────────────────────────────────────
 * Ouvrir Ket à un compte qui n'y a pas droit, contourner `canRead()`, franchir le
 * cloisonnement entre cabinets. Ces règles vivent ailleurs — `PorteDeKet`,
 * `WorkspaceAccessResolver`, le scoping des outils — et aucune console ne les
 * atteint. Couper un outil retire une CAPACITÉ, jamais une garde.
 */
final class ApplicationDesReglages
{
    public function __construct(
        private readonly PlateformeParametresRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly ReglagesDeKet $reglages,
    ) {
    }

    /**
     * Active ou coupe un outil pour TOUTE la plateforme.
     *
     * @throws \DomainException si l'outil ne peut pas être coupé, ou si le motif manque
     */
    public function basculerOutil(string $nom, bool $actif, string $motif, ?Utilisateur $auteur): void
    {
        $classe = CatalogueDesReglages::classeDe($nom);
        if (!$classe->estModifiable()) {
            throw new \DomainException(sprintf(
                '« %s » est %s : il ne se coupe pas, même par un super-administrateur.',
                $nom,
                mb_strtolower($classe->libelle()),
            ));
        }

        $motif = $this->exigerUnMotif($motif);
        $avant = $this->reglages->outilActif($nom);
        if ($avant === $actif) {
            return; // Rien n'a changé : pas de ligne de journal pour un non-événement.
        }

        $carte = $this->carte();
        if ($actif) {
            // ON EFFACE LA CLÉ AU LIEU D'ÉCRIRE `true`. La carte ne porte que les
            // ÉCARTS : un outil réactivé redevient exactement un outil dont on n'a
            // jamais rien dit, et « rétablir les réglages par défaut » reste exact.
            unset($carte['outils'][$nom]);
        } else {
            $carte['outils'][$nom] = false;
        }

        $this->enregistrer($carte);
        $this->journaliser(
            KetReglageJournal::TYPE_OUTIL,
            'outil:' . $nom,
            $avant ? 'actif' : 'coupé',
            $actif ? 'actif' : 'coupé',
            $motif,
            $auteur,
        );
    }

    /**
     * Déplace PLUSIEURS seuils d'un coup, avec un seul motif.
     *
     * TOUT OU RIEN, et c'est la même règle que l'écran des fournisseurs : on valide
     * les quatre valeurs AVANT d'en écrire une seule. Enregistrer les trois bonnes et
     * refuser la quatrième laisserait l'agent devant un écran à moitié pris en
     * compte, sans savoir laquelle manque.
     *
     * UN SEUL MOTIF POUR PLUSIEURS LIGNES DE JOURNAL, et c'est voulu : celui qui
     * déplace deux seuils le fait pour une seule raison. Chaque seuil garde
     * néanmoins sa propre ligne — c'est par seuil qu'on relit l'historique.
     *
     * @param array<string, int> $valeurs
     *
     * @throws \DomainException à la première valeur refusée, avant toute écriture
     */
    public function reglerSeuils(array $valeurs, string $motif, ?Utilisateur $auteur): void
    {
        $motif = $this->exigerUnMotif($motif);

        foreach ($valeurs as $clef => $valeur) {
            $this->verifierSeuil((string) $clef, (int) $valeur);
        }

        foreach ($valeurs as $clef => $valeur) {
            $this->reglerParametre((string) $clef, (int) $valeur, $motif, $auteur);
        }
    }

    /**
     * @throws \DomainException si la clé est inconnue ou la valeur hors bornes
     */
    private function verifierSeuil(string $clef, int $valeur): array
    {
        $regle = ReglagesDeKet::PARAMETRES[$clef] ?? null;
        if ($regle === null) {
            throw new \DomainException(sprintf('Réglage inconnu : « %s ».', $clef));
        }
        if ($valeur < $regle['min'] || $valeur > $regle['max']) {
            throw new \DomainException(sprintf(
                '« %s » doit rester entre %d et %d %s. Valeur reçue : %d.',
                $regle['libelle'],
                $regle['min'],
                $regle['max'],
                $regle['unite'],
                $valeur,
            ));
        }

        return $regle;
    }

    /**
     * Déplace UN seuil métier, entre ses bornes.
     *
     * @throws \DomainException si la clé est inconnue, la valeur hors bornes, ou le motif absent
     */
    public function reglerParametre(string $clef, int $valeur, string $motif, ?Utilisateur $auteur): void
    {
        $regle = $this->verifierSeuil($clef, $valeur);

        $motif = $this->exigerUnMotif($motif);
        $avant = $this->reglages->parametre($clef);
        if ($avant === $valeur) {
            return;
        }

        $carte = $this->carte();
        if ($valeur === $regle['defaut']) {
            unset($carte['parametres'][$clef]); // Même raison que pour un outil réactivé.
        } else {
            $carte['parametres'][$clef] = $valeur;
        }

        $this->enregistrer($carte);
        $this->journaliser(
            KetReglageJournal::TYPE_PARAMETRE,
            'parametre:' . $clef,
            (string) $avant,
            (string) $valeur,
            $motif,
            $auteur,
        );
    }

    /**
     * Rétablit tous les réglages du code.
     *
     * L'ÉTAT D'AVANT EST ÉCRIT DANS LE JOURNAL, et ce n'est pas une coquetterie :
     * sans lui, on efface en un clic un travail de réglage sans qu'il reste trace de
     * ce qu'il contenait. Avec lui, on peut relire ce qui a été annulé et le refaire.
     */
    public function reinitialiser(string $motif, ?Utilisateur $auteur): void
    {
        $motif = $this->exigerUnMotif($motif);
        if ($this->reglages->estVierge()) {
            return;
        }

        $avant = $this->reglages->tout();
        $resume = sprintf(
            '%d outil(s) coupé(s), %d seuil(s) déplacé(s) : %s',
            \count($avant['outils']),
            \count($avant['parametres']),
            json_encode($avant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $singleton = $this->repository->getSingleton();
        $singleton->setKetReglages(null);
        $this->em->flush();
        $this->reglages->refresh();

        $this->journaliser(
            KetReglageJournal::TYPE_REINITIALISATION,
            'reinitialisation',
            mb_substr($resume, 0, 255),
            'valeurs du code',
            $motif,
            $auteur,
        );
    }

    /**
     * LE MOTIF EST OBLIGATOIRE — cf. KetReglageJournal. On exige aussi une phrase,
     * pas un caractère : « x » passerait une contrainte de non-vide et n'apprendrait
     * rien à celui qui relira six mois plus tard.
     */
    private function exigerUnMotif(string $motif): string
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new \DomainException(
                'Dites pourquoi vous faites ce changement (au moins quelques mots) : '
                . 'sans motif, l’historique ne permet pas de savoir s’il faut le défaire.'
            );
        }

        return mb_substr($motif, 0, 2000);
    }

    /** @return array{outils: array<string, bool>, parametres: array<string, int>} */
    private function carte(): array
    {
        return $this->reglages->tout();
    }

    /** @param array{outils: array<string, bool>, parametres: array<string, int>} $carte */
    private function enregistrer(array $carte): void
    {
        $carte = array_filter($carte, static fn (array $v): bool => $v !== []);

        $this->repository->getSingleton()->setKetReglages($carte === [] ? null : $carte);
        $this->em->flush();

        // L'unique mécanisme d'invalidation du projet : sans lui, l'écran se
        // réafficherait avec les réglages d'AVANT l'enregistrement.
        $this->reglages->refresh();
    }

    private function journaliser(
        string $type,
        string $element,
        ?string $avant,
        ?string $apres,
        string $motif,
        ?Utilisateur $auteur,
    ): void {
        $ligne = (new KetReglageJournal())
            ->setType($type)
            ->setElement($element)
            ->setAncienneValeur($avant)
            ->setNouvelleValeur($apres)
            ->setMotif($motif)
            ->setAuteur($auteur);

        if ($auteur === null) {
            $ligne->setAuteurNom('(système)');
        }

        $this->em->persist($ligne);
        $this->em->flush();
    }
}
