<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\TypeAbsence;
use App\Repository\MouvementCongeRepository;
use App\Repository\TypeAbsenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES ÉCRITURES DU COMPTEUR QUI NE VIENNENT PAS D'UNE DEMANDE.
 *
 * Ajustement manuel, régularisation de sortie, dotation et report d'ouverture : quatre
 * gestes qui touchent le solde sans qu'aucun congé n'ait été posé. Ils vivent ici et
 * nulle part ailleurs, comme les mouvements de demande vivent dans DemandeCongeWorkflow.
 *
 * ── UN MOTIF, TOUJOURS ──────────────────────────────────────────────────────────────
 * Un ajustement sans motif est un chiffre qui apparaît dans un journal, que personne ne
 * saura expliquer six mois plus tard — et qui fera douter de tout le reste. Le motif est
 * donc exigé ici, pas seulement demandé par le formulaire.
 *
 * ── UN MOUVEMENT EST IMMUABLE ───────────────────────────────────────────────────────
 * Corriger un ajustement, c'est en écrire un autre, en sens inverse et motivé. Aucune
 * méthode de ce service ne modifie ni ne supprime une ligne existante.
 *
 * ── ON NE FLUSHE JAMAIS ICI ─────────────────────────────────────────────────────────
 * L'appelant maîtrise sa transaction, comme pour le workflow.
 */
class MouvementDuCompteur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MouvementCongeRepository $mouvementRepository,
        private readonly TypeAbsenceRepository $typeAbsenceRepository,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly ParametresDuCabinet $parametres,
    ) {
    }

    /**
     * Un ajustement manuel, motivé, sur le compteur d'un agent.
     *
     * @param float $quantite signée : négative pour retirer des jours, positive pour en
     *                        ajouter. Zéro est refusé — une ligne à zéro n'apprend rien.
     *
     * @throws CongeTransitionException
     */
    public function ajuster(
        Invite $agent,
        int $exercice,
        float $quantite,
        string $motif,
        Invite $acteur,
        string $origine = DemandeConge::ORIGINE_UI,
    ): MouvementConge {
        $violations = $this->verifierAjustement($quantite, $motif);
        if ($violations !== []) {
            throw new CongeTransitionException($violations);
        }

        return $this->ecrire(
            $agent,
            $exercice,
            MouvementConge::NATURE_AJUSTEMENT,
            $quantite,
            trim($motif),
            $acteur,
            $origine,
        );
    }

    /**
     * Ce qui empêcherait cet ajustement. Rendu séparément pour que l'écran et l'assistant
     * puissent le demander sans rien écrire.
     *
     * @return string[]
     */
    public function verifierAjustement(float $quantite, string $motif): array
    {
        $violations = [];

        if (abs($quantite) < 0.001) {
            $violations[] = "Un ajustement de zéro jour n'a aucun effet : indiquez un nombre de jours à ajouter ou à retirer.";
        }
        if (trim($motif) === '') {
            $violations[] = "Le motif est obligatoire : un chiffre sans explication rend tout le journal douteux.";
        }

        return $violations;
    }

    /**
     * LE DÉCOMPTE DE SORTIE d'un collaborateur qui quitte le cabinet.
     *
     * ── CE QU'IL CALCULE ────────────────────────────────────────────────────────────
     * La dotation de l'exercice est ramenée au prorata des mois de PRÉSENCE réelle. Ce
     * qui a été crédité au-delà est repris ; ce qui manque est ajouté. Le résultat est un
     * solde final : positif, le cabinet doit des jours ; négatif, le collaborateur en a
     * pris plus qu'il n'en avait acquis.
     *
     * ── IL NE SUPPRIME RIEN ─────────────────────────────────────────────────────────
     * La régularisation est un mouvement de plus, pas une réécriture de la dotation. Le
     * journal doit continuer à montrer ce qui avait été accordé, puis ce qui a été repris.
     *
     * @return array{
     *     mouvement: ?MouvementConge, dotationInitiale: float, dotationProratisee: float,
     *     regularisation: float, soldeAvant: float, soldeFinal: float
     * }
     */
    public function regulariserLaSortie(
        Invite $agent,
        \DateTimeInterface $dateFinContrat,
        Invite $acteur,
        string $origine = DemandeConge::ORIGINE_UI,
        bool $ecrire = true,
    ): array {
        $exercice = (int) $dateFinContrat->format('Y');
        $entreprise = $agent->getEntreprise();

        $soldeAvant = $this->calculateurSolde->pour($agent, $exercice);

        $dotationAnnuelle = $entreprise === null
            ? CongeParametres::DOTATION_ANNUELLE_DEFAUT
            : $this->parametres->dotationAnnuelle($entreprise);

        // La dotation DÉJÀ CRÉDITÉE sur cet exercice — c'est elle qu'on ramène au prorata,
        // pas le forfait théorique : un agent entré en cours d'année n'a jamais eu l'année
        // entière, et la reprendre entièrement le pénaliserait deux fois.
        $creditee = $this->dotationCreditee($agent, $exercice);

        $proratisee = CongeParametres::dotationAuProrataDeSortie(
            $dotationAnnuelle,
            $agent->getCreatedAt(),
            $dateFinContrat,
            $exercice,
        );

        $regularisation = $proratisee - $creditee;

        $mouvement = null;
        if ($ecrire && abs($regularisation) >= 0.001) {
            $mouvement = $this->ecrire(
                $agent,
                $exercice,
                MouvementConge::NATURE_REGULARISATION_SORTIE,
                $regularisation,
                sprintf(
                    'Départ au %s : dotation ramenée au prorata des mois de présence (%s j au lieu de %s j).',
                    $dateFinContrat->format('d/m/Y'),
                    $this->formater($proratisee),
                    $this->formater($creditee),
                ),
                $acteur,
                $origine,
            );
        }

        return [
            'mouvement' => $mouvement,
            'dotationInitiale' => $creditee,
            'dotationProratisee' => $proratisee,
            'regularisation' => $regularisation,
            'soldeAvant' => $soldeAvant->disponible(),
            'soldeFinal' => $soldeAvant->disponible() + $regularisation,
        ];
    }

    /** La dotation déjà créditée à cet agent sur cet exercice. */
    private function dotationCreditee(Invite $agent, int $exercice): float
    {
        $totaux = $this->mouvementRepository->totauxParNature($agent, $exercice);

        return $totaux[MouvementConge::NATURE_DOTATION] ?? 0.0;
    }

    /**
     * Écrit un mouvement, rattaché au congé annuel du cabinet.
     *
     * LE TYPE COMPTE : un mouvement sans type d'absence crédite un solde que le calcul
     * lit quand même — mais le journal, lui, devient illisible. On rattache donc au
     * congé annuel, le seul type que la dotation alimente.
     */
    private function ecrire(
        Invite $agent,
        int $exercice,
        string $nature,
        float $quantite,
        string $commentaire,
        Invite $acteur,
        string $origine,
    ): MouvementConge {
        $entreprise = $agent->getEntreprise();

        $mouvement = new MouvementConge();
        $mouvement->setAgent($agent);
        $mouvement->setExercice($exercice);
        $mouvement->setTypeAbsence($this->congeAnnuel($agent));
        $mouvement->setNature($nature);
        $mouvement->setQuantite(number_format($quantite, 1, '.', ''));
        $mouvement->setAuteur($acteur);
        $mouvement->setOrigine($origine);
        $mouvement->setCommentaire($commentaire);
        $mouvement->setEntreprise($entreprise);
        $mouvement->setInvite($acteur);

        $this->em->persist($mouvement);

        return $mouvement;
    }

    private function congeAnnuel(Invite $agent): ?TypeAbsence
    {
        $entreprise = $agent->getEntreprise();

        return $entreprise === null
            ? null
            : $this->typeAbsenceRepository->parCode($entreprise, TypeAbsence::CODE_CONGE_ANNUEL);
    }

    private function formater(float $jours): string
    {
        return rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ',');
    }
}
