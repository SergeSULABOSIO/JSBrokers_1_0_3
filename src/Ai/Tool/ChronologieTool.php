<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\Colonnes;
use App\Ai\Resolution\CheminsDeRelation;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Outil de données : la CHRONOLOGIE d'un dossier — tout ce qui lui est arrivé, dans
 * l'ordre, du jour où il est entré dans le système à ses échéances à venir.
 *
 * ── L'INCIDENT QUI A CRÉÉ CET OUTIL (2026-08-11) ────────────────────────────────
 * Un courtier demande « mets les dates aussi ». Ket répond que « la date exacte de
 * création du compte et des avenants n'est pas renseignée dans le système ». C'était
 * FAUX : createdAt est NOT NULL et posé au PrePersist sur les 42 entités portant
 * AuditableTrait. La donnée existait depuis toujours — elle n'était simplement jamais
 * SÉRIALISÉE pour l'assistant, faute de #[Groups(['list:read'])] sur le trait, et aucun
 * outil ne savait la restituer.
 *
 * Une information invisible se raconte comme une information absente : c'est le même
 * mécanisme qui, la veille, avait fait inventer une colonne « Assureur Partenaire ».
 * Ce que Ket ne reçoit pas, elle le devine ou le nie — dans les deux cas, elle se trompe.
 *
 * ── DEUX DATES PAR FAIT, ET JAMAIS L'UNE POUR L'AUTRE ───────────────────────────
 * La date de SAISIE (createdAt) n'est pas la date où le fait s'est produit. Une police
 * saisie le 28/02 peut prendre effet le 01/03 et échoir un an plus tard ; un sinistre
 * survenu en janvier peut n'être déclaré qu'en mars. Chaque fait porte donc sa date
 * MÉTIER — c'est elle qui ordonne la chronologie — et, à part, sa date d'enregistrement.
 * Confondre les deux, c'est raconter l'histoire de la saisie en croyant raconter celle
 * du contrat.
 *
 * ── POURQUOI DES CHEMINS EXPLICITES, ET NON LE GRAPHE GÉNÉRIQUE ─────────────────
 * CheminsDeRelation, qui sert à filtrer une rubrique par un rattachement, ne convient
 * PAS ici, et le vérifier a évité un bug silencieux. Deux raisons, mesurées le
 * 2026-08-11 sur le graphe réel :
 *  - sa profondeur est bornée à 3 segments, or PaiementPrime rejoint le client en
 *    QUATRE (tranche.cotation.piste.client) : les règlements de prime — précisément ce
 *    que le courtier regardait — disparaissaient de la chronologie ;
 *  - pris depuis une POLICE, il ne trouve les tranches que par
 *    « cotation.piste.avenantDeBase », qui désigne la police dont une piste DÉRIVÉE est
 *    issue, pas la police visée. La chronologie aurait montré les tranches d'un
 *    renouvellement en les présentant comme celles du contrat d'origine.
 * Les chemins ci-dessous sont donc écrits et vérifiés un par un.
 *
 * ── UNE CHRONOLOGIE EST CELLE D'UN DOSSIER CLIENT ───────────────────────────────
 * L'ancre peut être n'importe quelle fiche, mais elle sert à DÉSIGNER le dossier : une
 * police, une piste ou un sinistre ramènent à leur client, et c'est l'histoire de ce
 * client qui est restituée. Le payload le dit explicitement (« dossier »), pour que Ket
 * n'annonce jamais « l'historique de cette police » là où elle tient celui du compte.
 *
 * ── PÉRIMÈTRE ──────────────────────────────────────────────────────────────────
 * Fail-closed sur l'ancre, puis SOURCE PAR SOURCE : une source hors périmètre est omise
 * ET signalée — une chronologie amputée qui ne le dit pas se lit comme une chronologie
 * complète. L'ancre étant désignée par son identifiant, le filtre portefeuille ne s'y
 * applique pas (même convention que lire_fiche).
 */
final class ChronologieTool implements AiToolInterface
{
    /** Au-delà, une chronologie cesse d'être un récit et devient un journal. */
    private const MAX_FAITS = 40;

    /** Enregistrements ramenés par source : au-delà, c'est une liste, pas une histoire. */
    private const MAX_PAR_SOURCE = 25;

    /**
     * Les sources d'une chronologie : nom court => [libellé de sa CRÉATION, dates MÉTIER,
     * chemins vérifiés vers le Client].
     *
     * L'ordre de ce tableau est celui du CYCLE DE PRODUCTION — compte, opportunité,
     * proposition, police, échéancier, encaissements, sinistres, suivi — et sert de
     * départage à dates égales : le compte précède la piste, qui précède la police.
     *
     * Ce qui n'y est PAS, et pourquoi : chargements de prime, revenus, conditions de
     * partage, types et référentiels portent bien un createdAt, mais décrivent la
     * MÉCANIQUE d'une police, pas sa vie. Les inclure noierait trois faits lisibles sous
     * treize lignes techniques.
     */
    private const SOURCES = [
        'Client' => ['Compte client créé', [], []],
        'Piste' => ['Opportunité ouverte', [], ['client']],
        'Cotation' => ['Proposition d\'assureur enregistrée', [], ['piste.client']],
        'Avenant' => [
            'Police enregistrée',
            ['getStartingAt' => 'Police prend effet', 'getEndingAt' => 'Police arrive à échéance'],
            // Deux chemins réels : la police ordinaire par sa cotation, la police issue
            // d'un renouvellement par sa piste dérivée (souvent nulle sur l'autre).
            ['cotation.piste.client', 'pisteDeRenouvellement.client'],
        ],
        'Tranche' => ['Échéancier créé', ['getEcheanceAt' => 'Échéance de prime'], ['cotation.piste.client']],
        'PaiementPrime' => [
            'Paiement de prime signalé',
            ['getPaidAt' => 'Prime réglée par l\'assuré'],
            // QUATRE segments : hors de portée du graphe générique, d'où cette écriture.
            ['tranche.cotation.piste.client'],
        ],
        'Paiement' => ['Encaissement enregistré', ['getPaidAt' => 'Encaissement du courtier'], ['note.client']],
        'NotificationSinistre' => [
            'Sinistre enregistré',
            ['getOccuredAt' => 'Sinistre survenu', 'getNotifiedAt' => 'Sinistre déclaré'],
            ['assure'],
        ],
        'Tache' => [
            'Tâche créée',
            ['getToBeEndedAt' => 'Tâche à clôturer'],
            ['piste.client', 'cotation.piste.client', 'notificationSinistre.assure'],
        ],
        'Note' => ['Note créée', ['getSentAt' => 'Note envoyée'], ['client']],
    ];

    /**
     * Comment NOMMER l'objet d'un fait, quand le libellé générique ne suffit pas.
     *
     * EntiteLibelle applique la même heuristique que l'autocomplétion de la recherche
     * (premier champ parmi nom, nomComplet, titre, libelle, intitule, reference, numero,
     * description). Sur un Avenant, aucun de ces champs n'est la référence de POLICE :
     * l'heuristique retombe sur « numero », et une chronologie annonçait « Police
     * enregistrée · 0 ». On ne touche pas à EntiteLibelle — il sert aussi à
     * rechercher_entites, où son comportement est établi — on le surcharge ici.
     */
    private const LIBELLE_OBJET = [
        'Avenant' => 'getReferencePolice',
    ];

    private readonly PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        // Sert UNIQUEMENT à ramener une ancre quelconque à son client : ce sens-là du
        // graphe est fiable (relations *-vers-un, profondeur suffisante).
        private readonly CheminsDeRelation $chemins,
        private readonly EntiteLibelle $libelleur,
        // Source unique de « un nom dicté → un identifiant » : la MÊME que celle
        // d'ouvrir_dialogue, sans quoi les deux outils répondent différemment sur la
        // même personne (incident du 2026-08-13).
        private readonly ResolveurDeReferences $resolveur,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    public function name(): string
    {
        return 'chronologie';
    }

    public function description(): string
    {
        return 'Retrace la CHRONOLOGIE d\'un dossier client : tout ce qui lui est arrivé, dans '
            . 'l\'ordre — ouverture du compte, opportunités, propositions, polices et leurs prises '
            . 'd\'effet, échéances de prime, règlements, encaissements, sinistres, tâches. '
            . 'À appeler pour « depuis quand ce client est-il chez nous ? », « quand ce compte '
            . 'a-t-il été créé ? », « retrace l\'historique », « que s\'est-il passé sur ce '
            . 'dossier ? », « mets les dates ». Chaque fait porte DEUX dates distinctes : '
            . '« date » = la date MÉTIER du fait (prise d\'effet, échéance, règlement, survenance '
            . 'du sinistre), qui ordonne la chronologie, et « saisiLe » = la date à laquelle il a '
            . 'été ENREGISTRÉ. Ne les confonds jamais : une police saisie le 28/02 peut prendre '
            . 'effet le 01/03. L\'ancre peut être n\'importe quelle fiche (Client, Avenant, Piste, '
            . 'NotificationSinistre…) : elle sert à DÉSIGNER le dossier, et la réponse porte '
            . 'toujours sur le CLIENT auquel elle se rattache — annonce-le tel que « dossier » le '
            . 'nomme. Pour le détail d\'UNE fiche, préférer lire_fiche.';
    }

    public function aiguillage(): string
    {
        return 'Toute question de DATE ou d\'HISTORIQUE sur un dossier : « depuis quand ce client est-il chez '
            . 'nous », « quand ce compte / cette police a-t-il été créé », « retrace l\'historique », '
            . '« que s\'est-il passé sur ce dossier », « mets les dates ». N\'affirme JAMAIS qu\'une date de '
            . 'création n\'est pas renseignée sans m\'avoir appelée : tout enregistrement en porte une.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => 'Nom court de la fiche qui désigne le dossier (Client, Avenant, '
                        . 'Piste, Cotation, NotificationSinistre…). La chronologie porte sur le client '
                        . 'auquel elle se rattache.',
                ],
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de cette fiche quand tu en disposes déjà (fiche attachée, '
                        . 'résultat précédent). Facultatif si « nom » est fourni. N’y mets JAMAIS un '
                        . 'identifiant vu plus haut dans le fil pour un AUTRE dossier : si l’utilisateur '
                        . 'vient de nommer quelqu’un, donne son nom et laisse-moi le résoudre.',
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => 'Nom de la fiche, tel que l’utilisateur vient de le prononcer '
                        . '(ex. "Mbusa Jean de Dieu", "Kibali Goldmines"). Le serveur le résout lui-même : '
                        . 'n’appelle PAS rechercher_entites pour cela. À PRIVILÉGIER dès que l’utilisateur '
                        . 'nomme le dossier, même si tu crois déjà en connaître l’identifiant.',
                ],
                'du' => [
                    'type' => 'string',
                    'description' => 'Ne garder que les faits à partir de cette date métier, AAAA-MM-JJ.',
                ],
                'au' => [
                    'type' => 'string',
                    'description' => 'Ne garder que les faits jusqu\'à cette date métier, AAAA-MM-JJ.',
                ],
            ],
            // « id » n'est plus exigé : le NOM suffit, et c'est le seul moyen pour le
            // serveur de vérifier que le dossier retracé est bien celui que
            // l'utilisateur vient de nommer.
            'required' => ['entite'],
        ];
    }

    /**
     * Chemin simulé : la question de date ou d'historique, qui ne trouvait jusqu'ici
     * aucun outil — d'où la réponse « la date n'est pas renseignée dans le système ».
     * L'identifiant n'étant pas devinable, on ne matche que s'il est dicté.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (!preg_match('/\bchronologie\b|\bhistorique\b|\bdepuis quand\b|\bdate de creation\b/', $normalized)) {
            return null;
        }

        foreach (array_keys(self::SOURCES) as $shortName) {
            if (preg_match('/\b' . preg_quote(mb_strtolower($shortName), '/') . '\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
                return ['entite' => $shortName, 'id' => (int) $m[1]];
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();
        $ancreNom = (string) ($args['entite'] ?? '');
        $ancreId = (int) ($args['id'] ?? 0);
        $ancreFqcn = 'App\\Entity\\' . $ancreNom;

        if (!isset($labels[$ancreNom]) || !class_exists($ancreFqcn)) {
            return AiToolResult::introuvable($ancreNom);
        }

        // FAIL-CLOSED sur l'ancre ET sur le client : la chronologie porte sur le dossier,
        // et le désigner par une police ne doit pas contourner le droit de lire le compte.
        foreach ([$ancreNom, 'Client'] as $requis) {
            if (!$this->accessResolver->canRead($scope->invite, $requis)) {
                return AiToolResult::horsPerimetre($labels[$requis] ?? $requis);
            }
        }

        // LE NOM PRIME SUR L'IDENTIFIANT REPORTÉ. Cet outil était le dernier à EXIGER
        // un id, alors que la doctrine du projet est qu'un outil résout lui-même ce
        // qu'on lui dicte (ResolveurDeReferences). La conséquence s'est vue en
        // production le 2026-08-13 : à « non, je parle de Mr. Mbusa Jean de Dieu », le
        // modèle n'avait aucun moyen de faire résoudre ce nom ; il a donc reporté
        // l'identifiant du dossier PRÉCÉDENT (Kibali Goldmines) et retracé sa
        // chronologie sous le nom de Mbusa. L'utilisateur a lu l'historique d'un tiers
        // en croyant lire le sien — et au message suivant, ouvrir_dialogue, lui,
        // résolvait par nom et répondait « introuvable » : deux outils, deux réponses
        // contradictoires sur la même personne.
        //
        // Le nom l'emporte donc quand il est fourni : un identifiant reporté est une
        // hypothèse, un nom prononcé est une donnée.
        $nom = trim((string) ($args['nom'] ?? ''));
        if ($nom !== '') {
            $reference = $this->resolveur->resoudre($ancreNom, $nom, $scope);
            if (!$reference->estResolue()) {
                // Introuvable ou ambigu : une QUESTION, pas une chronologie approximative.
                return AiToolResult::ok([
                    'pret'      => false,
                    'aDemander' => [$reference->question()],
                    'note'      => sprintf(
                        'AUCUNE chronologie n’a été établie : « %s » ne désigne pas un enregistrement '
                        . 'unique. Pose la question telle quelle, en UNE ligne. N’invente aucun '
                        . 'identifiant, ne REPRENDS PAS celui d’un dossier cité plus haut dans le fil, '
                        . 'et ne présente sous ce nom l’historique de personne d’autre.',
                        $nom,
                    ),
                ]);
            }
            $ancreId = (int) $reference->id;
        }

        if ($ancreId < 1) {
            return AiToolResult::introuvable($labels[$ancreNom]);
        }

        $resultat = $this->searchService->search($ancreFqcn, ['id' => $ancreId], $scope->entreprise, null, 1, 1);
        $ancre = $resultat['data'][0] ?? null;
        if (($resultat['status']['code'] ?? 500) !== 200 || !\is_object($ancre)) {
            return AiToolResult::introuvable(sprintf('%s #%d', $labels[$ancreNom], $ancreId));
        }

        $client = $this->clientDe($ancre, $ancreFqcn);
        if ($client === null) {
            // On DIT ce qui manque plutôt que de rendre une chronologie vide : sans client,
            // il n'y a pas de dossier à raconter, et ce n'est pas la même chose que « rien
            // ne s'est passé ».
            return AiToolResult::ok([
                'bloquant' => sprintf(
                    'Cette fiche (%s #%d) n’est rattachée à aucun client : je ne peux pas en retracer le '
                        . 'dossier. Indiquez-moi le client concerné et je reprends.',
                    $labels[$ancreNom],
                    $ancreId,
                ),
            ]);
        }

        $du = $this->dateValide($args['du'] ?? null);
        $au = $this->dateValide($args['au'] ?? null);

        $faits = $this->faitsDe('Client', $client);
        $sourcesOmises = [];

        foreach (self::SOURCES as $shortName => [, , $cheminsVersClient]) {
            if ($cheminsVersClient === []) {
                continue; // le Client lui-même, déjà traité
            }
            if (!isset($labels[$shortName]) || !class_exists('App\\Entity\\' . $shortName)) {
                continue;
            }
            // Fail-closed PAR SOURCE : on reste utile sur le reste, et on le dit.
            if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
                $sourcesOmises[] = $labels[$shortName];
                continue;
            }

            foreach ($this->enregistrementsLies($shortName, $cheminsVersClient, (int) $client->getId(), $scope) as $entite) {
                foreach ($this->faitsDe($shortName, $entite) as $fait) {
                    $faits[] = $fait;
                }
            }
        }

        $faits = $this->filtrerParPeriode($faits, $du, $au);
        $total = \count($faits);
        [$faits, $omisAuMilieu] = $this->borner($this->ordonner($faits));

        return AiToolResult::ok(array_filter([
            'dossier' => sprintf('%s — %s', $labels['Client'] ?? 'Client', $this->objet('Client', $client)),
            'ancre' => $ancreNom === 'Client'
                ? null
                : sprintf('%s — %s', $labels[$ancreNom], $this->objet($ancreNom, $ancre)),
            'du' => $du,
            'au' => $au,
            'lignes' => $faits,
            'presentation' => $faits === [] ? null : Colonnes::de([
                'date' => Colonnes::DATE,
                'fait' => Colonnes::TEXTE,
                'objet' => Colonnes::TEXTE,
                'saisiLe' => Colonnes::DATE,
                'par' => Colonnes::TEXTE,
            ], []),
            'total' => $total,
            'faitsOmis' => $omisAuMilieu > 0 ? $omisAuMilieu : null,
            'sourcesOmises' => $sourcesOmises === [] ? null : $sourcesOmises,
            'note' => 'DEUX DATES PAR FAIT, jamais l\'une pour l\'autre : « date » est la date MÉTIER '
                . '(prise d\'effet, échéance, règlement, survenance) et c\'est elle qui ordonne la '
                . 'chronologie ; « saisiLe » est la date d\'ENREGISTREMENT dans le système. Un fait dont '
                . 'la date métier est future (une échéance à venir) figure normalement en fin de liste — '
                . 'ce n\'est pas une anomalie. La chronologie porte sur le DOSSIER nommé par « dossier » : '
                . 'ne l\'annonce jamais comme l\'historique de la seule fiche interrogée. '
                // Le 2026-08-13, la prose a titré « Le dossier du client M. Mbusa Jean de
                // Dieu (rattaché à Kibali Goldmines SA) » : le nom soufflé par l'utilisateur
                // promu en sujet, et le libellé qui fait foi relégué entre parenthèses. Le
                // lecteur croit alors lire l'historique d'une personne dont la base ne sait
                // rien. Le nom du dossier n'est pas une formulation, c'est une donnée.
                . 'NOMME CE DOSSIER EXACTEMENT COMME « dossier » le nomme, mot pour mot. '
                . 'N\'y substitue pas le nom que l\'utilisateur a prononcé, ne le complète pas, '
                . 'et n\'en fais pas une personne « rattachée » à ce libellé : si le dossier '
                . 'trouvé ne porte pas le nom attendu par l\'utilisateur, DIS-LUI que c\'est '
                . 'celui-là que tu as lu, et demande-lui s\'il visait quelqu\'un d\'autre.'
                . ($sourcesOmises === []
                    ? ''
                    : ' Chronologie PARTIELLE : les sources listées dans « sourcesOmises » sont hors du '
                        . 'périmètre de l\'utilisateur — dis-le au lieu de présenter cette liste comme complète.'),
        ], static fn ($v) => $v !== null && $v !== []));
    }

    // ───────────────────────────────── Le dossier ───────────────────────────────────

    /**
     * Le client dont cette fiche relève. Une ancre Client se rend elle-même ; pour toute
     * autre, on emprunte le premier chemin *-vers-un qui aboutit RÉELLEMENT sur une
     * instance — plusieurs chemins existent souvent, et le plus court est parfois celui
     * qui est nul (une police ordinaire n'a pas de piste de renouvellement).
     */
    private function clientDe(object $ancre, string $ancreFqcn): ?Client
    {
        if ($ancre instanceof Client) {
            return $ancre;
        }

        foreach ($this->chemins->vers($ancreFqcn, Client::class) as $chemin) {
            try {
                // Un chemin pointillé EST une expression de PropertyAccess.
                $valeur = $this->accessor->getValue($ancre, $chemin);
            } catch (\Throwable) {
                continue; // chemin illisible sur cette instance : on essaie le suivant
            }
            if ($valeur instanceof Client) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * Les enregistrements d'une source rattachés au client, tous chemins combinés en OR
     * par le moteur.
     *
     * @param list<string> $cheminsVersClient
     *
     * @return list<object>
     */
    private function enregistrementsLies(string $shortName, array $cheminsVersClient, int $clientId, AiScope $scope): array
    {
        $resultat = $this->searchService->search(
            'App\\Entity\\' . $shortName,
            [JSBDynamicSearchService::LIEN_MULTI_CHEMINS => ['paths' => $cheminsVersClient, 'id' => $clientId]],
            $scope->entreprise,
            null,
            1,
            self::MAX_PAR_SOURCE,
        );

        return ($resultat['status']['code'] ?? 500) === 200 ? array_values($resultat['data']) : [];
    }

    // ───────────────────────────────────── Faits ────────────────────────────────────

    /**
     * Les faits que porte UN enregistrement : sa création, puis ses dates métier.
     *
     * Un enregistrement produit donc plusieurs lignes — une police en produit trois
     * (enregistrée, prend effet, arrive à échéance), et c'est exactement ce qui fait une
     * chronologie plutôt qu'une liste.
     *
     * @return list<array<string, mixed>>
     */
    private function faitsDe(string $shortName, object $entite): array
    {
        [$libelleCreation, $datesMetier] = self::SOURCES[$shortName] ?? ['Enregistrement créé', []];

        $objet = $this->objet($shortName, $entite);
        $creeLe = $this->date($entite, 'getCreatedAt');
        $par = method_exists($entite, 'getInvite') ? $entite->getInvite()?->getNom() : null;
        $rang = array_search($shortName, array_keys(self::SOURCES), true);

        $faits = [];

        // La CRÉATION. Sa date métier EST sa date de saisie : c'est le seul cas où les deux
        // coïncident, et il faut qu'elles le fassent — sinon « quand ce compte a-t-il été
        // créé ? » reste sans réponse, ce qui est le défaut d'origine.
        if ($creeLe !== null) {
            $faits[] = $this->fait($creeLe, $libelleCreation, $objet, $creeLe, $par, $rang);
        }

        foreach ($datesMetier as $getter => $libelle) {
            $date = $this->date($entite, $getter);
            if ($date !== null) {
                $faits[] = $this->fait($date, $libelle, $objet, $creeLe, $par, $rang);
            }
        }

        return $faits;
    }

    /** @return array<string, mixed> */
    private function fait(string $date, string $libelle, string $objet, ?string $saisiLe, ?string $par, int|false $rang): array
    {
        return array_filter([
            'date' => $date,
            'fait' => $libelle,
            'objet' => $objet,
            'saisiLe' => $saisiLe,
            'par' => $par,
            '__rang' => $rang === false ? 99 : $rang,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    // ──────────────────────────────── Tri et bornage ────────────────────────────────

    /**
     * Ordre chronologique croissant. À date égale, l'ordre du CYCLE DE PRODUCTION
     * départage : le compte précède l'opportunité, qui précède la police. Sans ce second
     * critère, trois faits saisis le même jour s'affichent dans un ordre arbitraire et le
     * récit devient incompréhensible.
     *
     * @param list<array<string, mixed>> $faits
     *
     * @return list<array<string, mixed>>
     */
    private function ordonner(array $faits): array
    {
        usort(
            $faits,
            static fn (array $a, array $b) => [$a['date'], $a['__rang'] ?? 99] <=> [$b['date'], $b['__rang'] ?? 99],
        );

        return array_map(
            static function (array $fait): array {
                unset($fait['__rang']);

                return $fait;
            },
            $faits,
        );
    }

    /**
     * Bornage par les DEUX bouts, et non par la fin.
     *
     * Une chronologie tronquée par le début perdrait son premier fait — « le compte a été
     * ouvert le… » —, c'est-à-dire précisément ce qu'on venait chercher. On garde donc
     * l'ouverture, puis les faits les plus RÉCENTS, et on annonce le trou.
     *
     * @param list<array<string, mixed>> $faits
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function borner(array $faits): array
    {
        $total = \count($faits);
        if ($total <= self::MAX_FAITS) {
            return [$faits, 0];
        }

        return [
            array_merge(\array_slice($faits, 0, 1), \array_slice($faits, -(self::MAX_FAITS - 1))),
            $total - self::MAX_FAITS,
        ];
    }

    /**
     * @param list<array<string, mixed>> $faits
     *
     * @return list<array<string, mixed>>
     */
    private function filtrerParPeriode(array $faits, ?string $du, ?string $au): array
    {
        if ($du === null && $au === null) {
            return $faits;
        }

        return array_values(array_filter(
            $faits,
            static fn (array $f) => ($du === null || $f['date'] >= $du) && ($au === null || $f['date'] <= $au),
        ));
    }

    // ───────────────────────────────────── Outils ───────────────────────────────────

    private function date(object $entite, string $getter): ?string
    {
        if (!method_exists($entite, $getter)) {
            return null;
        }

        $valeur = $entite->{$getter}();

        return $valeur instanceof \DateTimeInterface ? $valeur->format('Y-m-d') : null;
    }

    private function libelle(string $fqcn, object $entite): string
    {
        return $this->libelleur->libelle($entite, $this->libelleur->displayField($fqcn));
    }

    /** Le nom de l'objet d'un fait : la surcharge si elle existe, le libellé générique sinon. */
    private function objet(string $shortName, object $entite): string
    {
        $getter = self::LIBELLE_OBJET[$shortName] ?? null;
        if ($getter !== null && method_exists($entite, $getter)) {
            $valeur = trim((string) ($entite->{$getter}() ?? ''));
            if ($valeur !== '') {
                return $valeur;
            }
        }

        return $this->libelle('App\\Entity\\' . $shortName, $entite);
    }

    /** Date AAAA-MM-JJ validée, ou null (une valeur mal formée est simplement ignorée). */
    private function dateValide(mixed $valeur): ?string
    {
        $valeur = trim((string) ($valeur ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1 ? $valeur : null;
    }
}
