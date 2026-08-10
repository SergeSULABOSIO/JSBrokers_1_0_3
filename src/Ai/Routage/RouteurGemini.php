<?php

namespace App\Ai\Routage;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Trousse\Trousse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le tour de routage chez Gemini : un appel court, en sortie STRUCTURÉE.
 *
 * Volontairement dépourvu de tout ce qui alourdit un tour ordinaire — pas d'outils
 * déclarés, pas de boucle, pas de pièces jointes. Le préfixe (consigne + catalogue)
 * est STABLE d'un message à l'autre, donc mis en cache par le fournisseur au même
 * titre que celui des tours ordinaires.
 *
 * Le débit consommé est déclaré comme celui de n'importe quel tour : le quota par
 * minute est partagé, et un routage qui ne se compterait pas ferait saturer le tour
 * suivant sans qu'on sache pourquoi.
 *
 * TOUTE panne rend null — jamais une exception. Un routage indisponible ne doit pas
 * empêcher de répondre : l'appelant retombe sur la trousse complète.
 */
final class RouteurGemini implements RouteurModele
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** Une décision tient en un mot ; ce plafond n'existe que pour borner une dérive. */
    private const MAX_OUTPUT_TOKENS = 64;

    /** Court par construction : au-delà, mieux vaut la trousse complète que faire attendre. */
    private const TIMEOUT_SECONDES = 12;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        // Le routage est une tâche de classification : le modèle le plus léger suffit,
        // et son quota par minute est SÉPARÉ de celui du modèle principal — router ne
        // prélève donc rien sur le débit dont la réponse aura besoin.
        #[Autowire(env: 'GEMINI_MODELE_ROUTAGE')] private readonly string $modele,
        private readonly BudgetDebit $budget,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function choisirTrousse(string $instruction, string $catalogue, array $messages): array
    {
        if (trim($this->apiKey) === '' || trim($this->modele) === '') {
            return ['trousse' => null, 'tokens' => 0];
        }

        $contents = array_map(
            static fn (array $m) => [
                'role'  => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ],
            $messages,
        );

        try {
            $reponse = $this->httpClient->request(
                'POST',
                sprintf('%s/%s:generateContent', self::API_BASE, $this->modele),
                [
                    'headers' => ['x-goog-api-key' => $this->apiKey, 'content-type' => 'application/json'],
                    'json'    => [
                        'systemInstruction' => ['parts' => [['text' => $instruction . "\n\n" . $catalogue]]],
                        'contents'          => $contents,
                        'generationConfig'  => [
                            'maxOutputTokens'  => self::MAX_OUTPUT_TOKENS,
                            // Une classification ne se discute pas : on veut la réponse
                            // la plus probable, pas une variation créative.
                            'temperature'      => 0,
                            'responseMimeType' => 'application/json',
                            'responseSchema'   => [
                                'type'       => 'object',
                                'properties' => [
                                    'trousse' => [
                                        'type' => 'string',
                                        'enum' => [Trousse::LECTURE->value, Trousse::ECRITURE->value],
                                    ],
                                ],
                                'required' => ['trousse'],
                            ],
                        ],
                    ],
                    'timeout' => self::TIMEOUT_SECONDES,
                ],
            )->toArray();
        } catch (\Throwable $e) {
            // Y compris un 429 : router n'est pas répondre. On rend la main.
            $this->logger->warning('Routage de trousse indisponible, repli sur la trousse complète.', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return ['trousse' => null, 'tokens' => 0];
        }

        $tokens = (int) ($reponse['usageMetadata']['promptTokenCount'] ?? 0);
        if ($tokens > 0) {
            $this->budget->enregistrer($this->modele, $tokens);
        }

        $texte = '';
        foreach ($reponse['candidates'][0]['content']['parts'] ?? [] as $part) {
            $texte .= (string) ($part['text'] ?? '');
        }

        $decode = json_decode(trim($texte), true);
        $choix = is_array($decode) ? ($decode['trousse'] ?? null) : null;

        return [
            'trousse' => Trousse::tryFrom((string) $choix)?->value,
            'tokens'  => $tokens,
        ];
    }
}
