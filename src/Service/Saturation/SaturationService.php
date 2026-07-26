<?php

namespace App\Service\Saturation;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Repository\RisqueRepository;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;

/**
 * Source unique du CROSS-SELLING / de la SATURATION du portefeuille.
 *
 * Objectif métier du courtier (« la boussole ») : chaque client souscrit tous
 * les types de risques du catalogue (saturation à 100 %). Ce service mesure la
 * couverture d'un client et agrège celle d'un portefeuille, et alimente aussi
 * les opportunités de cross-selling du SOA (dont il tient la logique — extraite
 * de SoaContextBuilder pour être partagée avec l'assistant IA, sans duplication).
 *
 * RÈGLE DE COUVERTURE : un risque est COUVERT dès qu'il porte une police valide
 * (un avenant dont le statut de renouvellement n'est ni PERDU ni RÉSILIÉ), même
 * définition que le SOA et les indicateurs client.
 */
class SaturationService
{
    /** Taille de page pour balayer les clients d'un périmètre (les compter tous, pas les afficher). */
    private const PAGE = 100;

    /** Nombre d'opportunités de tête restituées pour un portefeuille. */
    private const TOP_OPPORTUNITES = 3;

    public function __construct(
        private readonly RisqueRepository $risqueRepository,
        private readonly JSBDynamicSearchService $searchService,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
    ) {
    }

    /**
     * Opportunités de cross-selling avec un client — SORTIE STRICTEMENT IDENTIQUE
     * à l'ancienne SoaContextBuilder::buildCrossSellingOpportunites (non-régression) :
     * - 'nouveaux'  : risques du catalogue jamais abordés (aucune piste) ;
     * - 'aRelancer' : risques abordés sans suite (pistes fermées, polices perdues/résiliées).
     *
     * @return array{nouveaux: Risque[], aRelancer: array<int, array{risque: Risque, motif: string, piste: ?\App\Entity\Piste}>}
     */
    public function opportunites(Client $client, ?Entreprise $entreprise): array
    {
        if ($entreprise === null) {
            return ['nouveaux' => [], 'aRelancer' => []];
        }

        return $this->opportunitesDepuisCatalogue(
            $client,
            $this->risqueRepository->findCatalogueForEntreprise($entreprise),
        );
    }

    /**
     * Couverture d'UN client : taux de saturation (en points de %) + opportunités.
     *
     * @return array{nbCatalogue: int, nbCouverts: int, tauxCouverture: float, nouveaux: Risque[], aRelancer: array}
     */
    public function couvertureClient(Client $client, ?Entreprise $entreprise): array
    {
        $vide = ['nbCatalogue' => 0, 'nbCouverts' => 0, 'tauxCouverture' => 0.0, 'nouveaux' => [], 'aRelancer' => []];
        if ($entreprise === null) {
            return $vide;
        }

        $catalogue = $this->risqueRepository->findCatalogueForEntreprise($entreprise);
        if ($catalogue === []) {
            return $vide;
        }

        $catalogueIds = [];
        foreach ($catalogue as $risque) {
            $catalogueIds[$risque->getId()] = true;
        }

        $nbCatalogue = \count($catalogue);
        $nbCouverts  = \count($this->risquesCouverts($client, $catalogueIds));

        return [
            'nbCatalogue'    => $nbCatalogue,
            'nbCouverts'     => $nbCouverts,
            'tauxCouverture' => round($nbCouverts / $nbCatalogue * 100, 1),
        ] + $this->opportunitesDepuisCatalogue($client, $catalogue);
    }

    /**
     * Couverture agrégée d'un PORTEFEUILLE (par défaut celui de l'invité, comme la
     * rubrique à l'écran ; entreprise entière sur demande) : taux moyen, nombre de
     * clients pleinement saturés et non saturés, et les risques manquants chez le
     * plus de clients (opportunités de tête).
     *
     * @return array{perimetre: string, nbClients: int, nbCatalogue: int, tauxMoyen: float, nbClientsSatures: int, nbClientsSousSatures: int, topOpportunites: array<int, array{risque: string, nbClients: int}>}
     */
    public function couverturePortefeuille(Entreprise $entreprise, ?Invite $invite, bool $perimetreEntreprise = false): array
    {
        $catalogue   = $this->risqueRepository->findCatalogueForEntreprise($entreprise);
        $nbCatalogue = \count($catalogue);

        $criterePortefeuille = ($perimetreEntreprise || $invite === null)
            ? []
            : $this->portefeuilleCritere->pour('Client', $invite);

        $clients   = $this->clientsDuPerimetre($entreprise, $criterePortefeuille);
        $nbClients = \count($clients);

        $sommeTaux         = 0.0;
        $nbClientsSatures  = 0;
        $manquantsParRisque = []; // risqueId => ['risque' => Risque, 'nb' => int]

        foreach ($clients as $client) {
            $couverture = $this->couvertureClient($client, $entreprise);
            $sommeTaux += $couverture['tauxCouverture'];
            if ($nbCatalogue > 0 && $couverture['nbCouverts'] >= $nbCatalogue) {
                ++$nbClientsSatures;
            }
            foreach ($couverture['nouveaux'] as $risque) {
                $id = $risque->getId();
                $manquantsParRisque[$id]['risque'] = $risque;
                $manquantsParRisque[$id]['nb']     = ($manquantsParRisque[$id]['nb'] ?? 0) + 1;
            }
        }

        usort($manquantsParRisque, static fn (array $a, array $b): int => $b['nb'] <=> $a['nb']);
        $topOpportunites = array_map(
            static fn (array $m): array => ['risque' => (string) $m['risque']->getNomComplet(), 'nbClients' => $m['nb']],
            \array_slice($manquantsParRisque, 0, self::TOP_OPPORTUNITES),
        );

        return [
            'perimetre'            => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'nbClients'            => $nbClients,
            'nbCatalogue'          => $nbCatalogue,
            'tauxMoyen'            => $nbClients > 0 ? round($sommeTaux / $nbClients, 1) : 0.0,
            'nbClientsSatures'     => $nbClientsSatures,
            'nbClientsSousSatures' => $nbClients - $nbClientsSatures,
            'topOpportunites'      => $topOpportunites,
        ];
    }

    /**
     * Ensemble des risques du catalogue effectivement COUVERTS par le client
     * (au moins une police valide). Indexé par id de risque.
     *
     * @param array<int, true> $catalogueIds garde-fou d'isolation par entreprise
     * @return array<int, true>
     */
    private function risquesCouverts(Client $client, array $catalogueIds): array
    {
        $couverts = [];
        foreach ($client->getPistes() as $piste) {
            $risque = $piste->getRisque();
            if ($risque === null || !isset($catalogueIds[$risque->getId()])) {
                continue;
            }
            foreach ($piste->getCotations() as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    if (!\in_array($avenant->getRenewalStatus(), [Avenant::RENEWAL_STATUS_LOST, Avenant::RENEWAL_STATUS_CANCELLED], true)) {
                        $couverts[$risque->getId()] = true;
                        continue 3; // risque couvert : passer à la piste suivante
                    }
                }
            }
        }

        return $couverts;
    }

    /**
     * Cœur historique du cross-selling (repris tel quel de SoaContextBuilder pour
     * garantir la non-régression du SOA). Statut par risque du catalogue :
     * 'actif' (couvert ou en négociation) > 'policePerdue' > 'pisteFermee'.
     *
     * @param Risque[] $catalogue
     * @return array{nouveaux: Risque[], aRelancer: array}
     */
    private function opportunitesDepuisCatalogue(Client $client, array $catalogue): array
    {
        if ($catalogue === []) {
            return ['nouveaux' => [], 'aRelancer' => []];
        }

        $catalogueIds = [];
        foreach ($catalogue as $risqueCatalogue) {
            $catalogueIds[$risqueCatalogue->getId()] = true;
        }

        $statuts             = [];
        $dernierePisteFermee = [];
        foreach ($client->getPistes() as $piste) {
            $risque = $piste->getRisque();
            if ($risque === null || !isset($catalogueIds[$risque->getId()])) {
                continue;
            }
            $risqueId = $risque->getId();

            $aUnAvenant    = false;
            $aPoliceValide = false;
            foreach ($piste->getCotations() as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    $aUnAvenant = true;
                    if (!\in_array($avenant->getRenewalStatus(), [Avenant::RENEWAL_STATUS_LOST, Avenant::RENEWAL_STATUS_CANCELLED], true)) {
                        $aPoliceValide = true;
                        break 2;
                    }
                }
            }

            if (!$piste->isClosed() || $aPoliceValide) {
                $statuts[$risqueId] = 'actif';
            } elseif (($statuts[$risqueId] ?? null) !== 'actif') {
                $statuts[$risqueId] = $aUnAvenant ? 'policePerdue' : 'pisteFermee';
                $existante = $dernierePisteFermee[$risqueId] ?? null;
                if ($existante === null || $piste->getId() > $existante->getId()) {
                    $dernierePisteFermee[$risqueId] = $piste;
                }
            }
        }

        $nouveaux  = [];
        $aRelancer = [];
        foreach ($catalogue as $risque) {
            $statut = $statuts[$risque->getId()] ?? null;
            if ($statut === null) {
                $nouveaux[] = $risque;
            } elseif ($statut !== 'actif') {
                $aRelancer[] = [
                    'risque' => $risque,
                    'motif'  => $statut === 'policePerdue' ? 'Police perdue ou résiliée' : 'Piste(s) fermée(s) sans souscription',
                    'piste'  => $dernierePisteFermee[$risque->getId()] ?? null,
                ];
            }
        }

        return ['nouveaux' => $nouveaux, 'aRelancer' => $aRelancer];
    }

    /**
     * Tous les clients du périmètre (balayage paginé — on les COMPTE tous, la
     * restitution reste compacte). Scoping entreprise garanti par le service de
     * recherche ; critère portefeuille appliqué en amont.
     *
     * @param array<string, mixed> $criterePortefeuille
     * @return Client[]
     */
    private function clientsDuPerimetre(Entreprise $entreprise, array $criterePortefeuille): array
    {
        $clients = [];
        $page    = 1;
        do {
            $result = $this->searchService->search(Client::class, $criterePortefeuille, $entreprise, null, $page, self::PAGE);
            if (($result['status']['code'] ?? 500) !== 200) {
                break;
            }
            foreach ($result['data'] as $client) {
                $clients[] = $client;
            }
            $totalPages = (int) ($result['totalPages'] ?? 1);
            ++$page;
        } while ($page <= $totalPages);

        return $clients;
    }
}
