<?php

namespace App\Ai\Boussole;

use App\Comptabilite\SuiviFiscalService;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;

/**
 * LA BOUSSOLE du courtier, consolidée pour l'assistant IA.
 *
 * Produit un instantané COMPACT de la chaîne de valeur, dans le périmètre
 * (portefeuille) de l'invité, injecté dans le contexte système de Ket à CHAQUE
 * message : saturation/cross-selling, renouvellements à anticiper, primes
 * exigibles impayées, commissions à recouvrer auprès des assureurs, rétros à
 * reverser, obligation fiscale, bordereaux de fin de mois, tâches à clôturer.
 * Ket s'en sert pour rappeler et guider vers l'étape la plus prioritaire.
 *
 * FAIL-CLOSED : un axe n'apparaît que si l'invité a le droit de lire l'entité
 * sous-jacente. FAIL-SAFE : ce service tourne sur le chemin chaud du chat ; toute
 * exception d'un axe est avalée (l'axe disparaît, la conversation ne casse jamais).
 * Il ne restitue que des COMPTES — le DÉTAIL chiffré relève des outils dédiés.
 */
final class BoussoleService
{
    /** Barème d'urgence des axes (plus haut = plus prioritaire pour le rappel). */
    private const URGENCE = [
        'fiscal'            => 90,
        'retros'           => 85,
        'renouvellements'  => 80, // échus ; 60 si seulement imminents (cf. axeRenouvellements)
        'commissions'      => 70,
        'primes_impayees'  => 65,
        'bordereaux'       => 55,
        'saturation'       => 50,
        'taches'           => 40,
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly SaturationService $saturation,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly SuiviFiscalService $suiviFiscal,
        private readonly JSBDynamicSearchService $searchService,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
    ) {
    }

    /**
     * Instantané de la boussole pour l'invité. `prioritaire` = l'axe actionnable
     * le plus urgent (ou null si tout est au vert), sur lequel Ket appuie son rappel.
     *
     * @return array{items: array<int, array>, prioritaire: ?array}
     */
    public function etat(Entreprise $entreprise, Invite $invite): array
    {
        $items = array_values(array_filter([
            $this->axe($invite, 'Client', fn (): array => $this->axeSaturation($entreprise, $invite)),
            $this->axe($invite, 'Avenant', fn (): array => $this->axeRenouvellements($entreprise, $invite)),
            $this->axe($invite, 'Tranche', fn (): array => $this->axePrimesImpayees($entreprise, $invite)),
            $this->axe($invite, 'Tranche', fn (): array => $this->axeCommissions($entreprise, $invite)),
            $this->axe($invite, 'Tranche', fn (): array => $this->axeRetros($entreprise, $invite)),
            $this->axe($invite, 'Bordereau', fn (): array => $this->axeBordereaux()),
            $this->axe($invite, 'DocumentComptable', fn (): array => $this->axeFiscal()),
            $this->axe($invite, 'Tache', fn (): array => $this->axeTaches($entreprise, $invite)),
        ]));

        $prioritaire = null;
        foreach ($items as $item) {
            if (!empty($item['actionnable']) && ($prioritaire === null || $item['urgence'] > $prioritaire['urgence'])) {
                $prioritaire = $item;
            }
        }

        return ['items' => $items, 'prioritaire' => $prioritaire];
    }

    /**
     * Enveloppe fail-closed (canRead) + fail-safe (exceptions avalées) d'un axe :
     * le chat ne doit JAMAIS tomber à cause de la boussole.
     */
    private function axe(Invite $invite, string $entite, callable $calcul): ?array
    {
        if (!$this->accessResolver->canRead($invite, $entite)) {
            return null;
        }
        try {
            return $calcul();
        } catch (\Throwable) {
            return null;
        }
    }

    private function axeSaturation(Entreprise $entreprise, Invite $invite): array
    {
        $agg         = $this->saturation->couverturePortefeuille($entreprise, $invite, false);
        $sous        = $agg['nbClientsSousSatures'];
        $actionnable = $sous > 0 && $agg['nbCatalogue'] > 0;

        return [
            'axe'         => 'saturation',
            'libelle'     => $actionnable
                ? sprintf('%d client(s) sous 100 %% de couverture (taux moyen %s %%)', $sous, $agg['tauxMoyen'])
                : 'Portefeuille pleinement saturé',
            'compte'      => $sous,
            'urgence'     => $actionnable ? self::URGENCE['saturation'] : 0,
            'actionnable' => $actionnable,
            'opportunite' => $agg['topOpportunites'][0]['risque'] ?? null,
        ];
    }

    private function axeRenouvellements(Entreprise $entreprise, Invite $invite): array
    {
        $echus     = $this->compterAvenants($entreprise, $invite, AvenantEcheanceScope::STATUT_ECHUS);
        $imminents = $this->compterAvenants($entreprise, $invite, AvenantEcheanceScope::STATUT_30J);
        $total     = $echus + $imminents;

        return [
            'axe'         => 'renouvellements',
            'libelle'     => $total > 0
                ? sprintf('%d renouvellement(s) à anticiper (%d échu(s), %d sous 30 j)', $total, $echus, $imminents)
                : 'Aucun renouvellement imminent',
            'compte'      => $total,
            'urgence'     => $echus > 0 ? self::URGENCE['renouvellements'] : ($imminents > 0 ? 60 : 0),
            'actionnable' => $total > 0,
        ];
    }

    private function axePrimesImpayees(Entreprise $entreprise, Invite $invite): array
    {
        $r  = $this->tranchePaiement->lister($entreprise, TranchePaiementScope::STATUT_IMPAYEES, null, null, 1, 1, $invite);
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'primes_impayees',
            'libelle'     => $nb > 0 ? sprintf('%d prime(s) exigible(s) impayée(s)', $nb) : 'Aucune prime impayée',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalSoldePrime'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['primes_impayees'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    private function axeCommissions(Entreprise $entreprise, Invite $invite): array
    {
        $r  = $this->tranchePaiement->lister($entreprise, TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE, null, null, 1, 1, $invite);
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'commissions',
            'libelle'     => $nb > 0
                ? sprintf('%d commission(s) exigible(s) à recouvrer auprès des assureurs', $nb)
                : 'Aucune commission à recouvrer',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalSoldeCommission'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['commissions'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    private function axeRetros(Entreprise $entreprise, Invite $invite): array
    {
        $r  = $this->tranchePaiement->lister($entreprise, TranchePaiementScope::STATUT_RETRO_A_PAYER, null, null, 1, 1, $invite);
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'retros',
            'libelle'     => $nb > 0
                ? sprintf('%d rétrocommission(s) à reverser aux partenaires', $nb)
                : 'Aucune rétrocommission à reverser',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalRetroExigible'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['retros'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    private function axeBordereaux(): array
    {
        // Rappel TEMPOREL : les bordereaux de production sont fournis par les assureurs
        // à la clôture mensuelle — c'est en fin / début de mois qu'il faut les demander,
        // les analyser et facturer les commissions en lot (note de débit).
        $jour       = (int) (new \DateTimeImmutable('now'))->format('j');
        $finDeMois  = $jour >= 25 || $jour <= 5;

        return [
            'axe'         => 'bordereaux',
            'libelle'     => $finDeMois
                ? 'Fin de mois : demander les bordereaux de production aux assureurs et facturer les commissions en lot'
                : 'Bordereaux : rien d’urgent hors clôture mensuelle',
            'compte'      => 0,
            'urgence'     => $finDeMois ? self::URGENCE['bordereaux'] : 0,
            'actionnable' => $finDeMois,
        ];
    }

    private function axeFiscal(): array
    {
        // Obligation FISCALE du cabinet (non scopée au portefeuille) : réservée aux
        // invités habilités « Documents comptables » (gating canRead ci-dessus).
        $annee = (int) date('Y');
        $solde = (float) ($this->suiviFiscal->suivi($annee)['totaux']['solde'] ?? 0);
        $du    = $solde > 0.005;

        return [
            'axe'         => 'fiscal',
            'libelle'     => $du
                ? sprintf('TVA à reverser à l’administration (solde dû, exercice %d)', $annee)
                : 'TVA à jour',
            'compte'      => $du ? 1 : 0,
            'montant'     => $solde,
            'urgence'     => $du ? self::URGENCE['fiscal'] : 0,
            'actionnable' => $du,
        ];
    }

    private function axeTaches(Entreprise $entreprise, Invite $invite): array
    {
        $criteria = ['closed' => ['operator' => '=', 'value' => false]]
            + $this->portefeuilleCritere->pour('Tache', $invite);
        $result = $this->searchService->search(Tache::class, $criteria, $entreprise, null, 1, 1);
        $nb     = ($result['status']['code'] ?? 500) === 200 ? (int) ($result['totalItems'] ?? 0) : 0;

        return [
            'axe'         => 'taches',
            'libelle'     => $nb > 0
                ? sprintf('%d tâche(s) ouverte(s) : suivre les feedbacks puis clôturer', $nb)
                : 'Aucune tâche ouverte',
            'compte'      => $nb,
            'urgence'     => $nb > 0 ? self::URGENCE['taches'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    /** Compte des avenants d'une fenêtre d'échéance dans le périmètre de l'invité. */
    private function compterAvenants(Entreprise $entreprise, Invite $invite, string $statutEcheance): int
    {
        $criteria = AvenantEcheanceScope::critereRecherche('Avenant', $statutEcheance)
            + $this->portefeuilleCritere->pour('Avenant', $invite);
        $result = $this->searchService->search(Avenant::class, $criteria, $entreprise, null, 1, 1);

        return ($result['status']['code'] ?? 500) === 200 ? (int) ($result['totalItems'] ?? 0) : 0;
    }
}
