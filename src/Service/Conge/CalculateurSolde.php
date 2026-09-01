<?php

namespace App\Service\Conge;

use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Repository\DemandeCongeRepository;
use App\Repository\MouvementCongeRepository;

/**
 * LE COMPTEUR D'UN AGENT — un seul calcul, tous les consommateurs.
 *
 * L'écran, la fiche, la barre du tableau de bord, les e-mails, l'assistant et le
 * contrôle de solde à la soumission appellent TOUS cette classe. C'est la seule raison
 * pour laquelle le chiffre d'un mail correspond exactement à celui de l'écran : il n'y
 * en a qu'un.
 *
 * ── L'ARBITRAGE EST ICI, PAS EN SQL ─────────────────────────────────────────────────
 * Le repository rend les totaux BRUTS par nature ; c'est ici que l'on décide de ce que
 * chaque nature devient. Trancher en SQL aurait dupliqué la règle dans une requête, et
 * une requête ne se relit pas comme une règle.
 *
 * ── LES SIGNES ──────────────────────────────────────────────────────────────────────
 * Les quantités sont SIGNÉES en base : une prise est négative, une dotation positive.
 * Le « consommé » que l'on affiche est donc l'opposé du total des prises — on montre un
 * nombre de jours pris, pas une dette.
 */
class CalculateurSolde
{
    public function __construct(
        private readonly MouvementCongeRepository $mouvementRepository,
        private readonly DemandeCongeRepository $demandeRepository,
    ) {
    }

    /** Le compteur d'un agent sur un exercice. */
    public function pour(Invite $agent, ?int $exercice = null): SoldeConge
    {
        $exercice ??= (int) (new \DateTimeImmutable('now'))->format('Y');

        $totaux = $this->mouvementRepository->totauxParNature($agent, $exercice);

        $acquis = 0.0;
        foreach (MouvementConge::NATURES_ACQUISES as $nature) {
            $acquis += $totaux[$nature] ?? 0.0;
        }

        // Les prises sont stockées négatives : on rend leur valeur absolue, qui est ce
        // que l'utilisateur appelle « jours consommés ».
        $consomme = -($totaux[MouvementConge::NATURE_PRISE] ?? 0.0);

        return new SoldeConge(
            acquis: $acquis,
            dontReport: $totaux[MouvementConge::NATURE_REPORT] ?? 0.0,
            consomme: $consomme,
            engage: $this->demandeRepository->joursEngages($agent, $exercice),
            exercice: $exercice,
        );
    }

    /**
     * Compteurs de plusieurs agents, indexés par identifiant d'invité.
     *
     * @param iterable<Invite> $agents
     * @return array<int, SoldeConge>
     */
    public function pourPlusieurs(iterable $agents, ?int $exercice = null): array
    {
        $soldes = [];
        foreach ($agents as $agent) {
            $id = $agent->getId();
            if ($id !== null && !isset($soldes[$id])) {
                $soldes[$id] = $this->pour($agent, $exercice);
            }
        }

        return $soldes;
    }
}
