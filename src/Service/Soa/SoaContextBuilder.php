<?php

namespace App\Service\Soa;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Saturation\SaturationService;
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
        private SaturationService $saturationService,
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
                'crossSelling'     => $this->saturationService->opportunites($client, $entreprise),
            ];
        }

        return $context;
    }
}
