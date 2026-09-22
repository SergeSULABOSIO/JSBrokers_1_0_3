<?php

namespace App\Ai\Dictee;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Engine\Usage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La finition de dictée chez Anthropic.
 *
 * La sortie est imposée par un OUTIL forcé, comme pour la phase de compréhension :
 * le modèle ne peut rendre qu'un objet `{"texte": "..."}` conforme au schéma. Cela
 * remplace le `responseSchema` de Google et, surtout, cela interdit la prose —
 * une finition qui répondrait « voici votre texte : … » ferait échouer le contrôle
 * de fidélité et l'utilisateur récupérerait sa dictée brute sans comprendre
 * pourquoi.
 */
final class FinitionAnthropic implements FournisseurDeFinition
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /** L'outil par lequel le modèle rend le texte mis au propre. */
    private const OUTIL = 'rendre_le_texte';

    /** Une finition lente fait attendre quelqu'un qui a fini de parler. */
    private const TIMEOUT_SECONDES = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BudgetDebit $budget,
        private readonly MemoireDEpuisement $epuisement,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'ANTHROPIC_MODELE_COMPREHENSION')] private readonly string $modele,
        #[Autowire(env: 'AI_ENGINE')] private readonly string $moteurForce = '',
    ) {
    }

    public function nom(): string
    {
        return 'anthropic';
    }

    public function modele(): string
    {
        return $this->modele;
    }

    public function cleDeDebit(): string
    {
        return 'anthropic:in:' . $this->modele;
    }

    public function estDisponible(): bool
    {
        return trim($this->apiKey) !== '' && strtolower(trim($this->moteurForce)) !== 'simulated';
    }

    /** La clé de ce fournisseur dans la mémoire d'épuisement — famille comprise. */
    public function cleDEpuisement(): string
    {
        return MemoireDEpuisement::cle('dictee', 'anthropic', $this->modele);
    }

    public function estEpuise(): bool
    {
        return $this->epuisement->estEpuise($this->cleDEpuisement());
    }

    public function finir(string $consigne, string $brut, int $plafondSortie): array
    {
        $reponse = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'       => $this->modele,
                'max_tokens'  => $plafondSortie,
                'temperature' => 0.0,
                'system'      => $consigne,
                'messages'    => [['role' => 'user', 'content' => $brut]],
                'tools'       => [[
                    'name'         => self::OUTIL,
                    'description'  => 'Rends la dictée mise au propre, et rien d’autre.',
                    'strict'       => true,
                    'input_schema' => [
                        'type'                 => 'object',
                        'properties'           => ['texte' => ['type' => 'string']],
                        'required'             => ['texte'],
                        'additionalProperties' => false,
                    ],
                ]],
                // Le texte libre est interdit : la sortie passe par l'outil ou rien.
                'tool_choice' => ['type' => 'tool', 'name' => self::OUTIL],
            ],
            'timeout' => self::TIMEOUT_SECONDES,
        ])->toArray();

        $usage = Usage::depuisAnthropic($reponse);
        $this->budget->enregistrer($this->cleDeDebit(), $usage->debit);

        $texte = '';
        foreach (($reponse['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) === 'tool_use' && ($bloc['name'] ?? null) === self::OUTIL) {
                $texte = trim((string) (((array) ($bloc['input'] ?? []))['texte'] ?? ''));
            }
        }

        return ['texte' => $texte, 'tokens' => $usage->entree];
    }
}
