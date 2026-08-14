<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Mutation\MotifDeRefus;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Redaction\RepliPrecis;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moteur RÉEL de l'assistant : API Claude (Anthropic Messages API) via
 * symfony/http-client, avec tool-calling natif — le modèle décide lui-même
 * d'appeler nos outils métier pour répondre aux questions de données.
 *
 * Adaptateur direct (sans SDK) : le projet est verrouillé en Symfony 7.1.*,
 * incompatible avec les paquets symfony/ai-* (qui exigent clock ^7.3 et
 * phpdoc-parser ^2). Le jour où le socle passera en 7.3+, un adaptateur
 * Symfony AI pourra remplacer celui-ci par simple repointage d'alias.
 *
 * SÉCURITÉ : le périmètre ne dépend PAS du modèle — chaque outil re-vérifie
 * canRead() dans execute() (fail-closed). Le prompt système ne fait qu'énoncer
 * la politesse du refus ; la garde est dans le code.
 */
final class AnthropicAiEngine implements AiEngineInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    /** Assez ample pour restituer une page de liste (rechercher_entites) sans troncature. */
    private const MAX_OUTPUT_TOKENS = 4096;
    /**
     * UN SEUL TOUR D'OUTILS, comme chez Gemini : le modèle n'orchestre pas, PHP
     * orchestre. Un message = deux appels (les outils, puis la formulation).
     * Les deux moteurs doivent se comporter à l'identique, sinon comparer leurs
     * mesures n'a plus de sens et un défaut ne se reproduit que sur l'un des deux.
     */
    private const MAX_TOOL_ROUNDS = 1;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        // Même source que le prompt système : les deux doivent être dérivés du même
        // tableau d'outils, sans quoi une consigne peut nommer un outil non déclaré.
        private readonly TrousseCatalogue $trousseCatalogue,
        // Partagé avec le moteur Gemini et la phase de compréhension.
        private readonly ExecuteurDOutils $executeur,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'ANTHROPIC_MODEL')] private readonly string $model,
        // Rédige en PHP, à coût nul, ce que le modèle n'a pas rédigé.
        private readonly RepliPrecis $repliPrecis,
        // Source unique des outils qui produisent un plan — la même que celle du
        // prompt et du moteur Gemini.
        private readonly OutilsDePlan $outilsDePlan,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * Joint les pièces lisibles nativement (images, PDF scannés) au DERNIER tour
     * utilisateur, en blocs de contenu Messages API — miroir exact de l'inlineData
     * de GeminiAiEngine.
     *
     * Sans cela, le prompt affirmait au modèle que ces pièces lui « sont transmises
     * DIRECTEMENT pour lecture visuelle » alors que ce moteur ne les envoyait pas :
     * une image ou un PDF sans couche texte était invisible, et le modèle, sommé de
     * le lire, n'avait d'autre issue que d'inventer. L'écart était sans effet tant
     * que Gemini restait le moteur actif — mais ANTHROPIC_API_KEY est PRIORITAIRE
     * dans AiEngineResolver, donc une simple clé posée suffisait à l'ouvrir.
     *
     * @param list<array{role:string, content:mixed}>                               $messages
     * @param list<array{mimeType:string, donneesBase64:string, nom:string}>        $pieces
     *
     * @return list<array{role:string, content:mixed}>
     */
    private function joindrePiecesNatives(array $messages, array $pieces): array
    {
        if ($pieces === []) {
            return $messages;
        }

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) !== 'user') {
                continue;
            }
            // Le tour devient une liste de blocs : le texte d'abord, les pièces ensuite.
            $contenu = $messages[$i]['content'];
            $blocs = is_array($contenu) ? $contenu : [['type' => 'text', 'text' => (string) $contenu]];

            foreach ($pieces as $piece) {
                $mime = (string) $piece['mimeType'];
                // Une image et un PDF ne portent pas le même type de bloc chez Anthropic.
                $blocs[] = [
                    'type'   => $mime === 'application/pdf' ? 'document' : 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mime,
                        'data'       => (string) $piece['donneesBase64'],
                    ],
                ];
            }
            $messages[$i]['content'] = $blocs;
            break;
        }

        return $messages;
    }

    public function reply(AiRequest $request): AiReply
    {
        $messages = array_map(
            static fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $request->messages,
        );
        $messages = $this->joindrePiecesNatives($messages, $request->piecesNatives);

        $refused = false;
        $toolUsed = null;
        $actions = [];
        // Ce que les outils ont réellement rapporté : la matière du repli si la boucle
        // s'achève sans réponse rédigée (même règle que le moteur Gemini).
        $resultatsOutils = [];
        // Outils de plan ayant REFUSÉ : le garde-fou anti-plan fantôme du contrôleur
        // s'appuie sur ce signal. Le renseigner ici aussi n'est pas une précaution de
        // confort — un garde-fou qui ne s'appliquerait qu'à un moteur sur deux serait
        // une divergence silencieuse, exactement ce que ce chantier corrige.
        $plansRefuses = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->call($request, $messages);

            // Garde de sécurité Anthropic : la requête a été déclinée.
            if (($response['stop_reason'] ?? null) === 'refusal') {
                return new AiReply(
                    "Je ne peux pas traiter cette demande. Reformulez votre question sur les données "
                    . 'de votre espace de travail et je vous aiderai volontiers.',
                    refused: true,
                );
            }

            if (($response['stop_reason'] ?? null) !== 'tool_use') {
                return new AiReply(
                    $this->extractText($response),
                    refused: $refused,
                    toolUsed: $toolUsed,
                    actions: $actions,
                    plansRefuses: $plansRefuses,
                );
            }

            // Tool-calling : exécuter TOUS les appels demandés (fail-closed dans
            // chaque outil) et renvoyer tous les résultats dans UN SEUL message user.
            $messages[] = ['role' => 'assistant', 'content' => $this->preserverInputsObjets($response['content'])];
            $toolResults = [];
            foreach ($response['content'] as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }
                $result = $this->executeur->executer((string) $block['name'], (array) ($block['input'] ?? []), $request->scope);
                $toolUsed = (string) $block['name'];
                $resultatsOutils[] = ['outil' => $toolUsed, 'data' => $result->data];
                if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                    $refused = true;
                }
                if ($result->uiAction !== null) {
                    $actions[] = $result->uiAction;
                }
                if ($this->outilsDePlan->estOutilDePlan($toolUsed) && MotifDeRefus::estUnRefus($result)) {
                    $plansRefuses[] = ['outil' => $toolUsed, 'motif' => MotifDeRefus::depuis($result)];
                }
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) $block['id'],
                    'content'     => json_encode(
                        ['status' => $result->status] + $result->data,
                        JSON_UNESCAPED_UNICODE,
                    ),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Les tours sont épuisés sans réponse rédigée. On ne renvoie pas l'utilisateur à
        // sa question : on lui restitue ce que les outils ont trouvé — la question
        // précise qui reste, le blocage, ou les candidats à départager (cf. RepliPrecis).
        return new AiReply(
            $this->repliPrecis->depuis($resultatsOutils),
            refused: $refused,
            toolUsed: $toolUsed,
            actions: $actions,
            plansRefuses: $plansRefuses,
        );
    }

    /** Appel HTTP Messages API (synchrone, sans streaming). */
    private function call(AiRequest $request, array $messages): array
    {
        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => $this->model,
                'max_tokens' => self::MAX_OUTPUT_TOKENS,
                'system'     => $this->contextBuilder->toSystemPrompt($request),
                'tools'      => $this->toolDefinitions($request->scope),
                'messages'   => $messages,
            ],
            'timeout' => 90,
        ]);

        return $response->toArray(); // lève une exception explicite sur 4xx/5xx
    }

    /**
     * Définitions de tools au format Messages API (name/description/input_schema),
     * filtrées comme chez Gemini : les deux moteurs doivent présenter au modèle
     * exactement le même jeu d'outils, sinon comparer leurs mesures n'aurait plus
     * de sens et un bug ne se reproduirait que sur l'un des deux.
     */
    private function toolDefinitions(AiScope $scope): array
    {
        $definitions = [];
        // Même source que le prompt système (TrousseCatalogue) : c'est ce qui
        // interdit qu'une consigne nomme un outil non déclaré. Ce moteur reste sur
        // la trousse COMPLÈTE — le routage n'est implémenté que côté Gemini, et un
        // moteur qui déclarerait moins d'outils que son prompt n'en nomme serait
        // exactement le défaut qu'on cherche à éliminer.
        foreach ($this->trousseCatalogue->outilsDe(Trousse::ECRITURE, $scope) as $tool) {
            $definitions[] = [
                'name'         => $tool->name(),
                'description'  => $tool->description(),
                'input_schema' => $tool->schema(),
            ];
        }

        return $definitions;
    }

    /**
     * PHP décode « input: {} » (objet JSON vide) en TABLEAU vide ; ré-encodé
     * tel quel dans l'écho du tour assistant, il redeviendrait [] (une liste),
     * rejetée par l'API (input d'un tool_use = objet). On restitue l'objet
     * vide — cas de tout outil SANS paramètre (solde_tokens, quitter_workspace).
     */
    private function preserverInputsObjets(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? null) === []) {
                $blocks[$i]['input'] = new \stdClass();
            }
        }

        return $blocks;
    }

    /** Concatène les blocs texte de la réponse finale. */
    private function extractText(array $response): string
    {
        $parts = [];
        foreach (($response['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text' && trim((string) $block['text']) !== '') {
                $parts[] = trim((string) $block['text']);
            }
        }

        return $parts === []
            ? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?"
            : implode("\n\n", $parts);
    }
}
