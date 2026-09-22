<?php

namespace App\Ai\Comprehension;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\AiText;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La phase de compréhension chez Google — le code d'origine, déplacé.
 *
 * DEUX APPELS QUAND LE MODÈLE CHERCHE, UN SEUL SINON. Gemini refuse `tools` et
 * `responseMimeType: application/json` dans la même requête (400). Le tour qui
 * porte les outils demande donc son JSON par le prompt ; celui qui conclut
 * l'impose par le schéma. Quand le modèle n'appelle aucun outil — le cas
 * courant —, il n'y a qu'un appel et son texte est lu tel quel.
 */
final class AppelGemini implements FournisseurDeComprehension
{
    /**
     * Assez pour une intention de trois phrases et quelques questions courtes. Le
     * modèle n'a rien d'autre à écrire : un plafond haut n'achèterait ici que du
     * raisonnement interne facturé.
     */
    public const MAX_OUTPUT_TOKENS = 700;

    /** Un comprenant lent est un comprenant inutile : mieux vaut passer outre. */
    private const TIMEOUT_SECONDES = 20;

    /**
     * DURÉE TOTALE de l'appel, et pas seulement délai d'inactivité.
     *
     * Le `timeout` d'HttpClient ne compte que les silences du réseau : un flux qui
     * trickle indéfiniment ne l'atteint jamais. Mesuré sur les journaux du
     * 2026-09-17 : des appels coupés à 20, 34, 35 secondes — pour une phase qui
     * n'améliore qu'une reformulation, et dont l'échec est sans conséquence. Au-delà
     * de huit secondes, elle coûte plus qu'elle ne rapporte.
     */
    private const DUREE_MAX_SECONDES = 8;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        // Source unique du prompt : la compréhension est une PHASE, au même titre
        // que la planification et la rédaction, et son texte vit avec les leurs.
        private readonly AiContextBuilder $contextBuilder,
        // Les particularités du proto Gemini, partagées avec le moteur.
        private readonly DialecteGemini $dialecte,
        // Le seul chemin vers le code métier, partagé avec les deux moteurs. La garde
        // de périmètre reste dans chaque execute() : comprendre ne donne aucun droit.
        private readonly ExecuteurDOutils $executeur,
        private readonly BudgetDebit $budget,
        private readonly MemoireDEpuisement $epuisement,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODELE_COMPREHENSION')] private readonly string $modele,
    ) {
    }

    public function nom(): string
    {
        return 'gemini';
    }

    public function modele(): string
    {
        return $this->modele;
    }

    /** Le compteur de Google est tenu par modèle : la clé est le nom du modèle. */
    public function cleDeDebit(): string
    {
        return $this->modele;
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /** La clé de ce fournisseur dans la mémoire d'épuisement — famille comprise. */
    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('comprehension', 'gemini', $this->modele);
    }

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    public function conclure(AiRequest $request): array
    {
        $contents = $this->fil($request);

        $reponse = $this->appeler($request, $contents, avecOutils: true);
        $tokens = $this->facturer($reponse);

        $parts = $reponse['candidates'][0]['content']['parts'] ?? [];
        $appels = array_values(array_filter($parts, static fn (array $p) => isset($p['functionCall'])));
        if ($appels === []) {
            return ['texte' => self::texte($reponse), 'tokens' => $tokens];
        }

        // Les outils sont exécutés localement, tous ceux du tour, en fail-closed dans
        // leur propre execute(). Ils ne coûtent aucun token.
        $resultats = [];
        foreach ($appels as $part) {
            $nom = (string) $part['functionCall']['name'];
            $resultat = $this->executeur->executer($nom, (array) ($part['functionCall']['args'] ?? []), $request->scope);
            $resultats[] = ['functionResponse' => [
                'name'     => $nom,
                'response' => ['status' => $resultat->status] + $resultat->data,
            ]];
        }

        $contents[] = ['role' => 'model', 'parts' => DialecteGemini::preserverArgsObjets($parts)];
        $contents[] = ['role' => 'user', 'parts' => $resultats];

        // Second et DERNIER appel : plus d'outils, un schéma de sortie strict.
        $reponse = $this->appeler($request, $contents, avecOutils: false);

        return ['texte' => self::texte($reponse), 'tokens' => $tokens + $this->facturer($reponse)];
    }

    /**
     * L'historique au dialecte Gemini, construit ICI.
     *
     * ⚠ NE PAS LE RECEVOIR DE L'APPELANT. Le moteur de texte en a un, mais au
     * format de SON fournisseur : sous Claude, le lui reprendre enverrait chez
     * Google un fil que Google refuse — et, tout étant fail-open ici, la phase
     * échouerait sans un mot, à chaque message.
     *
     * @return list<array<string, mixed>>
     */
    private function fil(AiRequest $request): array
    {
        return array_map(
            static fn (array $m) => [
                'role'  => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ],
            $request->messages,
        );
    }

    /**
     * Déclare au compteur de débit ce que ce tour vient de consommer, et le rend.
     *
     * Sur le compteur du modèle de COMPRÉHENSION, distinct de celui de la
     * planification chez le fournisseur : c'est toute la raison d'utiliser un modèle
     * à part, et l'oublier ferait de cette phase une ponction sur la fenêtre qu'elle
     * est censée épargner.
     *
     * @param array<string, mixed> $reponse
     */
    private function facturer(array $reponse): int
    {
        $tokens = (int) ($reponse['usageMetadata']['promptTokenCount'] ?? 0);
        $this->budget->enregistrer($this->modele, $tokens);

        return $tokens;
    }

    /** @param array<string, mixed> $reponse */
    private static function texte(array $reponse): string
    {
        $texte = '';
        foreach ($reponse['candidates'][0]['content']['parts'] ?? [] as $part) {
            $texte .= (string) ($part['text'] ?? '');
        }

        return $texte;
    }

    /**
     * @param array<int, array> $contents
     *
     * @return array<string, mixed>
     */
    private function appeler(AiRequest $request, array $contents, bool $avecOutils): array
    {
        $prompt = $this->contextBuilder->toSystemPrompt($request, Trousse::COMPREHENSION, Phase::COMPREHENSION);

        // Les outils de LEVÉE D'AMBIGUÏTÉ, et eux seuls (cf. AiToolDeComprehension) :
        // de quoi savoir de qui l'on parle, jamais de quoi répondre à sa place.
        $declarations = $avecOutils ? $this->dialecte->declarations(Trousse::COMPREHENSION, $request->scope) : [];

        // Gemini refuse « tools » et « responseMimeType: application/json » ensemble
        // (400). Le tour qui porte les outils demande donc son JSON par le prompt ;
        // celui qui conclut l'impose par le schéma. Aucun des deux n'a le choix.
        $sortie = $declarations === []
            ? [
                'responseMimeType' => 'application/json',
                'responseSchema'   => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'claire'    => ['type' => 'BOOLEAN'],
                        'intention' => ['type' => 'STRING'],
                        'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    ],
                    'required' => ['claire', 'intention'],
                ],
            ]
            : [];

        return $this->httpClient->request('POST', sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $this->modele,
        ), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            // Texte non UTF-8 (fichier joint, troncature) : réparé, sinon le JSON ne part pas.
            'json' => AiText::utf8Profond([
                'systemInstruction' => ['parts' => [['text' => $prompt]]],
                'contents'          => $contents,
                'generationConfig'  => [
                    'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                    // Comprendre n'est pas une tâche créative : à température nulle, la
                    // même demande reçoit la même lecture d'un jour sur l'autre.
                    'temperature'     => 0.0,
                ] + $sortie,
            ] + ($declarations === []
                ? []
                : ['tools' => [['functionDeclarations' => $declarations]]])),
            'timeout'      => self::TIMEOUT_SECONDES,
            'max_duration' => self::DUREE_MAX_SECONDES,
        ])->toArray();
    }
}
