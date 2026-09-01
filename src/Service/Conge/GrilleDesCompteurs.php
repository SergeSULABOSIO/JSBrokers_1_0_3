<?php

namespace App\Service\Conge;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\InviteRepository;

/**
 * LA GRILLE DES COMPTEURS — agents × exercice.
 *
 * ── LA QUESTION À LAQUELLE ELLE RÉPOND ──────────────────────────────────────────────
 * « Où en est chacun ? » Un valideur qui doit arbitrer une fin d'année a besoin de voir
 * les soldes les uns SOUS les autres : celui qui n'a rien pris, celui qui déborde, celui
 * dont le report enfle. Une fiche à la fois ne le lui dit pas.
 *
 * ── UN SEUL CALCUL, CELUI DE PARTOUT ────────────────────────────────────────────────
 * Chaque ligne vient de CalculateurSolde — le même service que la liste, la fiche, le
 * picker de décision et les e-mails. Une grille qui referait la somme finirait par
 * annoncer un total que la fiche du collaborateur contredit, et personne ne saurait
 * lequel croire.
 *
 * ── ELLE MONTRE TOUT LE MONDE, MÊME À ZÉRO ──────────────────────────────────────────
 * Contrairement au calendrier, qui ne liste que les absents : ici, un collaborateur sans
 * aucun mouvement est une information — c'est peut-être quelqu'un qu'on a oublié de doter.
 */
class GrilleDesCompteurs
{
    public function __construct(
        private readonly InviteRepository $inviteRepository,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly ParametresDuCabinet $parametres,
    ) {
    }

    /**
     * Les compteurs de tous les collaborateurs du cabinet sur un exercice.
     *
     * @return array{
     *     exercice: int,
     *     precedent: int,
     *     suivant: int,
     *     seuilReport: float,
     *     lignes: array<int, array{
     *         id: int, agent: string, acquis: float, dontReport: float,
     *         consomme: float, engage: float, disponible: float, alerte: bool
     *     }>,
     *     totaux: array{acquis: float, consomme: float, engage: float, disponible: float}
     * }
     */
    public function pour(Entreprise $entreprise, ?int $exercice = null): array
    {
        $exercice ??= (int) (new \DateTimeImmutable('now'))->format('Y');
        $seuil = $this->parametres->seuilDAlerteEnJours($entreprise);

        $lignes = [];
        $totaux = ['acquis' => 0.0, 'consomme' => 0.0, 'engage' => 0.0, 'disponible' => 0.0];

        foreach ($this->inviteRepository->findBy(['entreprise' => $entreprise]) as $agent) {
            $id = $agent->getId();
            if ($id === null) {
                continue;
            }

            $solde = $this->calculateurSolde->pour($agent, $exercice);
            $disponible = $solde->disponible();

            $lignes[] = [
                'id' => $id,
                'agent' => (string) ($agent->getNom() ?? 'Collaborateur'),
                'acquis' => $solde->acquis,
                'dontReport' => $solde->dontReport,
                'consomme' => $solde->consomme,
                'engage' => $solde->engage,
                'disponible' => $disponible,
                // Le report est sans limite de durée : au-delà du seuil, c'est une dette
                // qui grossit sans que personne ne la regarde.
                'alerte' => $seuil > 0.0 && $disponible > $seuil,
            ];

            $totaux['acquis'] += $solde->acquis;
            $totaux['consomme'] += $solde->consomme;
            $totaux['engage'] += $solde->engage;
            $totaux['disponible'] += $disponible;
        }

        // Par nom : sur une grille qu'on relit chaque mois, un ordre stable évite de
        // rechercher la même personne à un endroit différent à chaque ouverture.
        usort($lignes, static fn (array $a, array $b) => strcasecmp($a['agent'], $b['agent']));

        return [
            'exercice' => $exercice,
            'precedent' => $exercice - 1,
            'suivant' => $exercice + 1,
            'seuilReport' => $seuil,
            'lignes' => $lignes,
            'totaux' => $totaux,
        ];
    }

    /**
     * Un agent du cabinet, ou null s'il n'en fait pas partie.
     *
     * Le scoping n'est pas une formalité : un identifiant venu d'une URL ne doit jamais
     * traverser les cabinets, et c'est par ici que passent l'ajustement et le décompte
     * de sortie — deux gestes qui écrivent.
     */
    public function agentDuCabinet(Entreprise $entreprise, int $idAgent): ?Invite
    {
        $agent = $this->inviteRepository->find($idAgent);

        return $agent !== null && $agent->getEntreprise()?->getId() === $entreprise->getId()
            ? $agent
            : null;
    }
}
