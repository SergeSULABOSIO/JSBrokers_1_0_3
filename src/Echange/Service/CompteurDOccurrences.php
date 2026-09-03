<?php

namespace App\Echange\Service;

use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\EchangeOccurrenceRepository;
use App\Token\InsufficientTokensException;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SOURCE UNIQUE du décompte et du prix d'une opération d'échange.
 *
 * L'écran, la route d'export et l'assistant posent tous les trois la même question —
 * « combien me reste-t-il de gratuites, et combien va me coûter la prochaine ? » — et
 * doivent recevoir le même chiffre. Recalculer ce chiffre ailleurs, ne serait-ce
 * qu'une fois, c'est se condamner à annoncer un prix et à en débiter un autre.
 *
 * DEUX RÈGLES QUI NE SE NÉGOCIENT PAS :
 *
 *  1. LE SOLDE SE CONTRÔLE AVANT. Générer un classeur de quarante feuilles pour
 *     découvrir ensuite qu'on ne peut pas le facturer serait un défaut de conception :
 *     l'utilisateur aurait attendu pour rien, et le fichier existerait sans contrepartie.
 *     D'où {@see verifierSolvabilite()}, appelée avant la première opération coûteuse.
 *
 *  2. L'OCCURRENCE ET LE DÉBIT SONT LE MÊME GESTE. Ils vivent dans la même transaction
 *     que l'opération : ou bien l'export a produit un fichier ET a été compté ET a été
 *     débité, ou bien rien de tout cela n'a eu lieu. Une occurrence sans débit fausse
 *     le quota ; un débit sans occurrence vole le cabinet.
 */
final class CompteurDOccurrences
{
    public function __construct(
        private readonly EchangeOccurrenceRepository $occurrences,
        private readonly ParametresTokenService $parametres,
        private readonly TokenAccountService $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Occurrences déjà abouties pour ce cabinet, export et import confondus. */
    public function consommees(Entreprise $entreprise): int
    {
        return $this->occurrences->compterPour($entreprise);
    }

    /** Opérations encore offertes. Jamais négatif : au-delà du quota, c'est zéro. */
    public function gratuitesRestantes(Entreprise $entreprise): int
    {
        return max(0, $this->parametres->echangeQuotaGratuit() - $this->consommees($entreprise));
    }

    /**
     * Coût en tokens de la prochaine opération de ce type.
     *
     * Un IMPORT ne coûte jamais de forfait : il écrit des enregistrements, et chacun est
     * déjà métré à son poids ordinaire par le circuit d'écriture commun. Il CONSOMME en
     * revanche une occurrence — le quota gratuit compte les deux, comme annoncé — ce qui
     * est la seule façon de rendre les trois premières opérations réellement libres,
     * quel que soit le sens dans lequel on les fait.
     */
    public function coutProchaine(Entreprise $entreprise, string $type): int
    {
        if ($type === EchangeOccurrence::TYPE_IMPORT) {
            return 0;
        }

        return $this->gratuitesRestantes($entreprise) > 0
            ? 0
            : $this->parametres->echangeCoutOccurrence();
    }

    /**
     * État de facturation à afficher en permanence — l'écran, l'assistant et le
     * bandeau lisent CETTE structure, pas leurs propres calculs.
     *
     * @return array{
     *     consommees: int, quotaGratuit: int, gratuitesRestantes: int,
     *     coutExport: int, coutImport: int, soldeDisponible: int,
     *     exportFinancable: bool, message: string
     * }
     */
    public function etat(Entreprise $entreprise): array
    {
        $consommees = $this->consommees($entreprise);
        $quota = $this->parametres->echangeQuotaGratuit();
        $restantes = max(0, $quota - $consommees);
        $coutExport = $this->coutProchaine($entreprise, EchangeOccurrence::TYPE_EXPORT);
        $solde = $this->tokens->availableFor($entreprise);

        return [
            'consommees'         => $consommees,
            'quotaGratuit'       => $quota,
            'gratuitesRestantes' => $restantes,
            'coutExport'         => $coutExport,
            'coutImport'         => 0,
            'soldeDisponible'    => $solde,
            'exportFinancable'   => $coutExport === 0 || $solde >= $coutExport,
            'message'            => $this->message($restantes, $coutExport),
        ];
    }

    /**
     * Refuse AVANT toute génération si l'opération n'est pas finançable.
     *
     * @throws InsufficientTokensException
     */
    public function verifierSolvabilite(Entreprise $entreprise, string $type): void
    {
        $cout = $this->coutProchaine($entreprise, $type);
        if ($cout === 0) {
            return;
        }

        $proprietaire = $entreprise->getUtilisateur();
        if (!$proprietaire instanceof Utilisateur) {
            return; // Pas de propriétaire identifiable : on ne facture pas, donc rien à refuser.
        }

        $disponible = $this->tokens->availableFor($entreprise);
        if ($disponible < $cout) {
            throw new InsufficientTokensException(
                required: $cout,
                available: $disponible,
                nextRenewalAt: $this->tokens->nextRenewalAt($proprietaire),
            );
        }
    }

    /**
     * Enregistre l'occurrence ET débite, en un seul geste.
     *
     * ⚠ À APPELER DANS LA TRANSACTION DE L'OPÉRATION, jamais après elle. L'appelant
     * maîtrise la transaction — même contrat que WorkspaceMutationService::executer().
     *
     * La clé d'idempotence est portée par une contrainte d'unicité en BASE : c'est elle,
     * et non la lecture préalable, qui résiste à deux requêtes concurrentes. Un rejeu
     * retrouve l'occurrence existante et ne débite pas une seconde fois.
     *
     * @param string[] $perimetre codes des ressources réellement présentes dans le fichier
     */
    public function enregistrer(
        Entreprise $entreprise,
        ?Invite $invite,
        ?Utilisateur $acteur,
        string $type,
        array $perimetre,
        int $nbLignes,
        string $cleIdempotence,
        ?string $empreinteFichier = null,
        ?string $nomFichier = null,
    ): EchangeOccurrence {
        $existante = $this->occurrences->parCleIdempotence($cleIdempotence);
        if ($existante !== null) {
            return $existante;
        }

        // Le coût se relit ICI, dans la transaction : entre l'annonce faite à
        // l'utilisateur et sa confirmation, une autre opération a pu consommer la
        // dernière gratuite. On facture ce qui est vrai au moment d'écrire.
        //
        // Le débit passe par le service de tokens, jamais par un consume() direct :
        // lui seul sait vérifier la solvabilité, puiser le prépayé avant le gratuit et
        // écrire la ligne de journal. Le refaire ici produirait une consommation
        // invisible du relevé.
        $cout = $this->coutProchaine($entreprise, $type);
        $this->tokens->meterEchange($entreprise, $acteur, $cout);

        $occurrence = (new EchangeOccurrence())
            ->setType($type)
            ->setPerimetre($perimetre)
            ->setNbLignes($nbLignes)
            ->setTokensDebites($cout)
            ->setCleIdempotence($cleIdempotence)
            ->setEmpreinteFichier($empreinteFichier)
            ->setNomFichier($nomFichier);
        $occurrence->setEntreprise($entreprise);
        $occurrence->setInvite($invite);

        $this->em->persist($occurrence);

        return $occurrence;
    }

    /**
     * Clé d'idempotence d'une opération.
     *
     * Volontairement grossière dans le temps (la minute) : elle vise le rejeu — double
     * clic, requête relancée par le navigateur, retry réseau — et non deux exports
     * délibérés du même périmètre à dix minutes d'intervalle, qui sont bien deux
     * opérations et doivent être comptés comme telles.
     *
     * @param string[] $perimetre
     */
    public function cleIdempotence(Entreprise $entreprise, ?Invite $invite, string $type, array $perimetre, ?string $graine = null): string
    {
        sort($perimetre);

        return hash('sha256', implode('|', [
            $entreprise->getId(),
            $invite?->getId() ?? 0,
            $type,
            implode(',', $perimetre),
            $graine ?? (new \DateTimeImmutable('now'))->format('Y-m-d H:i'),
        ]));
    }

    private function message(int $restantes, int $coutExport): string
    {
        if ($restantes > 0) {
            return sprintf(
                '%d opération%s gratuite%s restante%s.',
                $restantes,
                $restantes > 1 ? 's' : '',
                $restantes > 1 ? 's' : '',
                $restantes > 1 ? 's' : '',
            );
        }

        return sprintf('Cette exportation sera facturée %d tokens. L\'importation reste sans forfait.', $coutExport);
    }
}
