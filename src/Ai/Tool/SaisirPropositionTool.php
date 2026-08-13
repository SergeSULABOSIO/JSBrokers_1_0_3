<?php

namespace App\Ai\Tool;

use App\Ai\Trousse\AiToolEcriture;

use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Parcours\ParcoursCatalogue;
use App\Ai\Proposition\RevenuCourtierPrescrit;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Service\Workspace\ReferentielEnumerateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Ai\Resolution\ResolveurDeReferences;

/**
 * SAISIE COMPLÈTE d'une proposition (cotation) EN UN SEUL APPEL.
 *
 * POURQUOI CET OUTIL EXISTE — et c'est un constat de mesure, pas une intuition.
 * Sur la campagne du 2026-08-08/09 (31 messages, 96 tours), un seul parcours
 * représentait 48 % des messages, 71 % des tokens d'entrée et 12 des 13
 * saturations du débit : chercher l'assureur, chercher la piste, chercher le
 * risque, demander l'inventaire des champs, puis préparer. Cinq à six tours, dont
 * chacun réexpédie l'INTÉGRALITÉ du contexte (l'API est sans mémoire). Le message
 * le plus parlant du journal donnait pourtant TOUTE la matière — « Prime nette
 * 1000, Arca 20, Tva 160, Accessoire 50, deux tranches, 12 mois » — et s'est
 * terminé sur « votre demande m'a obligé à enchaîner trop de recherches ». Rien
 * n'a été enregistré.
 *
 * Or cette séquence, nous la connaissons : elle est écrite depuis le début dans
 * {@see ParcoursCatalogue}, trame « proposition ». Elle servait de DOCUMENTATION
 * rendue au modèle, qui refaisait lui-même le travail. Cet outil l'EXÉCUTE :
 * Symfony résout les relations par leur nom, dérive ce qui est dérivable, et
 * délègue la construction du plan à {@see PlanBuilder}. Le modèle ne fait plus que
 * ce qu'il fait bien — comprendre la phrase et en extraire les valeurs.
 *
 * CE QU'IL N'INVENTE JAMAIS. Un nom qui ne se résout pas ne devient pas un id au
 * jugé : l'outil renvoie « manquants » AVEC les valeurs réellement disponibles, et
 * le modèle pose la question. Un référentiel ambigu (deux assureurs plausibles) est
 * un « ambigu », jamais un choix arbitraire — la règle de la maison est qu'on ne
 * devine pas une donnée du courtier.
 *
 * CE QU'IL AJOUTE SANS QU'ON LE LUI DEMANDE, en revanche, parce que le métier le dit :
 * la RÉMUNÉRATION du courtier ({@see RevenuCourtierPrescrit}, au taux prescrit par la
 * configuration du risque) et l'ÉCHÉANCIER — une prime est payable en une fois, à
 * 100 %, sauf fractionnement dicté. Ce ne sont pas des devinettes mais des règles :
 * aucune affaire ne se place sans commission, et une proposition sans échéance ne rend
 * rien exigible. Les deux sont ANNONCÉS dans « defauts » et restent décochables avant
 * exécution — un défaut qu'on peut lire et corriger d'une phrase n'est pas un défaut subi.
 *
 * FAIL-CLOSED : droit d'écriture sur Cotation vérifié ici, puis re-vérifié par
 * WorkspaceMutationService pour chaque opération du plan.
 */
final class SaisirPropositionTool implements AiToolProduisantUnPlan, AiToolEcriture
{
    /** Le parcours exécuté par cet outil (sa trame porte les libellés d'étape). */
    private const PARCOURS = 'proposition';

    /**
     * Synonymes usuels des composantes de prime → libellé du référentiel Chargement.
     *
     * Le courtier dicte « Arca », « Tva », « Accessoire » ; le référentiel de
     * l'entreprise dit « Frais ARCA », « TVA », « Frais accessoires ». Sans cette
     * table, chaque composante coûtait un aller-retour de question. Elle ne SUBSTITUE
     * jamais un référentiel absent : si l'entreprise n'a pas la ligne, on le dit.
     */
    private const SYNONYMES_CHARGEMENT = [
        'prime nette'      => ['prime nette', 'prime pure', 'nette'],
        'frais accessoires' => ['accessoire', 'accessoires', 'frais accessoire', 'frais accessoires', 'frais de dossier'],
        'tva'              => ['tva', 'taxe sur la valeur ajoutee', 'dgi'],
        'frais arca'       => ['arca', 'frais arca', 'taxe arca'],
    ];

    public function __construct(
        private readonly PlanBuilder $planBuilder,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly ReferentielEnumerateur $referentiels,
        // Source unique de la résolution « nom dicté → identifiant », partagée avec
        // tous les plans : deux implémentations parallèles divergeraient.
        private readonly ResolveurDeReferences $resolveur,
        // La rémunération du courtier, dérivée de la configuration du risque : elle
        // accompagne TOUTE proposition, car aucune affaire ne se place sans commission.
        private readonly RevenuCourtierPrescrit $revenuPrescrit,
    ) {
    }

    public function name(): string
    {
        return 'saisir_proposition';
    }

    public function description(): string
    {
        return 'ENREGISTRER UNE PROPOSITION (COTATION) COMPLÈTE EN UN SEUL APPEL — le chemin RAPIDE, à '
            . 'préférer systématiquement à preparer_operations quand l\'utilisateur te dicte une offre '
            . 'd\'assureur (« voici l\'offre de X », « enregistre cette proposition », « j\'ai reçu une '
            . 'cotation »). Tu donnes ce qu\'il a DIT, en clair : le nom de l\'assureur, la piste ou le '
            . 'client, la durée, la composition de la prime (prime nette, accessoires, TVA, ARCA…) et le '
            . 'découpage en tranches. LE SERVEUR fait le reste : il retrouve l\'assureur, la piste et les '
            . 'types de chargement PAR LEUR NOM, construit la cotation avec sa composition de prime, son '
            . 'échéancier et LA RÉMUNÉRATION DU COURTIER, puis rend un PLAN + BUDGET à valider. '
            . 'NE DEMANDE JAMAIS la commission ni le découpage de la prime : le revenu du courtier est '
            . 'ajouté D\'OFFICE au taux prescrit par la configuration du risque, et la prime est réputée '
            . 'payable en UNE FOIS (100 %). '
            . 'N\'APPELLE DONC NI rechercher_entites (aucun id à trouver), NI inventaire_champs, NI '
            . 'parcours_saisie avant lui : tu perdrais plusieurs tours pour un travail déjà fait. '
            . 'S\'il manque une information ou qu\'un nom est ambigu, l\'outil te le dit avec les valeurs '
            . 'disponibles : pose alors UNE question groupée en PROPOSANT ces valeurs, et arrête-toi là — tu le '
            . 'rappelleras au message SUIVANT, avec la réponse. '
            . 'Pour une saisie qui n\'est PAS une proposition d\'assureur, utilise preparer_operations.';
    }

    public function aiguillage(): string
    {
        return 'Une PROPOSITION d\'assureur que l\'utilisateur te DICTE (« voici l\'offre de SFA », « j\'ai reçu une '
            . 'cotation », « enregistre cette proposition »), avec ou sans le détail de la prime. Appelle-moi '
            . 'DIRECTEMENT, sans aucune recherche préalable : je résous l\'assureur, la piste et les types de '
            . 'chargement par leur NOM, et je rends le plan complet en un seul appel.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nom' => [
                    'type' => 'string',
                    'description' => 'Objet de la proposition, tel que nommé par l\'utilisateur '
                        . '(ex. « Flotte automobile 2026 »). À défaut, un libellé reprenant le risque.',
                ],
                'assureur' => [
                    'type' => 'string',
                    'description' => 'NOM de l\'assureur qui propose, tel que dicté (ex. « SFA »). '
                        . 'Pas un identifiant : le serveur le résout lui-même.',
                ],
                'piste' => [
                    'type' => 'string',
                    'description' => 'Opportunité (piste) sur laquelle porte la proposition : son nom, ou son '
                        . 'identifiant si tu l\'as déjà. Si tu ne connais que le client, laisse vide et '
                        . 'renseigne « client ».',
                ],
                'client' => [
                    'type' => 'string',
                    'description' => 'NOM du client, à utiliser quand la piste n\'est pas nommée : le serveur '
                        . 'cherche ses opportunités et te demande laquelle s\'il y en a plusieurs.',
                ],
                'dureeMois' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Durée de couverture en MOIS (ex. 12).',
                ],
                'composition' => [
                    'type' => 'array',
                    'description' => 'Composition de la prime, une entrée par ligne dictée. Donne le nom TEL QUE '
                        . 'DIT (« Prime nette », « Arca », « Tva », « Accessoire ») : le serveur le rattache au '
                        . 'référentiel de l\'entreprise. N\'additionne rien et n\'invente aucune ligne absente.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nom'     => ['type' => 'string', 'description' => 'Libellé dicté de la composante.'],
                            'montant' => ['type' => 'number', 'description' => 'Montant de cette composante.'],
                        ],
                        'required' => ['nom', 'montant'],
                    ],
                ],
                'nombreTranches' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Nombre de tranches de paiement d\'égale valeur (ex. 2 pour « payable en deux '
                        . 'tranches de 50 % »). Le serveur répartit les pourcentages et échelonne les échéances. '
                        . 'À NE DONNER QUE si l\'utilisateur a dit que la prime est FRACTIONNÉE : par défaut elle '
                        . 'est payable en une seule fois (une tranche à 100 %), et tu n\'as pas à le demander. '
                        . 'Utilise « tranches » à la place si elles sont inégales ou datées précisément.',
                ],
                'tranches' => [
                    'type' => 'array',
                    'description' => 'Échéancier détaillé, quand les tranches ne sont pas égales.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nom'         => ['type' => 'string', 'description' => 'Libellé de la tranche.'],
                            'pourcentage' => ['type' => 'number', 'description' => 'Part de la prime, EN POINTS (50 = 50 %).'],
                            'montant'     => ['type' => 'number', 'description' => 'Montant fixe, si la tranche est chiffrée en valeur.'],
                            'payableAt'   => ['type' => 'string', 'description' => 'Date d\'exigibilité au format AAAA-MM-JJ.'],
                        ],
                    ],
                ],
                'debutCouverture' => [
                    'type' => 'string',
                    'description' => 'Date de prise d\'effet au format AAAA-MM-JJ, si l\'utilisateur l\'a donnée. '
                        . 'Sert à échelonner les tranches ; à défaut, la date du jour.',
                ],
                'tauxCommissionPercent' => [
                    'type' => 'number',
                    'description' => 'Taux de commission EXCEPTIONNEL, en POINTS (16 = 16 %), UNIQUEMENT si '
                        . 'l\'utilisateur en dicte un qui DÉROGE au taux habituel. Sinon, ne le renseigne PAS et '
                        . 'ne le demande PAS : le serveur applique le taux prescrit par la configuration du '
                        . 'risque de l\'opportunité, ou à défaut celui du type de revenu.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'true seulement si un plan attend déjà une décision ET que l\'utilisateur '
                        . 'demande de le CHANGER. L\'ancien est alors annulé et remplacé.',
                ],
            ],
            'required' => ['assureur', 'composition'],
        ];
    }

    /** Chemin simulé neutralisé : la saisie structurée relève du LLM réel. */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED, avant tout travail : sans droit d'écriture sur les cotations,
        // l'outil n'existe pas pour cet invité.
        if (!$this->accessResolver->can($scope->invite, 'Cotation', Invite::ACCESS_ECRITURE)) {
            $labels = $this->accessResolver->libellesEntites();

            return AiToolResult::horsPerimetre($labels['Cotation'] ?? 'Cotations');
        }

        $refus = $this->planBuilder->refusSiPlanEnAttente(
            $scope,
            ($args['remplacerPlanEnAttente'] ?? false) === true,
            $this->name(),
        );
        if ($refus !== null) {
            return $refus;
        }

        $aDemander = [];
        $defauts = [];

        // 1. L'ASSUREUR, par son nom. Une seule correspondance = on avance ; zéro ou
        //    plusieurs = on demande, jamais on ne tranche à sa place.
        $assureur = $this->resoudreEnregistrement('Assureur', (string) ($args['assureur'] ?? ''), $scope, $aDemander);

        // 2. LA PISTE : nommée, ou déduite du client quand il n'a qu'une opportunité.
        $piste = $this->resoudrePiste($args, $scope, $aDemander, $defauts);

        // 3. LA COMPOSITION de la prime, rattachée au référentiel Chargement de
        //    l'entreprise (« Arca » → « Frais ARCA »).
        $chargements = $this->resoudreComposition($args['composition'] ?? [], $scope, $aDemander);

        if ($aDemander !== []) {
            return $this->demander($aDemander);
        }

        // 4. Ce qui se DÉRIVE se dérive, et s'annonce — jamais ne se demande.
        $etapes = $this->libellesEtapes();
        $duree = (int) ($args['dureeMois'] ?? 0);
        $debut = $this->dateDebut($args, $defauts);

        $collections = [
            ['collection' => 'chargements', 'elements' => $chargements],
        ];

        $tranches = $this->construireTranches($args, $debut, $duree, $etapes['echeancier'], $defauts);
        if ($tranches !== []) {
            $collections[] = ['collection' => 'tranches', 'elements' => $tranches];
        }

        // LA COMMISSION N'EST PAS UNE OPTION. Elle est dérivée de la configuration —
        // taux prescrit par le risque, ou taux du type —, jamais demandée quand elle
        // se déduit. Si elle ne se déduit PAS, on s'arrête ici plutôt que d'écrire une
        // affaire à commission nulle.
        $revenu = $this->revenuPrescrit->deriver(
            isset($piste['id']) ? (int) $piste['id'] : null,
            $this->assiettes($chargements),
            $this->idBaseDeCommission($scope),
            isset($args['tauxCommissionPercent']) && is_numeric($args['tauxCommissionPercent'])
                ? (float) $args['tauxCommissionPercent']
                : null,
            $etapes['revenu-courtier'],
            $scope,
        );
        if ($revenu['aDemander'] !== []) {
            return $this->demander($revenu['aDemander']);
        }
        if ($revenu['elements'] !== []) {
            $collections[] = ['collection' => 'revenus', 'elements' => $revenu['elements']];
        }
        foreach ($revenu['defauts'] as $ligne) {
            $defauts[] = $ligne;
        }

        $champs = array_filter([
            'nom'      => $this->nomProposition($args, $assureur),
            'assureur' => $assureur['id'],
            'piste'    => $piste['id'] ?? null,
            'duree'    => $duree > 0 ? $duree : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $operations = [[
            'op'          => 'create',
            'entite'      => 'Cotation',
            'etape'       => $etapes['cotation'],
            'champs'      => $champs,
            'collections' => $collections,
        ]];

        $resultat = $this->planBuilder->construire(MutationPlan::fromArray($operations), $scope, $this->name());

        // Les défauts appliqués voyagent AVEC le plan : la règle de la maison est de
        // les annoncer, pas de les demander — encore faut-il que le modèle les ait.
        return $this->avecDefauts($resultat, $defauts, $assureur, $piste, $revenu['avertissements']);
    }

    /**
     * Résout un enregistrement par son nom, dans le périmètre de l'entreprise.
     * Alimente $aDemander plutôt que de choisir quand la réponse n'est pas unique.
     *
     * @param array<int, array<string, mixed>> $aDemander
     *
     * @return array{id: ?int, libelle: string, terme: string}
     */
    private function resoudreEnregistrement(string $entite, string $terme, AiScope $scope, array &$aDemander): array
    {
        // SOURCE UNIQUE : la même résolution que celle appliquée aux relations de
        // tout plan (PlanBuilder). Deux implémentations parallèles divergeraient au
        // premier correctif, et la divergence ne se verrait que sur les données
        // réelles de l'utilisateur.
        $reference = $this->resolveur->resoudre($entite, $terme, $scope);

        if (!$reference->estResolue()) {
            $aDemander[] = $reference->question();

            return ['id' => null, 'libelle' => $reference->libelleEntite, 'terme' => trim($terme)];
        }

        return ['id' => $reference->id, 'libelle' => (string) $reference->libelle, 'terme' => trim($terme)];
    }

    /**
     * La piste : nommée directement, donnée par id, ou déduite du client. Un client
     * qui n'a qu'UNE opportunité la voit reconduite d'office (défaut annoncé) ;
     * plusieurs, et l'on demande laquelle.
     *
     * @param array<int, array<string, mixed>> $aDemander
     * @param array<int, string>               $defauts
     *
     * @return array{id: ?int, libelle: ?string}
     */
    private function resoudrePiste(array $args, AiScope $scope, array &$aDemander, array &$defauts): array
    {
        $piste = trim((string) ($args['piste'] ?? ''));
        $client = trim((string) ($args['client'] ?? ''));
        $labels = $this->accessResolver->libellesEntites();

        // Identifiant déjà connu du modèle (issu d'une fiche attachée, par exemple).
        if ($piste !== '' && ctype_digit($piste)) {
            return ['id' => (int) $piste, 'libelle' => null];
        }

        if ($piste !== '') {
            $resolue = $this->resoudreEnregistrement('Piste', $piste, $scope, $aDemander);

            return ['id' => $resolue['id'], 'libelle' => $resolue['libelle']];
        }

        if ($client === '') {
            $aDemander[] = [
                'champ'    => 'Piste',
                'libelle'  => $labels['Piste'] ?? 'Opportunités',
                'probleme' => 'absent',
            ];

            return ['id' => null, 'libelle' => null];
        }

        $clientResolu = $this->resoudreEnregistrement('Client', $client, $scope, $aDemander);
        if ($clientResolu['id'] === null) {
            return ['id' => null, 'libelle' => null];
        }

        $pistes = $this->resolveur->chercherLies('Piste', 'client', (int) $clientResolu['id'], $scope);
        if ($pistes === []) {
            $aDemander[] = [
                'champ'    => 'Piste',
                'libelle'  => $labels['Piste'] ?? 'Opportunités',
                'probleme' => 'aucune_pour_ce_client',
                'terme'    => $clientResolu['libelle'],
            ];

            return ['id' => null, 'libelle' => null];
        }
        if (count($pistes) > 1) {
            $aDemander[] = [
                'champ'    => 'Piste',
                'libelle'  => $labels['Piste'] ?? 'Opportunités',
                'probleme' => 'ambigu',
                'terme'    => $clientResolu['libelle'],
                'valeurs'  => $pistes,
            ];

            return ['id' => null, 'libelle' => null];
        }

        $id = array_key_first($pistes);
        $defauts[] = sprintf(
            'Opportunité : « %s » — la seule du client « %s ».',
            reset($pistes),
            $clientResolu['libelle'],
        );

        return ['id' => $id, 'libelle' => reset($pistes)];
    }

    /**
     * Rattache chaque composante dictée au référentiel Chargement de l'entreprise.
     *
     * Sans « type », la commission du courtier ne se calcule pas et la prime reste à
     * 0 (cf. la note de la trame) : une composante non rattachée est donc une
     * question, jamais une ligne qu'on écrit quand même.
     *
     * @param array<int, array<string, mixed>> $aDemander
     *
     * @return array<int, array<string, mixed>>
     */
    private function resoudreComposition(mixed $composition, AiScope $scope, array &$aDemander): array
    {
        if (!is_array($composition) || $composition === []) {
            $aDemander[] = ['champ' => 'composition', 'libelle' => 'Composition de la prime', 'probleme' => 'absent'];

            return [];
        }

        $referentiel = $this->referentiels->codes('Chargement', $scope) ?? [];
        $etape = $this->libellesEtapes()['composition-prime'];
        $elements = [];

        foreach ($composition as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }
            $nom = trim((string) ($ligne['nom'] ?? ''));
            $montant = $ligne['montant'] ?? null;
            if ($nom === '' || !is_numeric($montant)) {
                continue;
            }

            [$typeId, $candidats] = $this->rattacherChargement($nom, $referentiel);
            if ($typeId === null) {
                $aDemander[] = [
                    'champ'    => 'composition',
                    'libelle'  => sprintf('Type de chargement pour « %s »', $nom),
                    'probleme' => $candidats === [] ? 'introuvable' : 'ambigu',
                    'terme'    => $nom,
                    'valeurs'  => $candidats === [] ? $referentiel : $candidats,
                ];
                continue;
            }

            $elements[] = [
                'op'     => 'create',
                'etape'  => $etape,
                'champs' => [
                    'nom'                    => $nom,
                    'type'                   => $typeId,
                    'montantFlatExceptionel' => (float) $montant,
                ],
            ];
        }

        return $elements;
    }

    /**
     * Rattachement d'un libellé dicté à une ligne du référentiel, par ordre de
     * certitude décroissante : correspondance exacte normalisée, puis table de
     * synonymes, puis inclusion.
     *
     * L'INCLUSION NE TRANCHE PAS À PLUSIEURS. C'est la règle la plus lâche, et la
     * confrontation au référentiel réel l'a montrée dangereuse : « Tva » retrouve
     * bien « Tva pour prime », mais un référentiel portant aussi « Tva sur
     * commission » aurait vu le PREMIER dans l'ordre d'itération l'emporter — un
     * chargement rangé sous le mauvais type, silencieusement, avec une commission
     * fausse au bout. Dès qu'il y a plusieurs candidats, on rend la main : la
     * question coûte une ligne, l'erreur coûte un chiffre faux.
     *
     * @param array<int, string> $referentiel id => libellé
     *
     * @return array{0: ?int, 1: array<int, string>} l'id retenu, ou null + les
     *                                               candidats à faire trancher
     */
    private function rattacherChargement(string $nom, array $referentiel): array
    {
        $cible = $this->resolveur->normaliser($nom);

        foreach ($referentiel as $id => $libelle) {
            if ($this->resolveur->normaliser((string) $libelle) === $cible) {
                return [(int) $id, []];
            }
        }

        foreach (self::SYNONYMES_CHARGEMENT as $canonique => $synonymes) {
            if (!in_array($cible, array_map($this->resolveur->normaliser(...), $synonymes), true)) {
                continue;
            }
            foreach ($referentiel as $id => $libelle) {
                if ($this->resolveur->normaliser((string) $libelle) === $this->resolveur->normaliser($canonique)) {
                    return [(int) $id, []];
                }
            }
        }

        $candidats = [];
        foreach ($referentiel as $id => $libelle) {
            $normalise = $this->resolveur->normaliser((string) $libelle);
            if ($normalise !== '' && (str_contains($normalise, $cible) || str_contains($cible, $normalise))) {
                $candidats[(int) $id] = (string) $libelle;
            }
        }

        return count($candidats) === 1 ? [array_key_first($candidats), []] : [null, $candidats];
    }

    /**
     * Échéancier : soit les tranches dictées, soit un découpage ÉGAL en N tranches
     * échelonnées de mois en mois à partir de la prise d'effet. Les pourcentages sont
     * en POINTS, convention unique de la plateforme (50 = 50 %).
     *
     * @param array<int, string> $defauts
     *
     * @return array<int, array<string, mixed>>
     */
    private function construireTranches(array $args, \DateTimeImmutable $debut, int $duree, string $etape, array &$defauts): array
    {
        $detaillees = $args['tranches'] ?? null;
        if (is_array($detaillees) && $detaillees !== []) {
            $elements = [];
            $rang = 0;
            $datesDerivees = false;
            foreach ($detaillees as $tranche) {
                if (!is_array($tranche)) {
                    continue;
                }
                // La DATE se dérive aussi ici, et ne se demande pas. « Payable en deux
                // tranches de chacune 50 % » est une phrase complète pour un courtier :
                // le modèle traduit alors en tranches DÉTAILLÉES sans date, et exiger
                // une exigibilité par tranche relancerait l'aller-retour que cet outil
                // existe pour supprimer. L'échelonnement mensuel est le défaut du
                // métier ; il est annoncé, donc corrigible d'une phrase.
                $date = $this->formatDate(trim((string) ($tranche['payableAt'] ?? '')));
                if ($date === null) {
                    $date = $this->formatDate($debut->modify(sprintf('+%d month', $rang)));
                    $datesDerivees = true;
                }
                $rang++;
                $champs = array_filter([
                    'nom'         => trim((string) ($tranche['nom'] ?? '')) ?: sprintf('Tranche %d', $rang),
                    'pourcentage' => isset($tranche['pourcentage']) && is_numeric($tranche['pourcentage'])
                        ? (float) $tranche['pourcentage'] : null,
                    'montantFlat' => isset($tranche['montant']) && is_numeric($tranche['montant'])
                        ? (float) $tranche['montant'] : null,
                    'payableAt'   => $date,
                ], static fn ($v) => $v !== null);
                $elements[] = ['op' => 'create', 'etape' => $etape, 'champs' => $champs];
            }

            if ($datesDerivees) {
                $defauts[] = sprintf(
                    'Échéancier : %d tranche(s) échelonnée(s) de mois en mois à partir du %s (aucune date dictée).',
                    count($elements),
                    $debut->format('d/m/Y'),
                );
            }

            return $elements;
        }

        // UNE PRIME EST PAYABLE EN UNE FOIS, sauf mention contraire. Le fractionnement
        // est l'exception que l'utilisateur dicte, avec ses pourcentages ; ne rien créer
        // laissait la proposition sans échéancier, donc sans rien d'exigible — et sans
        // exigible, pas de commission encaissable.
        $nombre = max(1, (int) ($args['nombreTranches'] ?? 0));

        if ($nombre === 1) {
            $defauts[] = sprintf(
                'Échéancier : prime payable en une seule fois (100 %%) le %s (aucun fractionnement dicté).',
                $debut->format('d/m/Y'),
            );

            return [[
                'op'     => 'create',
                'etape'  => $etape,
                'champs' => [
                    'nom'         => 'Prime unique',
                    'pourcentage' => 100.0,
                    'payableAt'   => $this->formatDate($debut),
                ],
            ]];
        }

        // Répartition en points, le reliquat sur la PREMIÈRE tranche : trois tranches
        // font 33,34 + 33,33 + 33,33 et non 99,99. On ne perd pas un centime de prime
        // sur un arrondi.
        $part = round(100 / $nombre, 2);
        $reliquat = round(100 - $part * $nombre, 2);

        $elements = [];
        for ($i = 0; $i < $nombre; $i++) {
            $elements[] = [
                'op'     => 'create',
                'etape'  => $etape,
                'champs' => [
                    'nom'         => sprintf('Tranche %d/%d', $i + 1, $nombre),
                    'pourcentage' => $i === 0 ? round($part + $reliquat, 2) : $part,
                    'payableAt'   => $this->formatDate($debut->modify(sprintf('+%d month', $i))),
                ],
            ];
        }

        $defauts[] = sprintf(
            'Échéancier : %d tranche(s) d’égale valeur, échelonnée(s) de mois en mois à partir du %s.',
            $nombre,
            $debut->format('d/m/Y'),
        );

        return $elements;
    }

    /**
     * ASSIETTES de commission : chargementId => montant, tirées des composantes déjà
     * rattachées au référentiel. C'est ce qui permet à la dérivation de ne retenir que
     * les revenus dont la base existe RÉELLEMENT dans cette proposition — une
     * « Commission sur Fronting » sans ligne de fronting vaudrait 0.
     *
     * @param array<int, array<string, mixed>> $chargements
     *
     * @return array<int, float>
     */
    private function assiettes(array $chargements): array
    {
        $assiettes = [];
        foreach ($chargements as $chargement) {
            $type = $chargement['champs']['type'] ?? null;
            $montant = $chargement['champs']['montantFlatExceptionel'] ?? null;
            if (is_numeric($type) && is_numeric($montant)) {
                // Deux lignes sur le même type se cumulent : c'est bien une seule assiette.
                $assiettes[(int) $type] = (float) ($assiettes[(int) $type] ?? 0) + (float) $montant;
            }
        }

        return $assiettes;
    }

    /**
     * Le chargement qui sert de BASE DE COMMISSION dans le référentiel de l'entreprise
     * (« Prime nette »), quel que soit son libellé exact. Sert à savoir sur quel revenu
     * poser un taux exceptionnel dicté : une dérogation vise la commission principale,
     * pas les accessoires.
     */
    private function idBaseDeCommission(AiScope $scope): ?int
    {
        // Le référentiel est mémoïsé par ReferentielEnumerateur : aucune requête de plus.
        $referentiel = $this->referentiels->codes('Chargement', $scope) ?? [];
        [$id] = $this->rattacherChargement('prime nette', $referentiel);

        return $id;
    }

    /**
     * Met une date au format attendu par les FORMULAIRES de l'application.
     *
     * Les champs de date des tranches sont des DateTimeType en widget « single_text » :
     * ils refusent une date seule (« Veuillez saisir une date et une heure valides »)
     * et attendent la forme HTML5 date-heure. Le plan traverse ces formulaires — c'est
     * la parité avec l'écran —, donc il doit parler leur langue.
     *
     * @param \DateTimeImmutable|string $valeur date déjà construite, ou dictée en texte
     *
     * @return string|null null si la valeur est vide ou illisible (l'appelant décide
     *                     alors d'appliquer son défaut)
     */
    private function formatDate(\DateTimeImmutable|string $valeur): ?string
    {
        if ($valeur instanceof \DateTimeImmutable) {
            return $valeur->format('Y-m-d\TH:i');
        }
        if (trim($valeur) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($valeur))->format('Y-m-d\TH:i');
        } catch (\Exception) {
            return null;
        }
    }

    /** Date de prise d'effet dictée, ou celle du jour (défaut annoncé). */
    private function dateDebut(array $args, array &$defauts): \DateTimeImmutable
    {
        $donnee = trim((string) ($args['debutCouverture'] ?? ''));
        if ($donnee !== '') {
            try {
                return new \DateTimeImmutable($donnee);
            } catch (\Exception) {
                // Date illisible : on retombe sur le jour même plutôt que d'échouer,
                // et le défaut est annoncé comme les autres.
            }
        }

        $aujourdhui = new \DateTimeImmutable('today');
        $defauts[] = sprintf('Prise d’effet : %s (aucune date dictée).', $aujourdhui->format('d/m/Y'));

        return $aujourdhui;
    }

    /** Nom de la proposition, à défaut construit sur l'assureur — jamais laissé vide. */
    private function nomProposition(array $args, array $assureur): string
    {
        $nom = trim((string) ($args['nom'] ?? ''));

        return $nom !== '' ? $nom : sprintf('Proposition %s', $assureur['libelle']);
    }

    /**
     * Libellés d'étape de la trame « proposition », par clé. Ils DOIVENT venir du
     * catalogue : ce sont eux qui font la ventilation du budget et les cases à
     * décocher de la barre de validation.
     *
     * @return array<string, string>
     */
    private function libellesEtapes(): array
    {
        $trame = ParcoursCatalogue::trame(self::PARCOURS) ?? [];
        $libelles = [];
        foreach ($trame['etapes'] ?? [] as $etape) {
            $libelles[(string) $etape['cle']] = (string) $etape['libelle'];
        }

        return $libelles + [
            'cotation'          => 'La proposition (cotation)',
            'composition-prime' => 'La composition de la prime',
            'echeancier'        => 'L’échéancier (tranches de paiement)',
            'revenu-courtier'   => 'Le revenu du courtier (commission)',
        ];
    }

    /**
     * Refus structuré : ce qui manque, avec les valeurs réellement disponibles.
     * Une seule question groupée côté modèle — jamais une par tour.
     *
     * @param array<int, array<string, mixed>> $aDemander
     */
    private function demander(array $aDemander): AiToolResult
    {
        return AiToolResult::ok([
            'pret'      => false,
            'aDemander' => $aDemander,
            'note'      => 'Je n’ai pas pu résoudre tout ce qu’il faut, et je ne devine pas une donnée du '
                . 'courtier. Pose à l’utilisateur, EN UN SEUL MESSAGE et en liste courte, les questions '
                . 'correspondant à « aDemander » : pour un « introuvable » ou un « ambigu », PROPOSE les '
                . 'options listées dans « valeurs » plutôt qu’une question ouverte. Puis rappelle '
                . 'saisir_proposition avec la réponse. N’appelle NI rechercher_entites NI inventaire_champs : '
                . 'les valeurs possibles sont déjà ci-dessus.',
        ]);
    }

    /**
     * Joint au plan les défauts appliqués et les résolutions faites côté serveur.
     * L'utilisateur doit pouvoir lire, dans la réponse, ce que personne ne lui a
     * demandé — c'est la contrepartie de l'économie de questions.
     *
     * @param array<int, string>       $defauts
     * @param array{id: ?int, libelle: string} $assureur
     * @param array{id: ?int, libelle: ?string} $piste
     * @param array<int, string>       $avertissements ce qui n'a PAS pu être dérivé
     */
    private function avecDefauts(
        AiToolResult $resultat,
        array $defauts,
        array $assureur,
        array $piste,
        array $avertissements = [],
    ): AiToolResult {
        if (($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        $resolutions = [sprintf('Assureur : « %s » (résolu depuis « %s »).', $assureur['libelle'], $assureur['terme'])];
        if ($piste['libelle'] !== null) {
            $resolutions[] = sprintf('Opportunité : « %s ».', $piste['libelle']);
        }

        $data = $resultat->data;
        // Les avertissements du plan sont ceux de PlanBuilder : on s'AJOUTE à la liste,
        // on ne la remplace pas — l'union « + » de PHP garderait la sienne en silence.
        if ($avertissements !== []) {
            $data['avertissements'] = array_merge($data['avertissements'] ?? [], $avertissements);
        }

        // Les DÉDUCTIONS DE PLANBUILDER (nom d'une piste, durée lue sur la période…)
        // arrivent déjà dans `defauts` : l'union « + » de PHP garderait alors la
        // sienne et TAIRAIT les hypothèses de cet outil, exactement comme pour les
        // avertissements ci-dessus. On compose les deux listes — les unes et les
        // autres sont des choix faits à la place de l'utilisateur, et toutes doivent
        // lui être annoncées.
        $data['defauts'] = array_merge($data['defauts'] ?? [], $defauts);

        return AiToolResult::ok(
            $data + [
                'resolutions' => $resolutions,
                'noteDefauts' => 'ANNONCE ces « resolutions » et ces « defauts » dans ta réponse, en une ou deux '
                    . 'lignes sous le plan : ce sont des choix faits À LA PLACE de l’utilisateur, il doit pouvoir '
                    . 'les corriger d’une phrase. Ne les présente jamais comme des informations qu’il aurait données.',
            ],
            uiAction: $resultat->uiAction,
        );
    }

}
