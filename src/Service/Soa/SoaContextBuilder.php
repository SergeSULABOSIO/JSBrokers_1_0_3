<?php

namespace App\Service\Soa;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\RisqueRepository;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Construit le contexte de rendu du relevé de compte (SOA) d'un client.
 * Utilisé par le SoaController (workspace/aperçu courtier) et par le
 * contrôleur public tokenisé.
 *
 * En vue client ($vueClient = true), les données de travail du courtier
 * (pistes/cotations en cours, tâches, cross-selling) ne sont NI calculées
 * NI présentes dans le tableau retourné : les partials ne rendent que les
 * sections dont la clé existe.
 */
class SoaContextBuilder
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private ServiceMonnaies $serviceMonnaies,
        private RisqueRepository $risqueRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(Client $client, ?Entreprise $entreprise, ?Invite $invite, bool $vueClient = false): array
    {
        $this->canvasBuilder->loadAllCalculatedValues($client);

        foreach ($client->getPartenaires() as $partenaire) {
            $this->canvasBuilder->loadAllCalculatedValues($partenaire);
        }

        $polices          = [];
        $pistesEnCours    = [];
        $cotationsEnCours = [];
        $tranches         = [];
        $taches           = [];
        $tacheIds         = [];

        foreach ($client->getPistes() as $piste) {
            if (!$vueClient) {
                $pisteHasAvenant = false;
                foreach ($piste->getCotations() as $c) {
                    if (!$c->getAvenants()->isEmpty()) { $pisteHasAvenant = true; break; }
                }
                if (!$piste->isClosed() && $piste->getAvenantDeBase() === null && !$pisteHasAvenant) {
                    $pistesEnCours[] = $piste;
                }
            }

            foreach ($piste->getCotations() as $cotation) {
                $avenants = $cotation->getAvenants();

                if ($avenants->isEmpty() && !$piste->isClosed()) {
                    if (!$vueClient) {
                        $this->canvasBuilder->loadAllCalculatedValues($cotation);
                        $cotationsEnCours[] = ['cotation' => $cotation, 'piste' => $piste];
                    }
                } else {
                    foreach ($avenants as $avenant) {
                        $this->canvasBuilder->loadAllCalculatedValues($avenant);
                        $polices[] = ['avenant' => $avenant, 'cotation' => $cotation, 'piste' => $piste];
                    }
                    foreach ($cotation->getTranches() as $tranche) {
                        $this->canvasBuilder->loadAllCalculatedValues($tranche);
                        $tranches[] = ['tranche' => $tranche, 'cotation' => $cotation, 'piste' => $piste];
                    }
                }

                if (!$vueClient) {
                    foreach ($cotation->getTaches() as $tache) {
                        if (!in_array($tache->getId(), $tacheIds, true)) {
                            $taches[]   = $tache;
                            $tacheIds[] = $tache->getId();
                        }
                    }
                }
            }

            if (!$vueClient) {
                foreach ($piste->getTaches() as $tache) {
                    if (!in_array($tache->getId(), $tacheIds, true)) {
                        $taches[]   = $tache;
                        $tacheIds[] = $tache->getId();
                    }
                }
            }
        }

        $sinistres = [];
        foreach ($client->getNotificationSinistres() as $sinistre) {
            $this->canvasBuilder->loadAllCalculatedValues($sinistre);
            $sinistres[] = $sinistre;

            if (!$vueClient) {
                foreach ($sinistre->getTaches() as $tache) {
                    if (!in_array($tache->getId(), $tacheIds, true)) {
                        $taches[]   = $tache;
                        $tacheIds[] = $tache->getId();
                    }
                }
            }
        }

        foreach ($taches as $tache) {
            $this->canvasBuilder->loadAllCalculatedValues($tache);
        }

        usort($tranches, static function (array $a, array $b): int {
            $dateA = $a['tranche']->getPayableAt();
            $dateB = $b['tranche']->getPayableAt();
            if ($dateA === null && $dateB === null) return 0;
            if ($dateA === null) return 1;
            if ($dateB === null) return -1;
            return $dateA <=> $dateB;
        });

        // Hors session (rendu public), la monnaie d'affichage est résolue par
        // l'entreprise émettrice et non par l'utilisateur connecté.
        if ($invite !== null || $entreprise === null) {
            $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        } else {
            $monnaie = $this->serviceMonnaies->getMonnaieAffichagePourEntreprise($entreprise)?->getCode() ?? 'USD';
        }

        $context = [
            'vueClient'    => $vueClient,
            'client'       => $client,
            'entreprise'   => $entreprise,
            'idEntreprise' => $entreprise?->getId(),
            'monnaie'      => $monnaie,
            'soaRef'       => 'SOA-' . $client->getId() . '-' . date('Y'),
            'soaDate'      => new \DateTimeImmutable(),
            'polices'      => $polices,
            'tranches'     => $tranches,
            'sinistres'    => $sinistres,
        ];

        if (!$vueClient) {
            $context += [
                'idInvite'         => $invite?->getId(),
                'apercuUrl'        => $this->urlGenerator->generate('admin.soa.client_apercu', ['id' => $client->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'pistesEnCours'    => $pistesEnCours,
                'cotationsEnCours' => $cotationsEnCours,
                'taches'           => $taches,
                'crossSelling'     => $this->buildCrossSellingOpportunites($client, $entreprise),
            ];
        }

        return $context;
    }

    /**
     * Détermine les opportunités de cross-selling avec le client :
     * - 'nouveaux'   : risques du catalogue de l'entreprise jamais abordés avec le client (aucune piste) ;
     * - 'aRelancer'  : risques abordés par le passé mais sans suite (pistes fermées, polices perdues ou résiliées).
     * Les risques avec une piste ouverte ou une police valide sont exclus (déjà couverts ou en négociation).
     */
    private function buildCrossSellingOpportunites(Client $client, ?Entreprise $entreprise): array
    {
        if ($entreprise === null) {
            return ['nouveaux' => [], 'aRelancer' => []];
        }

        // Catalogue strictement issu de la BD, restreint à l'entreprise courante.
        $catalogue = $this->risqueRepository->findCatalogueForEntreprise($entreprise);
        if ($catalogue === []) {
            return ['nouveaux' => [], 'aRelancer' => []];
        }

        // Ensemble des IDs du catalogue : garde-fou d'isolation par entreprise.
        $catalogueIds = [];
        foreach ($catalogue as $risqueCatalogue) {
            $catalogueIds[$risqueCatalogue->getId()] = true;
        }

        // Statut par risque : 'actif' (couvert ou en négociation) > 'policePerdue' > 'pisteFermee'
        $statuts = [];
        $dernierePisteFermee = []; // risqueId => Piste fermée la plus récente (pour "Editer la piste")
        foreach ($client->getPistes() as $piste) {
            $risque = $piste->getRisque();
            // On ne calcule un statut que pour les risques réellement persistés sous l'entreprise courante.
            if ($risque === null || !isset($catalogueIds[$risque->getId()])) {
                continue;
            }
            $risqueId = $risque->getId();

            $aUnAvenant      = false;
            $aPoliceValide   = false;
            foreach ($piste->getCotations() as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    $aUnAvenant = true;
                    if (!in_array($avenant->getRenewalStatus(), [Avenant::RENEWAL_STATUS_LOST, Avenant::RENEWAL_STATUS_CANCELLED], true)) {
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
}
