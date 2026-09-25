<?php

namespace App\Ai\Comprehension;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\AiText;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\FournisseurAModele;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\ModeleChoisi;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La phase de compréhension chez Anthropic.
 *
 * UN SEUL APPEL, MÊME QUAND LE MODÈLE CHERCHE — et c'est le seul endroit de ce
 * chantier où Anthropic fait mieux que Google par construction. Gemini refuse
 * `tools` et un schéma de sortie dans la même requête, ce qui oblige à deux
 * allers-retours dès que le comprenant veut vérifier un nom en base. Ici, les
 * deux coexistent : un outil de conclusion rend un JSON valide par construction,
 * les outils de levée d'ambiguïté sont déclarés à côté de lui, et `tool_choice`
 * interdit au modèle de répondre en prose.
 *
 * PAS DE CACHE DE PROMPT ICI, volontairement. Le préfixe minimum cachable de
 * claude-haiku-4-5 est de 4 096 tokens ; le prompt de compréhension est
 * délibérément court et passe en dessous. Y poser un point de rupture coûterait
 * 1,25× le plein tarif pour une relecture qui n'arriverait jamais.
 */
final class AppelAnthropic implements FournisseurDeComprehension, FournisseurAModele
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /** L'outil par lequel le modèle rend sa conclusion — jamais du texte libre. */
    private const OUTIL_CONCLUSION = 'conclure_comprehension';

    /** @see AppelGemini::MAX_OUTPUT_TOKENS — même raison, même valeur. */
    private const MAX_OUTPUT_TOKENS = 700;

    private const TIMEOUT_SECONDES = 20;

    private const DUREE_MAX_SECONDES = 8;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        private readonly TrousseCatalogue $trousseCatalogue,
        private readonly ExecuteurDOutils $executeur,
        private readonly BudgetDebit $budget,
        private readonly MemoireDEpuisement $epuisement,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'ANTHROPIC_MODELE_COMPREHENSION')] private readonly string $modeleParDefaut,
        // LA POLITIQUE DE LA CONSOLE, en dernier et facultative : sans elle, ce
        // fournisseur se comporte exactement comme avant, sur le seul `.env`.
        // C'est ce qui permet aux harnais de test de l'ignorer sans rien perdre.
        private readonly ?PolitiqueDesFournisseurs $politique = null,
    ) {
    }

    public function nom(): string
    {
        return 'anthropic';
    }

    /**
     * LE MODÈLE À APPELER, RELU À CHAQUE FOIS.
     *
     * Jamais mis en cache ni figé au constructeur : c'est ce qui fait qu'un
     * changement enregistré depuis la console part avec le MESSAGE SUIVANT, sans
     * redémarrage. Une saisie qui ne ressemble pas à un nom de modèle est ignorée
     * au profit du défaut du serveur — cf. ModeleChoisi.
     */
    public function modele(): string
    {
        return ModeleChoisi::pour($this->politique, 'comprehension', 'anthropic', $this->modeleParDefaut);
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /** La clé de ce fournisseur dans la mémoire d'épuisement — famille comprise. */
    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('comprehension', 'anthropic', $this->modele());
    }

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    /**
     * Le compteur d'entrée du modèle de compréhension — distinct de celui de la
     * planification, comme chez Google : c'est la raison d'être d'un modèle à part.
     */
    public function cleDeDebit(): string
    {
        return 'anthropic:in:' . $this->modele();
    }

    public function conclure(AiRequest $request): array
    {
        $fil = $this->fil($request);

        $reponse = $this->appeler($request, $fil);
        $tokens = $this->facturer($reponse);

        [$conclusion, $aChercher] = $this->depouiller($reponse);

        // Le modèle a conclu du premier coup : c'est le cas courant, et il n'aura
        // coûté qu'un seul appel.
        if ($conclusion !== null) {
            return ['texte' => $conclusion, 'tokens' => $tokens];
        }

        // Il a voulu vérifier quelque chose en base. Les outils sont exécutés
        // localement, fail-closed dans leur propre execute(), et ne coûtent aucun
        // token. On lui rend les résultats, et il conclut — UN tour de plus, jamais
        // deux : c'est l'enchaînement qui a saturé le quota le 2026-08-10.
        if ($aChercher === []) {
            return ['texte' => '', 'tokens' => $tokens];
        }

        $resultats = [];
        foreach ($aChercher as $appel) {
            $resultat = $this->executeur->executer($appel['nom'], $appel['args'], $request->scope, Trousse::COMPREHENSION);
            $resultats[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $appel['id'],
                'content'     => json_encode(['status' => $resultat->status] + $resultat->data, JSON_UNESCAPED_UNICODE),
            ];
        }

        $fil[] = ['role' => 'assistant', 'content' => $reponse['content']];
        $fil[] = ['role' => 'user', 'content' => $resultats];

        $reponse = $this->appeler($request, $fil);
        [$conclusion] = $this->depouiller($reponse);

        return ['texte' => $conclusion ?? '', 'tokens' => $tokens + $this->facturer($reponse)];
    }

    /**
     * La conclusion si le modèle l'a rendue, et les outils qu'il veut voir tourner.
     *
     * @param array<string, mixed> $reponse
     *
     * @return array{0: string|null, 1: list<array{nom: string, args: array, id: string}>}
     */
    private function depouiller(array $reponse): array
    {
        $conclusion = null;
        $aChercher = [];

        foreach (($reponse['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) !== 'tool_use') {
                continue;
            }
            if (($bloc['name'] ?? null) === self::OUTIL_CONCLUSION) {
                // Sortie structurée : le JSON est déjà valide, l'outil l'a imposé.
                $conclusion = (string) json_encode((array) ($bloc['input'] ?? []), JSON_UNESCAPED_UNICODE);
                continue;
            }
            $aChercher[] = [
                'nom'  => (string) ($bloc['name'] ?? ''),
                'args' => (array) ($bloc['input'] ?? []),
                'id'   => (string) ($bloc['id'] ?? ''),
            ];
        }

        return [$conclusion, $aChercher];
    }

    /**
     * L'historique au format Messages API, construit ICI — jamais repris du moteur,
     * qui peut parler un autre dialecte (cf. AppelDeComprehension).
     *
     * @return list<array<string, mixed>>
     */
    private function fil(AiRequest $request): array
    {
        return array_map(
            static fn (array $m) => [
                'role'    => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($m['content'] ?? ''),
            ],
            $request->messages,
        );
    }

    /** @param array<string, mixed> $reponse */
    private function facturer(array $reponse): int
    {
        $usage = \App\Ai\Engine\Usage::depuisAnthropic($reponse);
        $this->budget->enregistrer($this->cleDeDebit(), $usage->debit);

        return $usage->entree;
    }

    /**
     * @param list<array<string, mixed>> $fil
     *
     * @return array<string, mixed>
     */
    private function appeler(AiRequest $request, array $fil): array
    {
        $outils = [$this->outilDeConclusion()];
        foreach ($this->trousseCatalogue->outilsDe(Trousse::COMPREHENSION, $request->scope) as $outil) {
            $outils[] = [
                'name'         => $outil->name(),
                'description'  => $outil->description(),
                'input_schema' => $outil->schema(),
            ];
        }

        return $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => AiText::utf8Profond([
                'model'      => $this->modele(),
                'max_tokens' => self::MAX_OUTPUT_TOKENS,
                // Comprendre n'est pas une tâche créative : à température nulle, la
                // même demande reçoit la même lecture d'un jour sur l'autre.
                'temperature' => 0.0,
                'system'      => $this->contextBuilder->toSystemPrompt($request, Trousse::COMPREHENSION, Phase::COMPREHENSION),
                'messages'    => $fil,
                'tools'       => $outils,
                // JAMAIS DE TEXTE LIBRE. « any » force un outil sans imposer lequel :
                // le modèle peut donc lever une ambiguïté d'abord, puis conclure — mais
                // il ne peut pas répondre en prose, ce qui rendrait la sortie
                // imprévisible et ramènerait l'épluchage de clôtures markdown que la
                // version Gemini doit encore faire.
                'tool_choice' => ['type' => 'any'],
            ]),
            'timeout'      => self::TIMEOUT_SECONDES,
            'max_duration' => self::DUREE_MAX_SECONDES,
        ])->toArray();
    }

    /**
     * L'outil de sortie structurée.
     *
     * `strict: true` exige `additionalProperties: false` et la liste des champs
     * requis : le modèle ne peut alors rendre qu'un objet conforme. C'est
     * l'équivalent du `responseSchema` de Google — à ceci près qu'il coexiste avec
     * les autres outils, là où Google impose de choisir.
     *
     * @return array<string, mixed>
     */
    private function outilDeConclusion(): array
    {
        return [
            'name'        => self::OUTIL_CONCLUSION,
            'description' => 'Rends ta conclusion sur la demande de l’utilisateur. Appelle TOUJOURS cet outil '
                . 'pour terminer, même après avoir vérifié quelque chose avec un autre outil.',
            'strict'      => true,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'claire'    => ['type' => 'boolean', 'description' => 'La demande est-elle exploitable telle quelle ?'],
                    'intention' => ['type' => 'string', 'description' => 'La demande reformulée, valeurs comprises.'],
                    'questions' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Les points qui restent à trancher, vides si la demande est claire.',
                    ],
                ],
                'required'             => ['claire', 'intention', 'questions'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Le modèle que la console affiche en filigrane du champ « Modèle ».
     *
     * Un nom de modèle n'est pas un secret : la console est réservée aux agents
     * Joseara, et ce nom figure dans la documentation publique du fournisseur.
     */
    public function modeleEnVigueur(): string
    {
        return $this->modele();
    }
}
