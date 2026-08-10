<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiEngineFailure;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Routage\RouteurTrousse;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\ActiverOutilsEcritureTool;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
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
    /** Garde-fou : nombre maximal d'allers-retours de function calling par message. */
    private const MAX_TOOL_ROUNDS = 8;
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
     * PLAFOND BAS, ET CE N'EST PAS NÉGOCIABLE : en développement, « symfony
     * serve » ne dispose que d'UN worker php-cgi — une requête qui dort fige
     * toute l'application, y compris le rechargement de la page. On ne patiente
     * jamais non plus avant le PREMIER tour : là, rendre la main tout de suite
     * vaut mieux que geler le navigateur sur une question pas encore commencée.
     */
    private const MAX_ATTENTE_SECONDES = 15;

    /** Attente cumulée tolérée sur un message entier (garde-fou de temps de réponse). */
    private const MAX_ATTENTE_CUMULEE_SECONDES = 25;

    /**
     * Une seule escalade par message. Au-delà, ce n'est plus un aiguillage raté mais
     * une boucle : le modèle réclamerait indéfiniment des outils qu'il tient déjà.
     */
    private const MAX_ESCALADES = 1;

    /**
     * Ratio octets → tokens MESURÉ sur la campagne (3,52 le 2026-08-08), et non
     * supposé. Sert à estimer ce que coûtera le tour suivant avant de le lancer.
     */
    private const OCTETS_PAR_TOKEN = 3.5;

    /** @var iterable<AiToolInterface> */
    private iterable $tools;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        // Source unique des outils déclarés — la MÊME que celle dont le prompt tire
        // sa section d'aiguillage.
        private readonly TrousseCatalogue $trousseCatalogue,
        private readonly RouteurTrousse $routeur,
        #[AutowireIterator('app.ai_tool')] iterable $tools,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')] private readonly string $model,
        private readonly LoggerInterface $logger,
        private readonly JournalTokens $journal,
        private readonly BudgetDebit $budget,
        // Injectable pour les tests : ils vérifient la DÉCISION d'attendre, pas
        // la capacité de PHP à dormir. Une suite qui dort n'est plus une suite.
        private readonly ?\Closure $dormir = null,
    ) {
        $this->tools = $tools;
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function modelName(): string
    {
        return $this->model;
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

        // TROUSSE du message : décidée UNE FOIS, avant la boucle. Elle ne bouge
        // ensuite que sur escalade explicite du modèle — un préfixe qui changerait
        // à chaque tour ne serait jamais mis en cache, et le cache tient 75 % du
        // payload (jusqu'à 86 % sur les tours tardifs).
        $routage = $this->routeur->router($request);
        $trousse = $routage['trousse'];
        $this->journal->routage(
            $request,
            $this->name(),
            $trousse->libelle(),
            (string) $routage['origine'],
            (int) $routage['tokens'],
            (int) $routage['millisecondes'],
        );
        $escalades = 0;

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            ['reponse' => $response, 'octets' => $octets] = $this->appelerAvecReessai($request, $contents, $trousse);

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
            $this->budget->enregistrer($this->model, $tokensDuTour);

            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            $functionCalls = array_values(array_filter($parts, static fn (array $p) => isset($p['functionCall'])));
            $outilsDuTour = array_map(
                static fn (array $p) => (string) $p['functionCall']['name'],
                $functionCalls,
            );

            $this->journal->tour(
                $request,
                $this->name(),
                $this->model,
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

            if ($functionCalls === []) {
                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_REPONSE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply($this->extractText($parts), refused: $refused, toolUsed: $toolUsed, actions: $actions),
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
            $contents[] = ['role' => 'model', 'parts' => $this->preserverArgsObjets($parts)];
            $responseParts = [];
            foreach ($functionCalls as $part) {
                $name = (string) $part['functionCall']['name'];
                $args = (array) ($part['functionCall']['args'] ?? []);
                $result = $this->executeTool($name, $args, $request);
                $toolUsed = $name;
                $sequenceOutils[] = $name;
                if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                    $refused = true;
                }
                if ($result->uiAction !== null) {
                    $actions[] = $result->uiAction;
                }
                // ESCALADE : le modèle réclame les outils d'écriture, qui ne lui ont
                // pas été déclarés. On bascule pour les tours suivants de CE message.
                // Le tour en cours est déjà parti — c'est le prix d'un aiguillage
                // raté, et il se paie au tarif réduit de la trousse de lecture.
                $demandee = $result->data[ActiverOutilsEcritureTool::CLE_TROUSSE] ?? null;
                if ($demandee !== null && $escalades < self::MAX_ESCALADES) {
                    $trousse = Trousse::depuis((string) $demandee);
                    $escalades++;
                    $this->journal->escalade($request, $this->name(), $trousse->libelle(), $round + 1);
                }
                $responseParts[] = [
                    'functionResponse' => [
                        'name'     => $name,
                        'response' => ['status' => $result->status] + $result->data,
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];

            // Dernier tour autorisé : inutile de jauger un tour qui n'aura pas lieu.
            if ($round >= self::MAX_TOOL_ROUNDS) {
                continue;
            }

            // Le tour suivant réexpédiera tout le contexte, augmenté des résultats
            // qu'on vient d'ajouter : il coûtera au moins autant que celui-ci.
            $estime = $tokensDuTour + (int) ceil(
                \strlen((string) json_encode($responseParts, JSON_UNESCAPED_UNICODE)) / self::OCTETS_PAR_TOKEN,
            );
            $attente = $this->budget->secondesAvantLiberation($this->model, $estime);

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
                    'restant'        => $this->budget->restant($this->model),
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
                    ),
                );
            }

            // La fenêtre se libère dans quelques secondes : patienter et finir le
            // travail vaut infiniment mieux que jeter les tours déjà payés.
            $this->journal->attente($request, $this->name(), $this->model, $round + 1, $attente, $estime);
            ($this->dormir ?? static fn (int $s) => sleep($s))($attente);
            $attenteCumulee += $attente;
        }

        return $this->conclure(
            $request,
            JournalTokens::ISSUE_TOURS_EPUISES,
            self::MAX_TOOL_ROUNDS + 1,
            $cumulInput,
            $cumulSortie,
            $sequenceOutils,
            new AiReply(
                "Je n'ai pas réussi à conclure ma recherche dans le temps imparti. Reformulez votre "
                . 'question de façon plus ciblée, je réessaierai.',
                refused: $refused,
                toolUsed: $toolUsed,
                actions: $actions,
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
            $this->model,
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
    private function appelerAvecReessai(AiRequest $request, array $contents, Trousse $trousse): array
    {
        try {
            return $this->call($request, $contents, $trousse);
        } catch (\Throwable $e) {
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

            return $this->call($request, $contents, $trousse);
        }
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
    private function call(AiRequest $request, array $contents, Trousse $trousse): array
    {
        $promptSysteme = $this->contextBuilder->toSystemPrompt($request, $trousse);
        $declarations = $this->toolDeclarations($request->scope, $trousse);

        $response = $this->httpClient->request('POST', sprintf('%s/%s:generateContent', self::API_BASE, $this->model), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            'json' => [
                'systemInstruction' => ['parts' => [['text' => $promptSysteme]]],
                'contents'          => $contents,
                'tools'             => [['functionDeclarations' => $declarations]],
                'generationConfig'  => ['maxOutputTokens' => self::MAX_OUTPUT_TOKENS],
            ],
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
    private function toolDeclarations(AiScope $scope, Trousse $trousse): array
    {
        $declarations = [];
        foreach ($this->trousseCatalogue->outilsDe($trousse, $scope) as $tool) {
            $declarations[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $this->sanitizeSchema($tool->schema()),
            ];
        }

        return $declarations;
    }

    /**
     * Le proto Schema de Gemini ne connaît qu'un SOUS-ENSEMBLE de JSON-Schema :
     * un mot-clé inconnu (ex. additionalProperties, posé par ouvrir_dialogue
     * pour le pré-remplissage libre) fait rejeter TOUTE la requête en 400
     * INVALID_ARGUMENT. On élague donc récursivement ces mots-clés ici — le
     * schéma des outils reste du JSON-Schema standard pour les autres moteurs
     * (Claude les accepte) ; c'est le dialecte Gemini qui s'adapte.
     */
    private function sanitizeSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        foreach ($schema as $key => $value) {
            if (\is_array($value)) {
                $schema[$key] = $this->sanitizeSchema($value);
            }
        }

        return $schema;
    }

    /**
     * PHP décode « args: {} » (objet JSON vide) en TABLEAU vide ; ré-encodé tel
     * quel dans l'écho du tour model, il redeviendrait [] (une liste), rejetée
     * par le proto Gemini (400 « Proto field is not repeating, cannot start
     * list »). On restitue l'objet vide — cas de tout outil SANS paramètre
     * (solde_tokens, quitter_workspace).
     */
    private function preserverArgsObjets(array $parts): array
    {
        foreach ($parts as $i => $part) {
            if (isset($part['functionCall']) && ($part['functionCall']['args'] ?? null) === []) {
                $parts[$i]['functionCall']['args'] = new \stdClass();
            }
        }

        return $parts;
    }

    private function executeTool(string $name, array $args, AiRequest $request): AiToolResult
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool->execute($args, $request->scope);
            }
        }

        return AiToolResult::introuvable($name);
    }

    /** Prompt bloqué ou réponse coupée par les filtres de sécurité Gemini ? */
    private function estBloquee(array $response): bool
    {
        return isset($response['promptFeedback']['blockReason'])
            || ($response['candidates'][0]['finishReason'] ?? null) === 'SAFETY';
    }

    /** Concatène les blocs texte de la réponse finale. */
    private function extractText(array $parts): string
    {
        $textes = [];
        foreach ($parts as $part) {
            if (isset($part['text']) && trim((string) $part['text']) !== '') {
                $textes[] = trim((string) $part['text']);
            }
        }

        return $textes === []
            ? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?"
            : implode("\n\n", $textes);
    }
}
