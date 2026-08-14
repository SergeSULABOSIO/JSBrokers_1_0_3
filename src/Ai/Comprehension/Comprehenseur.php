<?php

namespace App\Ai\Comprehension;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LE PREMIER DES TROIS APPELS : établir ce que l'utilisateur veut dire, avant de
 * décider quoi que ce soit.
 *
 * Ket se trompait souvent de demande. La consigne « comprendre avant d'agir »
 * existait pourtant — mais noyée dans le prompt de planification, au milieu de
 * quarante déclarations d'outils et de 27 Ko de protocoles d'écriture. Comprendre y
 * était une tâche parmi douze, et c'est celle qui perdait. Elle a donc son propre
 * appel : petit, sans aucun outil, sur un modèle léger.
 *
 * L'ÉCONOMIE, PARCE QUE C'EST LA VRAIE QUESTION. Ce troisième appel n'en est un que
 * sur les messages qu'il ne change pas (+10 à +17 %). Sur un message ambigu, il
 * REMPLACE les deux gros : au lieu de payer une planification complète pour une
 * réponse à côté — puis la relance de l'utilisateur, soit quatre appels —, on paie
 * le plus petit et on s'arrête. Il tourne en outre sur un modèle distinct, dont
 * Google tient un compteur de débit séparé : il ne mange pas la fenêtre du modèle
 * principal.
 *
 * FAIL-OPEN, SANS EXCEPTION. Panne, quota, JSON illisible, débit indisponible : on
 * conclut que la demande est claire et on laisse passer. Le comprenant est là pour
 * améliorer une réponse, jamais pour empêcher qu'il y en ait une — bloquer
 * l'utilisateur parce que notre garde-fou est tombé serait pire que le mal qu'il
 * corrige.
 */
final class Comprehenseur
{
    /**
     * Assez pour une intention de trois phrases et quelques questions courtes. Le
     * modèle n'a rien d'autre à écrire : un plafond haut n'achèterait ici que du
     * raisonnement interne facturé.
     */
    private const MAX_OUTPUT_TOKENS = 700;

    /** Un comprenant lent est un comprenant inutile : mieux vaut passer outre. */
    private const TIMEOUT_SECONDES = 20;

    /**
     * Réponses par lesquelles un utilisateur ACQUIESCE. Elles ne veulent rien dire
     * seules, et tout dire après le tour précédent : les soumettre au comprenant,
     * c'est lui garantir une fausse ambiguïté sur le message le plus clair du fil.
     */
    private const ACQUIESCEMENTS = '/^(oui|ok|okay|d\'accord|daccord|je confirme|confirme|'
        . 'vas.?y|allez.?y|go|c\'est bon|parfait|exact|tout à fait|continue|poursuis)[\s.!]*$/iu';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        // Source unique du prompt : la compréhension est une PHASE, au même titre
        // que la planification et la rédaction, et son texte vit avec les leurs.
        private readonly AiContextBuilder $contextBuilder,
        private readonly ProgrammeEnCours $programmeEnCours,
        // Les particularités du proto Gemini, partagées avec le moteur.
        private readonly DialecteGemini $dialecte,
        // Le seul chemin vers le code métier, partagé avec les deux moteurs. La garde
        // de périmètre reste dans chaque execute() : comprendre ne donne aucun droit.
        private readonly ExecuteurDOutils $executeur,
        private readonly BudgetDebit $budget,
        private readonly JournalTokens $journal,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODELE_COMPREHENSION')] private readonly string $modele,
    ) {
    }

    public function modelName(): string
    {
        return $this->modele;
    }

    /**
     * @param array<int, array>|null $contents historique au dialecte Gemini. Le moteur
     *                                         l'a déjà construit et le passe : le refaire
     *                                         ici en ferait deux vérités d'une même
     *                                         conversation. Les appelants qui n'en ont
     *                                         pas (commande de diagnostic) laissent null.
     */
    public function comprendre(AiRequest $request, ?array $contents = null): DemandeComprise
    {
        $debut = microtime(true);
        $brut = $request->lastUserMessage();
        $contents ??= array_map(
            static fn (array $m) => [
                'role'  => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ],
            $request->messages,
        );

        if ($this->serveurSaitDeja($request)) {
            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_COURT_CIRCUIT), 0, $debut);
        }

        // On ne PATIENTE jamais avant cet appel. « symfony serve » n'a qu'un worker
        // php-cgi : une requête qui dort fige toute l'application. Et faire attendre
        // l'utilisateur pour une phase qui ne fait qu'améliorer sa réponse serait un
        // marché perdant.
        if ($this->budget->secondesAvantLiberation($this->modele, self::MAX_OUTPUT_TOKENS) !== 0) {
            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI), 0, $debut);
        }

        try {
            [$reponse, $tokens] = $this->conclure($request, $contents);
        } catch (\Throwable $e) {
            $this->logger->warning('Assistant IA : la phase de compréhension a échoué, la demande passe telle quelle.', [
                'exception' => $e,
                'modele'    => $this->modele,
            ]);

            return $this->journaliser($request, DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI), 0, $debut);
        }

        return $this->journaliser($request, $this->interpreter($reponse, $request), $tokens, $debut);
    }

    /**
     * Le ou les appels de la phase, et le total des tokens d'entrée consommés.
     *
     * UN SEUL TOUR D'OUTILS, exactement comme la planification. Le comprenant peut
     * vérifier en base — « ce client existe-t-il ? », « y a-t-il plusieurs polices à
     * ce nom ? » — parce que sans cela il poserait une question là où une recherche
     * aurait tranché, ou reformulerait vers un objet inexistant. Mais il ne CHAÎNE
     * pas : c'est l'enchaînement, et lui seul, qui a saturé le quota le 2026-08-10.
     *
     * POURQUOI DEUX APPELS QUAND IL CHERCHE. Gemini refuse `tools` et
     * `responseMimeType: application/json` dans la même requête. Le premier appel
     * porte donc les outils sans schéma de sortie, le second le schéma sans les
     * outils. Quand le modèle n'appelle aucun outil — le cas courant —, il n'y a
     * qu'un seul appel et son texte est lu tel quel.
     *
     * @param array<int, array> $contents
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function conclure(AiRequest $request, array $contents): array
    {
        $reponse = $this->appeler($request, $contents, avecOutils: true);
        $tokens = $this->facturer($reponse);

        $parts = $reponse['candidates'][0]['content']['parts'] ?? [];
        $appels = array_values(array_filter($parts, static fn (array $p) => isset($p['functionCall'])));
        if ($appels === []) {
            return [$reponse, $tokens];
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

        return [$reponse, $tokens + $this->facturer($reponse)];
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

    /**
     * Les cas où le SERVEUR connaît déjà l'intention — aucun appel, aucun token.
     *
     * Ce sont les mêmes signaux structurels que l'aiguillage de trousse : ils ne
     * devinent rien, ils CONSTATENT l'état du fil.
     */
    private function serveurSaitDeja(AiRequest $request): bool
    {
        $conversation = $request->scope->conversation;

        // Une barre de décision attend une réponse : « je confirme » porte tout son
        // sens, et reformuler une validation n'aurait aucun objet.
        if (PlanEnAttente::aUnPlanEnAttente($conversation)) {
            return true;
        }
        if ($this->programmeEnCours->courant($conversation) !== null) {
            return true;
        }
        // GARDE ANTI-BOUCLE. Le tour précédent était déjà une clarification : reposer
        // la question enfermerait l'utilisateur dans un dialogue de sourds bien pire
        // que la mécompréhension d'origine. On ne clarifie JAMAIS deux fois de suite.
        if (ClarificationEnAttente::enAttente($conversation)) {
            return true;
        }
        // Un acquiescement, quand il y a bien quelque chose à acquiescer.
        if ($conversation?->dernierMessageAssistant() !== null
            && preg_match(self::ACQUIESCEMENTS, trim($request->lastUserMessage())) === 1) {
            return true;
        }

        return false;
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
            'json' => [
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
                : ['tools' => [['functionDeclarations' => $declarations]]]),
            'timeout' => self::TIMEOUT_SECONDES,
        ])->toArray();
    }

    /**
     * @param array<string, mixed> $reponse
     */
    private function interpreter(array $reponse, AiRequest $request): DemandeComprise
    {
        $brut = $request->lastUserMessage();

        $texte = '';
        foreach ($reponse['candidates'][0]['content']['parts'] ?? [] as $part) {
            $texte .= (string) ($part['text'] ?? '');
        }

        // Le tour qui porte les outils ne peut pas imposer de schéma de sortie
        // (Gemini refuse les deux ensemble) : quand il conclut directement, son JSON
        // arrive parfois enveloppé dans une clôture markdown. On la retire — refuser
        // une réponse juste pour trois caractères de décoration serait absurde.
        $texte = trim($texte);
        if (str_starts_with($texte, '```')) {
            $texte = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $texte));
        }

        $sortie = json_decode($texte, true);
        $intention = is_array($sortie) ? trim((string) ($sortie['intention'] ?? '')) : '';

        // Sortie inexploitable ou vide : on n'a rien appris, on ne bloque rien.
        if (!is_array($sortie) || $intention === '') {
            return DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI);
        }

        // GARDE ANTI-DÉRIVE. Un modèle qui reformule invente des chiffres — c'est la
        // faute du « budget fabriqué » du 2026-08-12, sous une autre forme. Un montant
        // ou une date apparus de nulle part dans l'intention la disqualifient
        // ENTIÈREMENT : mieux vaut la demande brute, que personne n'a réécrite.
        if ($this->inventeUnChiffre($intention, $request)) {
            $this->logger->warning('Assistant IA : reformulation écartée, elle porte un chiffre absent du fil.', [
                'intention' => mb_substr($intention, 0, 200),
            ]);

            return DemandeComprise::claire($brut, DemandeComprise::ORIGINE_REPLI);
        }

        return ($sortie['claire'] ?? true) === false
            ? DemandeComprise::aClarifier($intention, (array) ($sortie['questions'] ?? []))
            : DemandeComprise::claire($intention);
    }

    /**
     * L'intention porte-t-elle un nombre qu'aucun message récent ne contient ?
     *
     * Les séparateurs de milliers sont neutralisés des deux côtés : « 12 000 » dicté
     * et « 12000 » reformulé sont le même montant, et croire le contraire ferait
     * rejeter les reformulations justes.
     */
    private function inventeUnChiffre(string $intention, AiRequest $request): bool
    {
        $sansEspaces = static fn (string $t): string => (string) preg_replace('/[\s\x{00A0}.,\']+/u', '', $t);

        $recent = '';
        foreach (array_slice($request->messages, -3) as $message) {
            $recent .= ' ' . (string) ($message['content'] ?? '');
        }
        $recent = $sansEspaces($recent);

        preg_match_all('/\d{2,}/', $sansEspaces($intention), $nombres);
        foreach ($nombres[0] as $nombre) {
            if (!str_contains($recent, $nombre)) {
                return true;
            }
        }

        return false;
    }

    private function journaliser(AiRequest $request, DemandeComprise $comprise, int $tokens, float $debut): DemandeComprise
    {
        $this->journal->comprehension(
            $request,
            $this->modele,
            $comprise->claire ? 'claire' : 'a_clarifier',
            $comprise->origine,
            $tokens,
            (int) round((microtime(true) - $debut) * 1000),
        );

        return $comprise;
    }
}
