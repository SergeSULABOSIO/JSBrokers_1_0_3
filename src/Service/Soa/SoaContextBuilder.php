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
 * Utilisé par le SoaController (workspace/aperçu courtier), par le contrôleur
 * public tokenisé, et par `lire_soa` — l'outil qui restitue le relevé DANS la
 * conversation avec Ket.
 *
 * En vue client ($vueClient = true), les données de travail du courtier
 * (pistes/cotations en cours, tâches, cross-selling) ne sont NI calculées
 * NI présentes dans le tableau retourné : les partials ne rendent que les
 * sections dont la clé existe.
 *
 * ── POURQUOI LES COLONNES DÉRIVÉES SONT CALCULÉES ICI ──────────────────────
 * « Payé » et « Solde », par police comme par tranche, ne sont PAS des montants
 * stockés : ce sont des PRORATA du taux de règlement global du client
 * (`montant_paye / montant_du`). Cette arithmétique vivait en Twig, recopiée
 * dans les deux gabarits du relevé — et il a fallu la recopier une troisième
 * fois le jour où Ket a dû restituer le même relevé dans la conversation.
 *
 * Trois copies d'une formule, c'est trois vérités en sursis : le jour où l'une
 * change, l'écran et l'assistant annoncent au client deux soldes différents sur
 * le même compte. Elle est donc posée une fois, ici, et les gabarits comme
 * l'outil la LISENT. C'est ce qui rend la parité écran ↔ Ket structurelle au
 * lieu d'être surveillée.
 */
class SoaContextBuilder
{
    /**
     * Libellés des statuts de renouvellement TELS QUE LE RELEVÉ LES NOMME.
     *
     * ⚠ Ce ne sont pas ceux d'`AvenantIndicatorStrategy` (« Unique (sans
     * renouvellement) », « Prorogé », « Annulé ») : le relevé est une pièce
     * adressée au CLIENT, et il a toujours employé un vocabulaire plus court.
     * Les deux jeux coexistent donc à dessein — ce qui ne doit pas arriver,
     * c'est un TROISIÈME jeu. Ils étaient déjà recopiés dans les deux gabarits
     * du relevé ; ils sont maintenant lus depuis le contexte.
     */
    public const STATUTS_DE_POLICE = [
        0 => 'Perdu',
        1 => 'Ponctuel',
        2 => 'Renouvelé',
        3 => 'Prolongé',
        4 => 'En cours',
        5 => 'En renouvellement',
        6 => 'Résilié',
    ];

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
        //
        // ⚠ ET JAMAIS VIDE. `getCodeMonnaieAffichage()` s'appuie sur l'utilisateur
        // connecté : il rend `null` partout où il n'y en a pas — worker Messenger,
        // ligne de commande, et donc les réponses de Ket. Un relevé de compte dont
        // les montants sortent sans monnaie n'est pas un relevé : « 18 500,00 » ne
        // dit pas si le client doit des dollars ou des francs. On retombe donc sur
        // l'entreprise, puis sur l'USD — le même repli que la branche publique.
        $monnaie = ($invite !== null || $entreprise === null)
            ? $this->serviceMonnaies->getCodeMonnaieAffichage()
            : null;

        if (($monnaie === null || $monnaie === '') && $entreprise !== null) {
            $monnaie = $this->serviceMonnaies->getMonnaieAffichagePourEntreprise($entreprise)?->getCode();
        }
        $monnaie = ($monnaie === null || $monnaie === '') ? 'USD' : $monnaie;

        // LE TAUX DE RÈGLEMENT DU CLIENT, calculé une fois. `null` quand rien n'est
        // dû : il n'y a alors aucun prorata à appliquer, et les deux tableaux ne
        // traitent pas ce cas de la même façon (cf. `prorata()`).
        $ratioPaiement = $this->ratioDeReglement($client);

        // Colonnes « Payé » et « Solde » de chaque police et de chaque tranche.
        // Posées SUR l'entrée : les gabarits n'ont plus qu'à les lire, et l'outil
        // `lire_soa` lit exactement les mêmes.
        foreach ($polices as $i => $entree) {
            $polices[$i] += $this->prorata($entree['avenant'], 'primeTotale', $ratioPaiement, null);
        }
        foreach ($tranches as $i => $entree) {
            // Une tranche a un montant payé PROPRE : c'est lui qui sert de repli
            // quand aucun prorata n'est applicable — une police, elle, affiche 0.
            $tranches[$i] += $this->prorata($entree['tranche'], 'primeTranche', $ratioPaiement, 'primePayee');
        }

        $context = [
            'vueClient'     => $vueClient,
            'client'        => $client,
            'entreprise'    => $entreprise,
            'idEntreprise'  => $entreprise?->getId(),
            'monnaie'       => $monnaie,
            'soaRef'        => 'SOA-' . $client->getId() . '-' . date('Y'),
            'soaDate'       => new \DateTimeImmutable(),
            'polices'       => $polices,
            'tranches'      => $tranches,
            'sinistres'     => $sinistres,
            'ratioPaiement' => $ratioPaiement,
            // Les gabarits lisaient ces libellés d'un `{% set %}` local, chacun le
            // sien : ils viennent maintenant d'ici.
            'renewalLabels' => self::STATUTS_DE_POLICE,
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

    /**
     * Taux de règlement global du client — `null` quand rien n'est dû.
     *
     * C'est la clé de voûte du relevé : faute de paiement rattaché police par
     * police, le relevé RÉPARTIT ce qui a été payé au prorata des montants dus.
     */
    private function ratioDeReglement(Client $client): ?float
    {
        $du = $this->valeurCalculee($client, 'montant_du');

        return $du > 0.0 ? $this->valeurCalculee($client, 'montant_paye') / $du : null;
    }

    /**
     * Part payée et solde d'une ligne, au prorata du taux de règlement.
     *
     * @param string      $cleMontant Clé du montant dû de la ligne (calculé).
     * @param string|null $cleRepli   Montant payé PROPRE de la ligne, utilisé
     *                                quand aucun prorata n'est applicable.
     *                                `null` = la ligne affiche alors 0.
     *
     * @return array{primePayee: float, primeSolde: float}
     */
    private function prorata(object $ligne, string $cleMontant, ?float $ratio, ?string $cleRepli): array
    {
        $du = $this->valeurCalculee($ligne, $cleMontant);

        $payee = $ratio !== null
            ? $du * $ratio
            : ($cleRepli === null ? 0.0 : $this->valeurCalculee($ligne, $cleRepli));

        return ['primePayee' => $payee, 'primeSolde' => $du - $payee];
    }

    /**
     * Lecture d'une valeur CALCULÉE, posée en propriété dynamique par
     * `CanvasBuilder::loadAllCalculatedValues()`.
     *
     * `property_exists` et non `??` : la propriété n'existe pas du tout tant
     * qu'aucune stratégie ne l'a posée, et la lire directement émettrait un
     * avertissement à chaque ligne d'un relevé un peu fourni.
     */
    private function valeurCalculee(object $entite, string $cle): float
    {
        return property_exists($entite, $cle) ? (float) ($entite->{$cle} ?? 0.0) : 0.0;
    }
}
