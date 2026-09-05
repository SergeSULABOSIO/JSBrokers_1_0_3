<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiEngineFailure;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Comprehension\ClarificationEnAttente;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Mutation\MotifDeRefus;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Redaction\RepliPrecis;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moteur réel alternatif : API Google Gemini (generateContent) via
 * symfony/http-client, avec function calling — équivalent Gemini du
 * tool-calling de l'adaptateur Claude (AnthropicAiEngine) : le modèle décide
 * d'appeler nos outils métier, la boucle functionCall/functionResponse est
 * bornée, et TOUS les résultats d'un tour sont renvoyés dans UN message user.
 *
 * Différences de dialecte avec Claude gérées ici : rôles user/model (pas
 * assistant), prompt système dans systemInstruction, outils déclarés en
 * functionDeclarations (nos schema() JSON-Schema se mappent directement),
 * clé transmise par en-tête x-goog-api-key (jamais dans l'URL).
 *
 * SÉCURITÉ : identique aux autres moteurs — le périmètre ne dépend PAS du
 * modèle, chaque outil re-vérifie canRead() dans execute() (fail-closed).
 */
final class GeminiAiEngine implements AiEngineInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    /** Assez ample pour restituer une page de liste (rechercher_entites) sans troncature. */
    private const MAX_OUTPUT_TOKENS = 4096;
    /**
     * UN SEUL TOUR D'OUTILS PAR MESSAGE. Ce n'est pas un réglage, c'est la règle
     * d'architecture : **le modèle n'orchestre plus rien, PHP orchestre**.
     *
     * Un message coûte donc DEUX appels, et deux seulement :
     *  1. le modèle reçoit la bulle et émet ses appels d'outils — plusieurs à la
     *     fois s'il le veut, ce qui est du PARALLÈLE, pas de l'orchestration ;
     *  2. le serveur exécute tout, puis un dernier appel sert à FORMULER la réponse.
     *
     * POURQUOI ON NE PEUT PAS SE CONTENTER D'UN PLAFOND PLUS HAUT. L'API étant sans
     * mémoire, chaque tour réexpédie l'intégralité du contexte. Mesuré le
     * 2026-08-10 sur une conversation réelle : un message a enchaîné CINQ tours de
     * rechercher_entites à 37 700 tokens et consommé 188 000 tokens — presque toute
     * la fenêtre d'une minute (212 500). Les messages suivants, « salut » compris,
     * se sont tous heurtés à une fenêtre vide. Un seul message peut donc rendre la
     * conversation entière inutilisable : le nombre de tours n'est pas un curseur de
     * performance, c'est un risque de panne.
     *
     * CE QUI REND CE PLAFOND TENABLE : les outils résolvent eux-mêmes les noms en
     * identifiants (cf. ResolveurDeReferences). Le modèle n'a plus d'identifiant à
     * aller chercher, donc plus de raison d'enchaîner. Quand le serveur ne peut pas
     * trancher, l'outil renvoie « aDemander » et Ket pose UNE question groupée —
     * un tour de conversation vaut mieux que cinq tours de moteur.
     */
    private const MAX_TOOL_ROUNDS = 1;

    /**
     * Attente maximale AVANT DE RELANCER UN TOUR, quand la fenêtre d'une minute
     * est saturée mais sur le point de se libérer.
     *
     * L'API est sans mémoire : chaque tour réexpédie l'INTÉGRALITÉ du contexte
     * (prompt système + déclarations d'outils + historique + résultats déjà
     * obtenus ≈ 130 Ko, soit ~35 000 tokens). Abandonner au 4e tour, c'est donc
     * jeter quatre tours DÉJÀ facturés à l'utilisateur (meterWrite débite avant
     * l'appel) et lui rendre une non-réponse. Patienter quelques secondes coûte
     * infiniment moins cher que recommencer.
     *
     * PLAFOND BAS. La raison d'origine était le développement : « symfony serve »
     * ne dispose que d'UN worker php-cgi, et une requête qui dort y figeait toute
     * l'application, rechargement de page compris.
     *
     * ⚠️ CETTE RAISON A DISPARU, LE PLAFOND RESTE. Le traitement vit désormais
     * dans un worker, où dormir ne gèle plus rien. La tentation de relever ces
     * bornes est donc réelle — et c'est un AUTRE sujet : ce serait changer le
     * comportement de repli face au quota du fournisseur, décision à prendre sur
     * des mesures, pas au détour d'une refonte de transport. La seconde raison,
     * elle, tient toujours : au-delà d'une trentaine de secondes, l'utilisateur
     * préfère une réponse honnête à une attente muette.
     */
    private const MAX_ATTENTE_SECONDES = 15;

    /** Attente cumulée tolérée sur un message entier (garde-fou de temps de réponse). */
    private const MAX_ATTENTE_CUMULEE_SECONDES = 25;

    /**
     * Ratio octets → tokens MESURÉ sur la campagne (3,52 le 2026-08-08), et non
     * supposé. Sert à estimer ce que coûtera le tour suivant avant de le lancer.
     */
    private const OCTETS_PAR_TOKEN = 3.5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        // Source unique des outils déclarés — la MÊME que celle dont le prompt tire
        // sa section d'aiguillage.
        private readonly TrousseCatalogue $trousseCatalogue,
        // Les particularités du proto Gemini (déclarations assainies, objets vides
        // préservés), partagées avec la phase de compréhension.
        private readonly DialecteGemini $dialecte,
        private readonly SelecteurDeTrousse $selecteur,
        // Le seul chemin vers le code métier, partagé avec l'autre moteur et avec la
        // phase de compréhension : la méthode vivait en trois exemplaires identiques.
        private readonly ExecuteurDOutils $executeur,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')] private readonly string $model,
        // MODÈLES DE SECOURS, séparés par des virgules. Vide = aucun repli.
        //
        // Ils ne servent QUE sur un 503 : le modèle principal est débordé chez Google,
        // et aucune attente raisonnable n'y change quoi que ce soit. Le compteur de
        // débit de Google étant tenu PAR MODÈLE, un modèle de secours arrive avec sa
        // propre fenêtre — c'est ce qui rend le repli utile et non cosmétique.
        #[Autowire(env: 'GEMINI_MODELES_REPLI')] private readonly string $modelesDeRepli,
        private readonly LoggerInterface $logger,
        private readonly JournalTokens $journal,
        private readonly BudgetDebit $budget,
        // Rédige en PHP, à coût nul, ce que le modèle n'a pas rédigé — à partir des
        // résultats d'outils déjà obtenus. Remplace l'ancienne phrase générique.
        private readonly RepliPrecis $repliPrecis,
        // Rattrape l'appel d'outil que le modèle a ÉCRIT au lieu de l'émettre.
        private readonly AppelDOutilEnTexte $appelEnTexte,
        // Source unique des outils qui produisent un plan : sert à signaler au
        // contrôleur qu'un tel outil a tourné SANS produire de plan.
        private readonly OutilsDePlan $outilsDePlan,
        // PREMIÈRE PHASE : établir ce que l'utilisateur veut avant de décider quoi
        // que ce soit. Fail-open par construction — s'il ne conclut pas, la demande
        // part telle quelle et la planification retrouve son comportement d'avant.
        private readonly Comprehenseur $comprehenseur,
        // Injectable pour les tests : ils vérifient la DÉCISION d'attendre, pas
        // la capacité de PHP à dormir. Une suite qui dort n'est plus une suite.
        private readonly ?\Closure $dormir = null,
    ) {
        $this->modeleCourant = $model;
        $this->replisRestants = array_values(array_filter(
            array_map('trim', explode(',', $modelesDeRepli)),
            static fn (string $m): bool => $m !== '' && $m !== $model,
        ));
    }

    /**
     * LE MODÈLE RÉELLEMENT INTERROGÉ, qui n'est pas toujours celui de la configuration.
     *
     * Un 503 le fait basculer sur un modèle de secours, et il y RESTE pour le reste du
     * message : revenir au principal à chaque tour ferait repayer un échec certain à
     * chaque appel d'outil, sur un message qui en compte souvent cinq ou six.
     */
    private string $modeleCourant;

    /** @var string[] modèles de secours pas encore essayés, dans l'ordre */
    private array $replisRestants;

    public function name(): string
    {
        return 'gemini';
    }

    public function modelName(): string
    {
        return $this->modeleCourant;
    }

    public function reply(AiRequest $request): AiReply
    {
        // Ouvre la mesure : rattache les lignes « tour » qui suivent à ce message,
        // et permet au contrôleur de savoir combien de tours ont été payés si un
        // 429 interrompt la boucle.
        $this->journal->nouveauMessage();

        // Historique : notre rôle « assistant » devient « model » chez Gemini.
        $contents = array_map(
            static fn (array $m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['content']]],
            ],
            $request->messages,
        );

        // PREMIÈRE PHASE — COMPRENDRE. Avant les pièces natives, et ce n'est pas un
        // détail d'ordre : joindre un PDF scanné à l'appel censé rester petit lui
        // ferait perdre sa raison d'être. Le nom des pièces figure dans son prompt,
        // c'est assez pour savoir qu'elles existent.
        //
        // Demande ambiguë => on s'arrête ICI. Ni planification ni rédaction : le
        // message aura coûté un seul appel, le plus léger des trois, au lieu de deux
        // appels pleins pour une réponse à côté suivie d'une relance.
        $this->journal->debutDePhase(Phase::COMPREHENSION);
        $comprise = $this->comprehenseur->comprendre($request, $contents);
        if (!$comprise->claire) {
            return $this->conclure(
                $request,
                JournalTokens::ISSUE_CLARIFICATION,
                0,
                0,
                0,
                [],
                new AiReply(
                    $comprise->texteDeClarification(),
                    actions: [ClarificationEnAttente::action($comprise)],
                ),
            );
        }
        // L'intention voyage désormais avec la requête : la planification la lira en
        // tête de ses règles, à côté — jamais à la place — du message d'origine.
        $request = $request->withComprehension($comprise);

        // Pièces jointes lisibles nativement (PDF scannés, images) : jointes au
        // DERNIER tour utilisateur en inlineData, pour que Gemini les lise par
        // vision avec la question courante (elles restent en contexte des rounds
        // de function calling suivants).
        if ($request->piecesNatives !== []) {
            for ($i = count($contents) - 1; $i >= 0; $i--) {
                if (($contents[$i]['role'] ?? null) !== 'user') {
                    continue;
                }
                foreach ($request->piecesNatives as $piece) {
                    $contents[$i]['parts'][] = ['inlineData' => [
                        'mimeType' => $piece['mimeType'],
                        'data'     => $piece['donneesBase64'],
                    ]];
                }
                break;
            }
        }

        $refused = false;
        $toolUsed = null;
        $actions = [];
        $cumulInput = 0;
        $cumulSortie = 0;
        $sequenceOutils = [];
        $attenteCumulee = 0;
        // Ce que les outils ont RÉELLEMENT rapporté : la matière du repli si le modèle
        // ne rédige pas. Sans elle, on ne pouvait que servir une phrase générique.
        $resultatsOutils = [];
        // Outils de plan qui ont REFUSÉ : le contrôleur croise ce signal avec la prose
        // pour démasquer un plan décrit mais jamais préparé — et dire ce qui manque.
        $plansRefuses = [];

        // TROUSSE du message : décidée par le SERVEUR, sans rien demander au modèle.
        // Un appel de routage était un TROISIÈME appel — la règle n'en tolère que
        // deux, et c'est la planification elle-même qui choisit les outils.
        $trousse = $this->selecteur->trousseDe($request);
        $this->journal->routage(
            $request,
            $this->name(),
            $trousse->libelle(),
            'serveur',
            0,
            0,
        );

        // DEUX PHASES, JAMAIS TROIS. Planification (les outils sont déclarés), puis
        // rédaction (ils ne le sont plus : on commente un travail déjà fait).
        foreach ([Phase::PLANIFICATION, Phase::REDACTION] as $round => $phase) {
            // La phase est annoncée AVANT de partir : c'est pendant l'appel que
            // l'utilisateur attend, pas après. Tout le reste de ce journal se
            // mesure au retour, et arriverait donc une phase trop tard.
            $this->journal->debutDePhase($phase);
            ['reponse' => $response, 'octets' => $octets] = $this->appelerAvecReessai($request, $contents, $trousse, $phase);

            // APPEL D'OUTIL MALFORMÉ — le blocage du 2026-08-12, et il ne venait ni du
            // prompt ni du raisonnement du modèle. Sur « Je confirme », Gemini a bien
            // TENTÉ d'émettre preparer_operations, mais son sérialiseur d'appels a
            // produit une structure invalide qu'il a lui-même rejetée : finishReason
            // MALFORMED_FUNCTION_CALL, 698 jetons de sortie, et RIEN dans le canal des
            // fonctions. L'utilisateur recevait alors « redites-le-moi en nommant le
            // point précis », c'est-à-dire notre échec présenté comme son imprécision.
            //
            // C'est un défaut de SÉRIALISATION, donc dépendant de l'échantillonnage :
            // le même appel réémis passe le plus souvent. On réessaie UNE fois, et une
            // seule — le quota se compte par minute, et un échec répété relève d'autre
            // chose (un schéma trop profond pour ce modèle) que d'un aléa.
            if ($phase === Phase::PLANIFICATION && $this->estAppelMalforme($response)) {
                $this->logger->warning('Assistant IA (gemini) : appel d’outil MALFORMÉ, une reprise.', [
                    'sortie' => (int) ($response['usageMetadata']['candidatesTokenCount'] ?? 0),
                ]);
                ['reponse' => $response, 'octets' => $octets] = $this->appelerAvecReessai($request, $contents, $trousse, $phase);
                if ($this->estAppelMalforme($response)) {
                    // Deux fois de suite : ce n'est plus un aléa. On le DIT — et
                    // surtout on ne renvoie pas la faute à l'utilisateur.
                    $this->logger->error('Assistant IA (gemini) : appel d’outil malformé DEUX fois, abandon du tour.', [
                        'sortie' => (int) ($response['usageMetadata']['candidatesTokenCount'] ?? 0),
                    ]);
                }
            }

            $usage = $response['usageMetadata'] ?? [];
            $tokensDuTour = (int) ($usage['promptTokenCount'] ?? 0);
            $cumulInput += $tokensDuTour;
            $cumulSortie += (int) ($usage['candidatesTokenCount'] ?? 0);

            // Le débit consommé se déclare AUSSITÔT, et sur le compte TOTAL
            // (tokens cachés inclus) : le cache implicite du fournisseur allège
            // la facture, jamais la limite par minute — le 429 du 2026-08-08 est
            // survenu alors que 77 % de la minute était en cache. Déclarer ici
            // plutôt qu'en fin de message est indispensable : le quota est
            // partagé, une autre requête en cours doit voir ce que celle-ci vient
            // de consommer, sans attendre qu'elle se termine.
            $this->budget->enregistrer($this->modeleCourant, $tokensDuTour);

            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            $functionCalls = array_values(array_filter($parts, static fn (array $p) => isset($p['functionCall'])));

            // RATTRAPAGE — L'APPEL D'OUTIL ÉCRIT AU LIEU D'ÊTRE ÉMIS. Le modèle rend
            // parfois « consulter_guide(parcours-de-saisie) » comme du TEXTE, sans
            // rien émettre dans le canal des fonctions. Servir cette ligne à
            // l'utilisateur — ce qui est arrivé le 2026-08-11 — c'est lui montrer nos
            // rouages et lui refuser une réponse que nous pouvions produire : l'outil
            // existe, l'argument est bon, seul le canal était faux. On l'exécute donc,
            // sans aucun appel supplémentaire au fournisseur.
            $rattrapage = false;
            if ($functionCalls === [] && $phase === Phase::PLANIFICATION) {
                foreach ($this->appelEnTexte->extraire(
                    $this->extractText($parts, ''),
                    $this->trousseCatalogue->outilsDe($trousse, $request->scope),
                ) as $appel) {
                    $functionCalls[] = ['functionCall' => ['name' => $appel['name'], 'args' => $appel['args']]];
                    $rattrapage = true;
                }
                if ($rattrapage) {
                    $this->logger->warning('Assistant IA (gemini) : appel d’outil rendu en texte, rattrapé par le serveur.', [
                        'outils' => array_map(static fn (array $p) => $p['functionCall']['name'], $functionCalls),
                    ]);
                }
            }

            $outilsDuTour = array_map(
                static fn (array $p) => (string) $p['functionCall']['name'],
                $functionCalls,
            );

            $this->journal->tour(
                $request,
                $this->name(),
                $this->modeleCourant,
                $round + 1,
                [
                    'entree' => $tokensDuTour,
                    'sortie' => (int) ($usage['candidatesTokenCount'] ?? 0),
                    'cache'  => (int) ($usage['cachedContentTokenCount'] ?? 0),
                ],
                $octets,
                $outilsDuTour,
            );

            // Requête bloquée par les garde-fous Gemini (prompt ou réponse).
            if ($this->estBloquee($response)) {
                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_BLOCAGE_SECURITE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        "Je ne peux pas traiter cette demande. Reformulez votre question sur les données "
                        . 'de votre espace de travail et je vous aiderai volontiers.',
                        refused: true,
                    ),
                );
            }

            // Le modèle a formulé sa réponse : c'est la fin normale, à l'une ou
            // l'autre phase (une question de pure conversation n'appelle aucun outil
            // et se termine donc dès la planification, en UN seul appel).
            if ($functionCalls === []) {
                // TEXTE VIDE SANS APPEL D'OUTIL : le modèle n'a RIEN rendu. Le cas
                // s'est produit le 11/08/2026 sur « affiche le même tableau, mais
                // ajoute une colonne » — une pure remise en forme, où l'utilisateur
                // s'est vu répondre « précisez votre question » alors que sa demande
                // était parfaitement claire et que le tableau était juste au-dessus.
                //
                // Deux corrections, distinctes :
                //  1) on TRACE la cause. `finishReason` la nomme (MAX_TOKENS quand le
                //     budget de sortie est parti en raisonnement interne, RECITATION,
                //     OTHER…), et sans elle on ne peut que supposer. Le journal ne
                //     disait rien de ce tour : c'est ce qui a rendu l'incident opaque.
                //  2) on cesse de RENVOYER LA FAUTE. Le repli par défaut demande à
                //     l'utilisateur de préciser ; RepliPrecis, lui, restitue ce que les
                //     outils du tour ont rapporté et, à défaut, reconnaît franchement
                //     que NOUS n'avons pas conclu. C'est déjà ce que fait la phase de
                //     rédaction ; il n'y avait aucune raison que celle-ci en diffère.
                $texte = $this->extractText($parts, $this->repliPrecis->depuis($resultatsOutils));
                if (trim($this->extractText($parts, '')) === '') {
                    $this->logger->warning('Assistant IA (gemini) : réponse SANS texte ni appel d’outil.', [
                        'phase'        => $phase->name,
                        'finishReason' => $response['candidates'][0]['finishReason'] ?? null,
                        'outilsDuTour' => $sequenceOutils,
                        'sortie'       => (int) ($usage['candidatesTokenCount'] ?? 0),
                    ]);
                }

                // FILET DE SÛRETÉ D'AFFICHAGE. Si ce texte est encore un appel d'outil
                // écrit en prose — parce qu'il nomme un outil qui n'existe pas, ou qui
                // n'est pas déclaré ce tour-ci —, le rattrapage n'a rien pu en faire.
                // Le servir tel quel resterait la pire des issues : l'utilisateur a
                // reçu « consulter_guide(parcours-de-saisie) » et répondu « je ne
                // comprends rien !! ». On restitue plutôt ce que les outils du tour ont
                // rapporté, et à défaut on dit honnêtement qu'on n'a pas conclu.
                if ($this->appelEnTexte->ressembleAUnAppel($texte)) {
                    $this->logger->warning('Assistant IA (gemini) : appel d’outil en texte non rattrapable, masqué.', [
                        'phase' => $phase->name,
                        'texte' => mb_substr($texte, 0, 120),
                    ]);
                    $texte = $this->repliPrecis->depuis($resultatsOutils);
                }

                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_REPONSE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply($texte, refused: $refused, toolUsed: $toolUsed, actions: $actions, plansRefuses: $plansRefuses),
                );
            }

            // Phase de RÉDACTION : aucun outil ne lui est déclaré, donc un appel
            // d'outil ici ne peut être qu'une hallucination. On ne l'exécute pas et
            // on ne relance pas — ce serait le troisième appel. On rend ce qui a
            // déjà été obtenu, actions comprises : un plan préparé et son bouton de
            // validation valent bien mieux qu'une page blanche.
            if ($phase === Phase::REDACTION) {
                // Le modèle a redemandé un outil au lieu d'écrire. Plutôt que la phrase
                // générique d'autrefois — qui renvoyait l'utilisateur à sa question alors
                // que la réponse était déjà sur la table —, on restitue nous-mêmes ce que
                // les outils ont rapporté : la question précise qui reste à poser, le
                // blocage rencontré, ou les candidats à départager. Coût : zéro token.
                $texte = $this->extractText($parts, $this->repliPrecis->depuis($resultatsOutils));

                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_REPONSE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply($texte, refused: $refused, toolUsed: $toolUsed, actions: $actions, plansRefuses: $plansRefuses),
                );
            }

            // Function calling : exécuter TOUS les appels demandés (fail-closed
            // dans chaque outil), réponses regroupées dans UN message user.
            //
            // On exécute AVANT de regarder le débit disponible, à l'inverse de
            // l'ancien garde-fou : nos outils ne coûtent aucun token, et s'il
            // faut malgré tout s'arrêter là, autant que l'utilisateur reparte
            // avec ce qu'ils ont produit — un plan préparé et son bouton de
            // validation (uiAction) valent bien mieux qu'une page blanche.
            $contents[] = ['role' => 'model', 'parts' => DialecteGemini::preserverArgsObjets($parts)];
            $responseParts = [];
            foreach ($functionCalls as $part) {
                $name = (string) $part['functionCall']['name'];
                $args = (array) ($part['functionCall']['args'] ?? []);
                $result = $this->executeur->executer($name, $args, $request->scope);
                $toolUsed = $name;
                $sequenceOutils[] = $name;
                $resultatsOutils[] = ['outil' => $name, 'data' => $result->data];
                if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                    $refused = true;
                }
                if ($result->uiAction !== null) {
                    $actions[] = $result->uiAction;
                }
                // Un outil de plan qui n'a PAS produit de plan : le contrôleur doit le
                // savoir, sans quoi rien ne distingue « le modèle a décrit un plan
                // inexistant » de « le modèle a répondu à une question ».
                if ($this->outilsDePlan->estOutilDePlan($name) && MotifDeRefus::estUnRefus($result)) {
                    $plansRefuses[] = ['outil' => $name, 'motif' => MotifDeRefus::depuis($result)];
                }
                $responseParts[] = [
                    'functionResponse' => [
                        'name'     => $name,
                        'response' => ['status' => $result->status] + $result->data,
                    ],
                ];
            }
            // Un appel RATTRAPÉ n'existe pas dans le tour du modèle : lui renvoyer un
            // « functionResponse » sans « functionCall » correspondant ferait rejeter
            // toute la requête par le proto (400). Le résultat repart donc en texte —
            // même contenu, canal que la conversation accepte.
            $contents[] = $rattrapage
                ? ['role' => 'user', 'parts' => [['text' => $this->resultatsEnTexte($responseParts)]]]
                : ['role' => 'user', 'parts' => $responseParts];

            // CE QUI VIENT D'ARRIVER DÉCIDE DU TEMPS DE LA PHRASE SUIVANTE. Si la
            // planification a préparé une décision — plan d'écriture, document à
            // produire —, RIEN n'est écrit : la rédaction doit parler au futur. Sans ce
            // signal, elle n'a que le fil pour se situer et son prompt lui affirme que
            // le travail est fait ; c'est ainsi que Ket a annoncé « le document a été
            // correctement rattaché au client » sous un bouton « Valider et exécuter »
            // que personne n'avait touché (2026-08-16).
            $request = $request->withDecisionEnAttente(PlanEnAttente::porteUneDecision($actions));

            // La rédaction n'emporte NI les déclarations d'outils NI les protocoles
            // d'écriture : elle coûte donc bien moins que la planification. On
            // l'estime sur ce qu'elle transporte vraiment — l'historique et les
            // résultats —, sans quoi on renoncerait à un tour bon marché en le
            // croyant aussi cher que le précédent.
            $estime = (int) ceil(
                (\strlen((string) json_encode($contents, JSON_UNESCAPED_UNICODE))
                    + \strlen($this->contextBuilder->toSystemPrompt($request, $trousse, Phase::REDACTION)))
                / self::OCTETS_PAR_TOKEN,
            );
            $attente = $this->budget->secondesAvantLiberation($this->modeleCourant, $estime);

            // Assez de débit tout de suite : on enchaîne, c'est le cas courant.
            if ($attente === 0) {
                continue;
            }

            $tropLong = $attente === null
                || $attente > self::MAX_ATTENTE_SECONDES
                || $attenteCumulee + $attente > self::MAX_ATTENTE_CUMULEE_SECONDES;

            if ($tropLong) {
                $this->logger->warning('Assistant IA (gemini) : débit par minute saturé, boucle arrêtée.', [
                    'tours'          => $round + 1,
                    'cumulEntree'    => $cumulInput,
                    'estimeProchain' => $estime,
                    'restant'        => $this->budget->restant($this->modeleCourant),
                    'attente'        => $attente,
                    'dernierOutil'   => $outilsDuTour[0] ?? null,
                ]);

                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_BUDGET_ATTEINT,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        $this->messageDebitSature($attente),
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                        plansRefuses: $plansRefuses,
                    ),
                );
            }

            // La fenêtre se libère dans quelques secondes : patienter et finir le
            // travail vaut infiniment mieux que jeter les tours déjà payés.
            $this->journal->attente($request, $this->name(), $this->modeleCourant, $round + 1, $attente, $estime);
            ($this->dormir ?? static fn (int $s) => sleep($s))($attente);
            $attenteCumulee += $attente;
        }

        // Sortie de sécurité : les deux phases rendent toujours la main d'elles-mêmes,
        // ce point n'est donc pas atteint aujourd'hui. Il reste écrit — et correct :
        // une variable orpheline y attendait, qui aurait fait tomber le moteur le jour
        // où une troisième phase serait ajoutée. Et là encore, on restitue le travail
        // des outils plutôt qu'une phrase creuse.
        return $this->conclure(
            $request,
            JournalTokens::ISSUE_TOURS_EPUISES,
            count($sequenceOutils) + 1,
            $cumulInput,
            $cumulSortie,
            $sequenceOutils,
            new AiReply(
                $this->repliPrecis->depuis($resultatsOutils),
                refused: $refused,
                toolUsed: $toolUsed,
                actions: $actions,
                plansRefuses: $plansRefuses,
            ),
        );
    }

    /**
     * Point de sortie unique de reply() : journalise le bilan du message puis
     * rend la réponse. Passer par ici garantit qu'AUCUN chemin de sortie ne
     * manque à la campagne de mesure — or ce sont justement les sorties
     * anormales (budget, blocage, tours épuisés) qui l'intéressent le plus.
     *
     * @param list<string> $sequenceOutils
     */
    private function conclure(
        AiRequest $request,
        string $issue,
        int $tours,
        int $cumulEntree,
        int $cumulSortie,
        array $sequenceOutils,
        AiReply $reply,
    ): AiReply {
        $this->journal->message(
            $request,
            $this->name(),
            $this->modeleCourant,
            $issue,
            $tours,
            $cumulEntree,
            $cumulSortie,
            $sequenceOutils,
        );

        return $reply;
    }

    /**
     * Ce que Ket dit quand elle doit s'arrêter faute de débit.
     *
     * L'ancien message (« votre demande m'a obligé à enchaîner trop de
     * recherches… découpez-la ») rejetait sur l'utilisateur une limite qui
     * n'était pas la sienne — et, pire, se déclenchait le plus souvent alors que
     * le fournisseur aurait laissé passer (cap par message, cf. BudgetDebit).
     * Le débit étant partagé par tout le cabinet, la seule chose honnête est de
     * le dire et d'annoncer un délai réel.
     */
    private function messageDebitSature(?int $attente): string
    {
        // Attente impossible : même une fenêtre entièrement vide ne suffirait
        // pas. Ce n'est plus une question de patience mais de poids du fil —
        // c'est la seule situation où découper sert vraiment à quelque chose.
        if ($attente === null) {
            return 'Le fil de cette conversation est devenu trop lourd : je dois le renvoyer en '
                . "entier à mon moteur à chaque recherche, et il dépasse désormais ce qu'il accepte "
                . "en une minute. Ouvrez une nouvelle conversation (ou détachez quelques pièces "
                . 'jointes) et reposez-moi la question : je repartirai sur un contexte léger.';
        }

        return 'Mon moteur a atteint sa limite de débit pour la minute en cours — une limite '
            . 'partagée par tout le cabinet, pas un défaut de votre demande. '
            . sprintf('Relancez-la dans %d secondes ', max(1, $attente))
            . 'et je la termine : ce que j\'ai déjà rassemblé reste dans le fil.';
    }

    /**
     * Appel HTTP, avec UN réessai si le fournisseur répond 429 en annonçant un
     * délai court.
     *
     * Notre compteur local (BudgetDebit) peut sous-estimer : le quota est
     * partagé entre processus, et la séquence lire-modifier-écrire n'est pas
     * atomique. Quand le mur est touché malgré tout, abandonner ferait perdre
     * TOUS les tours déjà payés du message. Le fournisseur, lui, dit exactement
     * combien de temps attendre (google.rpc.RetryInfo) : autant s'en servir.
     *
     * Un seul réessai, et seulement si le délai tient dans MAX_ATTENTE_SECONDES —
     * au-delà, l'exception remonte au contrôleur, qui sait déjà l'expliquer
     * (AiEngineFailure) et journaliser le quota violé.
     */
    private function appelerAvecReessai(AiRequest $request, array $contents, Trousse $trousse, Phase $phase): array
    {
        try {
            return $this->call($request, $contents, $trousse, $phase);
        } catch (\Throwable $e) {
            // ⚠ LE 503 NE SE SOIGNE PAS EN ATTENDANT. Il dit que le modèle est débordé
            // chez Google, pas que nous avons trop consommé : le fournisseur n'annonce
            // aucun délai, et l'attente n'a rien de prévisible. On change de modèle.
            if (AiEngineFailure::estMoteurIndisponible($e)) {
                return $this->basculerSurUnRepli($e, $request, $contents, $trousse, $phase);
            }

            $delai = AiEngineFailure::estLimiteDeDebit($e)
                ? AiEngineFailure::secondesAvantNouvelEssai($e)
                : null;

            if ($delai === null || $delai > self::MAX_ATTENTE_SECONDES) {
                throw $e;
            }

            $this->logger->warning('Assistant IA (gemini) : 429 du fournisseur, un réessai après le délai annoncé.', [
                'delai'   => $delai,
                'details' => AiEngineFailure::detailsPourJournal($e),
            ]);
            ($this->dormir ?? static fn (int $s) => sleep($s))($delai);

            return $this->call($request, $contents, $trousse, $phase);
        }
    }

    /**
     * Rejoue l'appel sur les modèles de secours, l'un après l'autre.
     *
     * ⚠ LE BASCULEMENT EST DÉFINITIF POUR CE MESSAGE. Le modèle retenu le reste pour
     * tous les tours suivants : un message qui appelle six outils repaierait sinon six
     * échecs certains avant chaque succès, et l'utilisateur attendrait six fois le
     * délai réseau pour rien.
     *
     * ⚠ ON NE REVIENT PAS AU MODÈLE PRINCIPAL EN COURS DE ROUTE, et on ne réessaie pas
     * un secours déjà tombé : sans cette mémoire, un fournisseur durablement saturé
     * ferait tourner la boucle en rond à chaque tour.
     *
     * Si tous les secours échouent, c'est l'exception D'ORIGINE qui remonte — celle du
     * modèle principal. C'est elle qui décrit la panne réelle ; celle du dernier
     * secours ne parlerait que d'un modèle que l'utilisateur n'a jamais choisi.
     *
     * @param array<int, array<string, mixed>> $contents
     *
     * @return array{reponse: array, octets: array<string, int>}
     */
    private function basculerSurUnRepli(
        \Throwable $origine,
        AiRequest $request,
        array $contents,
        Trousse $trousse,
        Phase $phase,
    ): array {
        while ($this->replisRestants !== []) {
            $abandonne = $this->modeleCourant;
            $this->modeleCourant = array_shift($this->replisRestants);

            $this->logger->warning('Assistant IA (gemini) : modèle surchargé (503), bascule sur un modèle de secours.', [
                'abandonne' => $abandonne,
                'repli'     => $this->modeleCourant,
                'details'   => AiEngineFailure::detailsPourJournal($origine),
            ]);

            try {
                return $this->call($request, $contents, $trousse, $phase);
            } catch (\Throwable $e) {
                // Le secours est débordé lui aussi : on passe au suivant. Toute autre
                // panne, en revanche, appartient à ce modèle-là et doit remonter telle
                // quelle — un 400 sur un modèle qui refuse notre schéma d'outils n'a
                // rien à voir avec une surcharge, et l'enterrer serait perdre la cause.
                if (!AiEngineFailure::estMoteurIndisponible($e)) {
                    throw $e;
                }
            }
        }

        throw $origine;
    }

    /**
     * Appel HTTP generateContent (synchrone, sans streaming).
     *
     * Rend aussi la taille des trois blocs du payload. C'est la seule façon de
     * savoir OÙ partent les tokens sans payer un aller-retour countTokens : le
     * fournisseur ne renvoie qu'un total. Le rapport convertit ces octets en
     * tokens via le ratio observé.
     *
     * @return array{reponse: array, octets: array<string, int>}
     */
    private function call(AiRequest $request, array $contents, Trousse $trousse, Phase $phase): array
    {
        $promptSysteme = $this->contextBuilder->toSystemPrompt($request, $trousse, $phase);
        // LA PIÈCE MAÎTRESSE DE L'ÉCONOMIE : en rédaction, aucun outil n'est déclaré.
        // Les 72 Ko de déclarations ne servent qu'à CHOISIR un outil ; commenter un
        // résultat déjà obtenu n'en a aucun besoin. Les envoyer quand même, c'était
        // payer le catalogue deux fois par message.
        $declarations = $phase->declareDesOutils() ? $this->dialecte->declarations($trousse, $request->scope) : [];

        $response = $this->httpClient->request('POST', sprintf('%s/%s:generateContent', self::API_BASE, $this->modeleCourant), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            'json' => [
                'systemInstruction' => ['parts' => [['text' => $promptSysteme]]],
                'contents'          => $contents,
                'generationConfig'  => ['maxOutputTokens' => self::MAX_OUTPUT_TOKENS],
            ] + ($declarations === []
                // Aucun outil à déclarer : on OMET la clé au lieu d'envoyer une
                // liste vide. Un « tools » vide reste une invitation à en chercher,
                // et la phase de rédaction ne doit en trouver aucun.
                ? []
                : ['tools' => [['functionDeclarations' => $declarations]]]),
            'timeout' => 90,
        ]);

        return [
            'reponse' => $response->toArray(), // lève une exception explicite sur 4xx/5xx
            'octets'  => [
                'systeme'    => \strlen($promptSysteme),
                'outils'     => \strlen((string) json_encode($declarations, JSON_UNESCAPED_UNICODE)),
                'historique' => \strlen((string) json_encode($contents, JSON_UNESCAPED_UNICODE)),
            ],
        ];
    }

    /**
     * Déclarations de fonctions au format Gemini (name/description/parameters),
     * restreintes à ce qui a un sens dans ce périmètre et ce fil.
     *
     * Ces déclarations pèsent 72 Ko (≈ 19 600 tokens) et repartent à CHAQUE tour,
     * l'API étant sans mémoire. Décrire un outil que l'invité n'a pas le droit
     * d'exécuter, c'est payer plusieurs fois par message pour un refus certain.
     * Le filtrage n'est PAS une sécurité (elle reste dans execute(), fail-closed)
     * mais une économie de débit — cf. AiToolConditionnel.
     */
    /**
     * Les résultats d'outils rendus en TEXTE, pour le cas d'un appel rattrapé.
     *
     * Le canal normal (functionResponse) exige un functionCall correspondant dans le
     * tour du modèle ; un appel écrit en prose n'en a pas. Le contenu, lui, est le
     * même : le modèle reçoit les mêmes données, seule l'enveloppe change.
     *
     * @param array<int, array{functionResponse: array{name: string, response: array}}> $responseParts
     */
    private function resultatsEnTexte(array $responseParts): string
    {
        $lignes = [];
        foreach ($responseParts as $part) {
            $reponse = $part['functionResponse'] ?? null;
            if (!is_array($reponse)) {
                continue;
            }
            $lignes[] = sprintf(
                'Résultat de %s : %s',
                (string) ($reponse['name'] ?? '?'),
                (string) json_encode($reponse['response'] ?? [], JSON_UNESCAPED_UNICODE),
            );
        }

        return implode("\n\n", $lignes);
    }

    /**
     * Le fournisseur a-t-il rejeté SON PROPRE appel d'outil ?
     *
     * `MALFORMED_FUNCTION_CALL` signifie que le modèle a voulu appeler un outil et
     * que la structure émise était invalide : rien n'arrive dans le canal des
     * fonctions, et le texte est vide. C'est un défaut de sérialisation, pas de
     * raisonnement — d'où la reprise (cf. la boucle des phases).
     */
    private function estAppelMalforme(array $response): bool
    {
        if (($response['candidates'][0]['finishReason'] ?? null) !== 'MALFORMED_FUNCTION_CALL') {
            return false;
        }

        // Ceinture : si malgré tout un appel est arrivé, il n'y a rien à reprendre.
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return false;
            }
        }

        return true;
    }

    /** Prompt bloqué ou réponse coupée par les filtres de sécurité Gemini ? */
    private function estBloquee(array $response): bool
    {
        return isset($response['promptFeedback']['blockReason'])
            || ($response['candidates'][0]['finishReason'] ?? null) === 'SAFETY';
    }

    /**
     * Concatène les blocs texte de la réponse finale.
     *
     * @param string|null $repli message à rendre quand le modèle n'a produit aucun
     *                           texte. Le défaut convient à une réponse vide ; la
     *                           rédaction, elle, mérite mieux qu'un « précisez votre
     *                           question » — l'utilisateur a déjà été précis, c'est
     *                           nous qui n'avons pas su conclure.
     */
    private function extractText(array $parts, ?string $repli = null): string
    {
        $textes = [];
        foreach ($parts as $part) {
            if (isset($part['text']) && trim((string) $part['text']) !== '') {
                $textes[] = trim((string) $part['text']);
            }
        }

        if ($textes !== []) {
            return implode("\n\n", $textes);
        }

        return $repli ?? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?";
    }
}
