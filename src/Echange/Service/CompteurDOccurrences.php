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
        private readonly FranchiseDeReprise $franchise,
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
     * Coût en tokens de la prochaine opération de ce type — TOUJOURS ZÉRO.
     *
     * ⚠ AUCUNE OPÉRATION D'ÉCHANGE NE PORTE PLUS DE FORFAIT, et les deux sens ont leur
     * raison propre :
     *
     *  - **L'EXPORTATION EST GRATUITE ET ILLIMITÉE.** Ce que le cabinet SORT de la
     *    plateforme ne lui coûte rien : c'est la contrepartie de la réversibilité, et
     *    c'est annoncé comme tel sur le site public. Facturer la sortie de ses propres
     *    données reviendrait à les retenir en otage.
     *
     *  - **L'IMPORTATION NE PORTE PAS DE FORFAIT NON PLUS**, mais pour l'autre motif : elle
     *    écrit des enregistrements, et chacun est déjà métré à son poids ordinaire par le
     *    circuit d'écriture commun. Lui ajouter un forfait le ferait payer deux fois pour
     *    un seul geste. Ce qui la borne, c'est sa FRANCHISE en lignes — voir
     *    {@see FranchiseDeReprise} —, laquelle exonère ce métrage au lieu de s'y ajouter.
     *
     * La méthode subsiste parce que ses appelants raisonnent en coût, et parce que le jour
     * où une opération d'échange redeviendrait payante, c'est ici qu'on le dirait.
     */
    public function coutProchaine(Entreprise $entreprise, string $type): int
    {
        return 0;
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
        $solde = $this->tokens->availableFor($entreprise);

        // La franchise de REPRISE — en lignes, à vie, par cabinet. C'est elle qui borne
        // désormais la gratuité ; le quota d'opérations ne borne plus rien.
        $lignesOffertes = $this->franchise->plafond();
        $lignesConsommees = $this->franchise->dejaConsommees($entreprise);
        $lignesRestantes = max(0, $lignesOffertes - $lignesConsommees);

        return [
            'consommees'         => $consommees,
            'quotaGratuit'       => $quota,
            // ⚠ CONSERVÉES POUR LA FORME, ET FIGÉES. Cinq consommateurs lisent cette
            // structure — l'écran, deux outils de l'assistant, les tests. Les retirer
            // d'un coup casserait tout pour un gain cosmétique ; les figer dit la vérité
            // du nouveau modèle : plus rien n'est facturé à l'opération.
            'gratuitesRestantes' => max(0, $quota - $consommees),
            'coutExport'         => 0,
            'coutImport'         => 0,
            'exportFinancable'   => true,

            'lignesOffertes'     => $lignesOffertes,
            'lignesConsommees'   => $lignesConsommees,
            'lignesRestantes'    => $lignesRestantes,

            'soldeDisponible'    => $solde,
            'message'            => $this->message($lignesRestantes, $lignesOffertes),
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

    /**
     * Ce que l'écran annonce en permanence, dans les mots du métier.
     *
     * ⚠ ON PARLE DE LIGNES, PAS D'OPÉRATIONS NI DE TOKENS. C'est ce que l'utilisateur voit
     * dans son fichier, et donc la seule unité qu'il peut vérifier lui-même. Lui annoncer
     * un solde de tokens le laisserait sans moyen de savoir ce qu'il peut encore reprendre.
     */
    private function message(int $lignesRestantes, int $lignesOffertes): string
    {
        if ($lignesRestantes > 0) {
            return sprintf(
                'L\'exportation est gratuite et illimitée. Il vous reste %s ligne%s de reprise offerte%s sur %s.',
                number_format($lignesRestantes, 0, ',', ' '),
                $lignesRestantes > 1 ? 's' : '',
                $lignesRestantes > 1 ? 's' : '',
                number_format($lignesOffertes, 0, ',', ' '),
            );
        }

        return sprintf(
            'L\'exportation est gratuite et illimitée. Vos %s lignes de reprise offertes sont épuisées : '
            . 'les suivantes sont facturées au tarif d\'écriture, et le coût vous est annoncé avant confirmation.',
            number_format($lignesOffertes, 0, ',', ' '),
        );
    }
}
