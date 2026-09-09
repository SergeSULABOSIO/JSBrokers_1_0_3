<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\Colonnes;
use App\Ai\Presentation\TableauMarkdown;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Service\Soa\SoaContextBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * LE RELEVÉ DE COMPTE D'UN CLIENT, RESTITUÉ DANS LA CONVERSATION.
 *
 * Le SOA (Statement of Account) est la pièce que le courtier présente à son
 * client : ce qu'il doit, ce qu'il a payé, ce qui reste, police par police et
 * tranche par tranche, plus ses sinistres et deux ratios de pilotage.
 *
 * ── POURQUOI CET OUTIL EXISTE ──────────────────────────────────────────────
 * Ket savait ENVOYER le relevé (`preparer_envoi_soa`) et savait répondre sur
 * les impayés (`suivi_impayes`), mais elle ne savait pas MONTRER le relevé
 * lui-même : il fallait ouvrir l'écran. C'était la seule dette réelle de
 * l'inventaire de parité (cf. `App\Ai\Parite\CouvertureDesEcrans`), et depuis
 * que le téléphone ne reçoit que la conversation, cette dette signifiait
 * qu'un courtier en déplacement ne pouvait pas consulter le compte de son
 * client — l'usage le plus banal qui soit.
 *
 * ── LES CHIFFRES SONT CEUX DE L'ÉCRAN, PAS DES CHIFFRES RESSEMBLANTS ───────
 * Tout vient de `SoaContextBuilder`, le service que les trois rendus du relevé
 * (workspace, aperçu imprimable, page publique du client) utilisent déjà —
 * colonnes dérivées comprises.
 *
 * Le RÉCAPITULATIF restitué est celui de la pièce remise au client (primes et
 * indemnisations). La vue de l'espace de travail en montre davantage — la
 * commission du cabinet et ses taxes —, mais ce sont les revenus du COURTIER sur
 * ce client, pas la position du compte de ce client : `indicateur_calcule` les
 * donne, avec une période. La légende de la section le dit à Ket, pour qu'elle
 * ne présente pas cette limite comme une donnée manquante.
 *
 * « Payé » et « Solde » ne sont pas des montants stockés mais des PRORATA du
 * taux de règlement global du client : les recalculer ici aurait créé une
 * quatrième version d'une formule qui en avait déjà trois. L'outil ne calcule
 * donc rien ; il met en forme.
 *
 * FAIL-CLOSED : lecture des Clients exigée, et le client résolu STRICTEMENT
 * dans l'entreprise du scope (patron `PreparerEnvoiSoaTool`).
 */
final class LireSoaTool implements AiToolInterface, AiToolConditionnel
{
    /** Nombre maximal de candidats restitués sur un nom ambigu. */
    private const MAX_CANDIDATS = 6;

    /**
     * Plafond de lignes par tableau — celui du RENDU, et pas un autre.
     *
     * Un relevé d'écran défile ; une bulle de chat se paye en tokens et se lit
     * sur un téléphone. Envoyer plus de lignes que `TableauMarkdown` n'en
     * affiche ferait payer des tokens pour des lignes invisibles, et surtout
     * ferait totaliser au modèle des montants que l'utilisateur ne verrait pas.
     * On tronque donc, et on le DIT (cf. `tableau()`).
     */
    private const MAX_LIGNES = TableauMarkdown::MAX_LIGNES;

    /** Largeur de la circonstance d'un sinistre restituée dans un tableau. */
    private const MAX_CIRCONSTANCE = 120;

    /** Les sections du relevé, et ce que chacune apporte. */
    private const SECTIONS = [
        'recapitulatif' => 'Récapitulatif global (primes et indemnisations : dû, payé, solde)',
        'polices'       => 'Portefeuille de polices actives',
        'echeancier'    => 'Échéancier des primes, tranche par tranche',
        'sinistres'     => 'Sinistres déclarés et indemnisations',
        'ratios'        => 'Ratio S/P et indice de solvabilité',
    ];

    /** Sections servies quand l'utilisateur demande « le relevé » sans préciser. */
    private const SECTIONS_PAR_DEFAUT = ['recapitulatif', 'echeancier', 'ratios'];

    /**
     * Ce que chaque chiffre VEUT DIRE. Sans ces définitions, le modèle explique
     * un solde de prime comme un solde bancaire, et un indice de solvabilité
     * comme une note de crédit.
     */
    private const LEGENDES = [
        'recapitulatif' => "Position du compte DU CLIENT : les primes d'assurance qu'il doit et les "
            . 'indemnisations que ses sinistres lui ouvrent. Un solde positif est une somme ENCORE '
            . "ATTENDUE. Ce sont les deux rubriques du relevé remis au client ; les revenus du CABINET "
            . 'sur ce client (commission de courtage, taxes) sont un autre sujet — indicateur_calcule '
            . 'les donne, avec sa période.',
        'polices'       => "Polices en portefeuille. « Payé » et « Solde » sont des PRORATA du taux de règlement "
            . "global du client (payé ÷ dû) : le relevé répartit ainsi ce qui a été encaissé, faute de paiement "
            . 'rattaché police par police. Ce ne sont donc pas des règlements constatés sur CETTE police.',
        'echeancier'    => "Tranches de prime par date d'échéance. Même prorata que les polices ; la colonne "
            . '« retard » vient, elle, de la tranche elle-même. C\'est le tableau du recouvrement.',
        'sinistres'     => "Sinistres déclarés : évaluation, montant déjà indemnisé, solde restant à verser au "
            . 'client par l\'assureur.',
        'ratios'        => "Ratio S/P = part des primes absorbée par les sinistres (sous 70 % excellent, au-delà "
            . "de 100 % le portefeuille est en perte). Indice de solvabilité = part des primes émises "
            . 'effectivement réglée par le client (sous 60 %, le risque d\'impayé impose un recouvrement).',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLibelle $libelleur,
        private readonly SoaContextBuilder $contextBuilder,
    ) {
    }

    public function name(): string
    {
        return 'lire_soa';
    }

    public function description(): string
    {
        return "Lit le RELEVÉ DE COMPTE (SOA) d'un client et le restitue dans la réponse : "
            . 'récapitulatif (dû / payé / solde), portefeuille de polices, échéancier des primes '
            . 'tranche par tranche, sinistres, ratio S/P et indice de solvabilité. Client désigné '
            . "par id (fourni par rechercher_entites) ou par nom. À appeler pour « où en est le "
            . 'compte de X ? », « montre-moi le relevé de X », « combien X nous doit-il ? ». '
            . "Chiffres identiques à ceux du relevé remis au client. Pour l'ENVOYER, utiliser "
            . 'preparer_envoi_soa.';
    }

    public function aiguillage(): string
    {
        return "Le relevé de compte / SOA d'un client, et toute question sur SA position de compte : ce qu'il "
            . "doit, ce qu'il a payé, son échéancier, sa sinistralité, sa solvabilité. Choisis les `sections` "
            . "utiles à la question plutôt que tout demander — chacune porte une « legende » sur laquelle "
            . "appuyer ton explication. Ne confonds pas avec suivi_impayes (les impayés de TOUT le "
            . 'portefeuille) ni avec preparer_envoi_soa (envoyer la pièce au client).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant du client (prioritaire sur nom).',
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => "Nom (ou partie du nom) du client, si l'id est inconnu.",
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => array_keys(self::SECTIONS)],
                    'description' => 'Sections à restituer. Par défaut : '
                        . implode(', ', self::SECTIONS_PAR_DEFAUT) . '.',
                ],
            ],
        ];
    }

    /** Chemin simulé : « le relevé de compte / le SOA de X », « où en est le compte de X ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // « envoie le SOA » appartient à preparer_envoi_soa : on ne le capte pas.
        if (ReleveDeCompteIntent::estUnEnvoi($normalized)) {
            return null;
        }
        // Vocabulaire du relevé : écrit UNE fois, et lu aussi par compter_entites
        // pour qu'il s'écarte (« compte » y est une forme du verbe compter).
        if (!ReleveDeCompteIntent::concerne($normalized)) {
            return null;
        }

        if (preg_match('/\b(?:soa|releve de compte|compte)\s+(?:du client |au client |de la |du |de |d )?(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
            $nom = trim($m[1]);
            if ($nom !== '') {
                return ['nom' => $nom];
            }
        }

        return null;
    }

    /** Miroir exact de la garde d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->canRead($scope->invite, 'Client');
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : même garde que l'écran du relevé (lecture Clients).
        $labels = $this->accessResolver->libellesEntites();
        if (!$this->accessResolver->canRead($scope->invite, 'Client')) {
            return AiToolResult::horsPerimetre($labels['Client'] ?? 'Clients');
        }

        $resolution = $this->resoudreClient($args, $scope, $labels);
        if (!$resolution instanceof Client) {
            return $resolution;
        }

        $sections = $this->sectionsDemandees($args);
        $contexte = $this->contextBuilder->build($resolution, $scope->entreprise, $scope->invite);

        $reponse = [
            'client'    => $this->libelleur->libelle($resolution, $this->libelleur->displayField(Client::class)),
            'reference' => $contexte['soaRef'],
            'arreteAu'  => $contexte['soaDate']->format('Y-m-d'),
            'monnaie'   => $contexte['monnaie'],
        ];

        foreach ($sections as $section) {
            $reponse[$section] = match ($section) {
                'recapitulatif' => $this->recapitulatif($resolution),
                'polices'       => $this->polices($contexte['polices'], $contexte['renewalLabels']),
                'echeancier'    => $this->echeancier($contexte['tranches']),
                'sinistres'     => $this->sinistres($contexte['sinistres']),
                'ratios'        => $this->ratios($resolution),
            };
            $reponse[$section]['legende'] = self::LEGENDES[$section];
        }

        // Ce qui N'A PAS été demandé, nommé : sans cela, le modèle croit avoir vu
        // tout le relevé et affirme « aucun sinistre » sur une section qu'il n'a
        // simplement pas réclamée.
        $absentes = array_values(array_diff(array_keys(self::SECTIONS), $sections));
        if ($absentes !== []) {
            $reponse['sectionsNonDemandees'] = $absentes;
        }

        return AiToolResult::ok($reponse);
    }

    /**
     * Résout le client dans l'entreprise du scope, ou renvoie le refus / la liste
     * de candidats à restituer tel quel.
     *
     * @param array<string, string> $labels
     */
    private function resoudreClient(array $args, AiScope $scope, array $labels): Client|AiToolResult
    {
        $id = (int) ($args['id'] ?? 0);
        $nom = trim((string) ($args['nom'] ?? ''));
        $displayField = $this->libelleur->displayField(Client::class);

        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($nom !== '' && $displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']];
        } else {
            return AiToolResult::introuvable($labels['Client'] ?? 'Clients');
        }

        // Scoping : le client doit exister DANS l'entreprise du scope.
        $result = $this->searchService->search(Client::class, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        $clients = ($result['status']['code'] ?? 500) === 200 ? $result['data'] : [];

        if ($clients === []) {
            return AiToolResult::introuvable(sprintf(
                '%s « %s »',
                $labels['Client'] ?? 'Client',
                $nom !== '' ? $nom : '#' . $id,
            ));
        }
        if (count($clients) > 1) {
            return AiToolResult::ok([
                'entite'    => 'Client',
                'libelle'   => $labels['Client'] ?? 'Clients',
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $c) => ['id' => $c->getId(), 'libelle' => $this->libelleur->libelle($c, $displayField)],
                    $clients,
                ),
            ]);
        }

        return $clients[0];
    }

    /** @return list<string> */
    private function sectionsDemandees(array $args): array
    {
        $demandees = array_values(array_filter(
            array_map('strval', (array) ($args['sections'] ?? [])),
            static fn (string $s) => isset(self::SECTIONS[$s]),
        ));

        // L'ordre du RELEVÉ, jamais celui de la demande : une pièce comptable se
        // lit dans un ordre stable, du général au détail.
        $ordonnees = array_values(array_intersect(array_keys(self::SECTIONS), $demandees));

        return $ordonnees !== [] ? $ordonnees : self::SECTIONS_PAR_DEFAUT;
    }

    /** Les deux lignes du récapitulatif, exactement celles de l'écran. */
    private function recapitulatif(Client $client): array
    {
        return [
            'lignes' => [
                [
                    'rubrique' => "Primes d'assurance",
                    'du'       => $this->valeur($client, 'primeTotale'),
                    'paye'     => $this->valeur($client, 'primePayee'),
                    'solde'    => $this->valeur($client, 'primeSoldeDue'),
                ],
                [
                    'rubrique' => 'Indemnisations sinistres',
                    'du'       => $this->valeur($client, 'indemnisationDue'),
                    'paye'     => $this->valeur($client, 'indemnisationVersee'),
                    'solde'    => $this->valeur($client, 'indemnisationSolde'),
                ],
            ],
            'presentation' => Colonnes::de([
                'rubrique' => Colonnes::TEXTE,
                'du'       => Colonnes::MONTANT,
                'paye'     => Colonnes::MONTANT,
                'solde'    => Colonnes::MONTANT,
            ]),
        ];
    }

    /**
     * @param array<int, array{avenant: object, cotation: object, piste: object, primePayee: float, primeSolde: float}> $polices
     * @param array<int, string>                                                                                        $statuts
     */
    private function polices(array $polices, array $statuts): array
    {
        return $this->tableau(
            array_map(
                fn (array $e) => [
                    'reference' => $e['avenant']->getReferencePolice() ?: '—',
                    'risque'    => $e['piste']->getRisque()?->getNomComplet() ?? '—',
                    'assureur'  => $e['cotation']->getAssureur()?->getNom() ?? '—',
                    'statut'    => $statuts[$e['avenant']->getRenewalStatus()] ?? '—',
                    'primeTTC'  => $this->valeur($e['avenant'], 'primeTotale'),
                    'paye'      => $e['primePayee'],
                    'solde'     => $e['primeSolde'],
                ],
                $polices,
            ),
            // ÉCARTÉES : les dates de début et de fin. Un relevé de compte répond
            // « combien », pas « jusqu'à quand » — les échéances sont l'affaire de
            // `vigie_echeances`, et la fiche de la police porte ses dates.
            [
                'reference' => Colonnes::IDENTIFIANT,
                'risque'    => Colonnes::TEXTE,
                'assureur'  => Colonnes::TEXTE,
                'statut'    => Colonnes::STATUT,
                'primeTTC'  => Colonnes::MONTANT,
                'paye'      => Colonnes::MONTANT,
                'solde'     => Colonnes::MONTANT,
            ],
        );
    }

    /**
     * @param array<int, array{tranche: object, cotation: object, piste: object, primePayee: float, primeSolde: float}> $tranches
     */
    private function echeancier(array $tranches): array
    {
        return $this->tableau(
            array_map(
                function (array $e): array {
                    $premier = $e['cotation']->getAvenants()->first();

                    return [
                        'police'       => $premier ? ($premier->getReferencePolice() ?: '—') : '—',
                        'tranche'      => $e['tranche']->getNom(),
                        'echeance'     => $e['tranche']->getPayableAt()?->format('Y-m-d'),
                        'primeTotale'  => $this->valeur($e['tranche'], 'primeTranche'),
                        'paye'         => $e['primePayee'],
                        'solde'        => $e['primeSolde'],
                        'retard'       => $this->retard($e['tranche']),
                    ];
                },
                $tranches,
            ),
            // ÉCARTÉS : le risque et l'assureur. Ils figurent au tableau des polices,
            // que la référence de police relie ligne à ligne — les répéter ici
            // coûterait deux colonnes à la seule table qui sert au recouvrement.
            [
                'police'      => Colonnes::IDENTIFIANT,
                'tranche'     => Colonnes::TEXTE,
                'echeance'    => Colonnes::DATE,
                'primeTotale' => Colonnes::MONTANT,
                'paye'        => Colonnes::MONTANT,
                'solde'       => Colonnes::MONTANT,
                'retard'      => Colonnes::STATUT,
            ],
        );
    }

    /** @param array<int, object> $sinistres */
    private function sinistres(array $sinistres): array
    {
        return $this->tableau(
            array_map(
                fn (object $s) => [
                    'reference'    => $s->getReferenceSinistre() ?: '—',
                    'circonstance' => $this->resume(strip_tags((string) $s->getDescriptionDeFait())),
                    'risque'       => $s->getRisque()?->getNomComplet() ?? '—',
                    'survenance'   => $s->getOccuredAt()?->format('Y-m-d'),
                    'evaluation'   => $this->valeur($s, 'evaluationChiffree'),
                    'indemnise'    => $this->valeur($s, 'compensationVersee'),
                    'solde'        => $this->valeur($s, 'compensationSoldeAverser'),
                ],
                $sinistres,
            ),
            [
                'reference'    => Colonnes::IDENTIFIANT,
                'circonstance' => Colonnes::TEXTE,
                'risque'       => Colonnes::TEXTE,
                'survenance'   => Colonnes::DATE,
                'evaluation'   => Colonnes::MONTANT,
                'indemnise'    => Colonnes::MONTANT,
                'solde'        => Colonnes::MONTANT,
            ],
        );
    }

    /**
     * Les deux ratios de pilotage, en POINTS de pourcentage (convention du
     * projet : 70 = 70 %).
     *
     * AUCUNE `presentation` ici, contrairement aux autres sections : ce ne sont
     * pas des lignes de tableau mais deux scalaires, et `TableauMarkdown` attend
     * une liste. En déclarer une inviterait la phase de rédaction à dresser un
     * tableau de deux cases. L'unité est donc dite explicitement, et la légende
     * porte les seuils de lecture.
     */
    private function ratios(Client $client): array
    {
        return [
            'tauxSP'            => $this->valeur($client, 'tauxSP'),
            'indiceSolvabilite' => $this->valeur($client, 'indiceSolvabilite'),
            'unite'             => '%',
        ];
    }

    /**
     * Un tableau borné, qui DIT quand il est tronqué.
     *
     * ⚠ SEPT COLONNES AU PLUS. `TableauMarkdown` s'arrête à `MAX_COLONNES` — en
     * SILENCE, et dans l'ordre de déclaration. Les tableaux du relevé en
     * comptent neuf à l'écran : déclarés tels quels, ils auraient perdu « payé »
     * et « solde », c'est-à-dire les deux seules colonnes pour lesquelles on lit
     * un relevé de compte. Chaque appelant choisit donc ses sept colonnes et dit
     * ce qu'il écarte ; `LireSoaToolTest` vérifie qu'aucun ne dépasse.
     *
     * @param list<array<string, mixed>> $lignes
     * @param array<string, string>      $roles
     */
    private function tableau(array $lignes, array $roles): array
    {
        $total = count($lignes);

        return [
            'nombre'          => $total,
            'lignes'          => array_slice($lignes, 0, self::MAX_LIGNES),
            'lignesTronquees' => $total > self::MAX_LIGNES,
            'presentation'    => Colonnes::de($roles),
        ];
    }

    /**
     * La circonstance d'un sinistre, ramenée à une largeur lisible.
     *
     * L'écran la borne par CSS (`soa-circonstance-clamp`) et garde le texte
     * entier en infobulle ; dans une conversation, un récit de sinistre entier
     * par ligne noierait le tableau et se paierait en tokens. Le texte complet
     * reste à un `lire_fiche` de distance.
     */
    private function resume(string $texte): string
    {
        $texte = trim(preg_replace('/\s+/', ' ', $texte) ?? '');
        if ($texte === '') {
            return '—';
        }

        return mb_strlen($texte) > self::MAX_CIRCONSTANCE
            ? mb_substr($texte, 0, self::MAX_CIRCONSTANCE - 1) . '…'
            : $texte;
    }

    /**
     * Le retard d'une tranche tel que l'écran le montre : le texte de l'indicateur
     * quand il en porte un, `null` sinon (« Non » et « N/A » ne sont pas des
     * retards, et les restituer ferait dire à Ket qu'une tranche est en retard).
     */
    private function retard(object $tranche): ?string
    {
        $retard = property_exists($tranche, 'retardPaiement') ? (string) ($tranche->retardPaiement ?? '') : '';

        return ($retard === '' || $retard === 'Non' || $retard === 'N/A') ? null : $retard;
    }

    /**
     * Lecture d'une valeur CALCULÉE (propriété dynamique posée par
     * `CanvasBuilder::loadAllCalculatedValues`, appelé par SoaContextBuilder).
     *
     * `property_exists` et non `??` : la propriété n'existe pas du tout tant
     * qu'aucune stratégie ne l'a posée.
     */
    private function valeur(object $entite, string $cle): float
    {
        return property_exists($entite, $cle) ? (float) ($entite->{$cle} ?? 0.0) : 0.0;
    }
}
