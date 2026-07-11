<?php

namespace App\Ai\Engine;

use App\Ai\AiReply;
use App\Ai\AiRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sélectionne le moteur de l'assistant à l'exécution.
 *
 * AI_ENGINE (simulated | anthropic | gemini) force un moteur — c'est ce que
 * fait .env.test (simulated) pour que les tests n'appellent JAMAIS une API
 * réelle, même quand une clé traîne en variable d'environnement OS (qui
 * prime sur les .env). Vide = choix automatique par ordre de priorité :
 *   1. ANTHROPIC_API_KEY renseignée → API Claude (AnthropicAiEngine) ;
 *   2. sinon GEMINI_API_KEY renseignée → API Gemini (GeminiAiEngine) ;
 *   3. sinon → simulateur déterministe (aucun réseau ni secret requis).
 *
 * Le basculement est donc une pure affaire de .env.local — aucun code à
 * toucher pour comparer les fournisseurs. C'est ce service que l'alias
 * AiEngineInterface pointe (services.yaml).
 */
final class AiEngineResolver implements AiEngineInterface
{
    public function __construct(
        private readonly SimulatedAiEngine $simulated,
        private readonly AnthropicAiEngine $anthropic,
        private readonly GeminiAiEngine $gemini,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $anthropicApiKey,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $geminiApiKey,
        #[Autowire(env: 'AI_ENGINE')] private readonly string $force = '',
    ) {
    }

    public function name(): string
    {
        return $this->engine()->name();
    }

    public function reply(AiRequest $request): AiReply
    {
        return $this->engine()->reply($request);
    }

    private function engine(): AiEngineInterface
    {
        // Forçage explicite (AI_ENGINE) : prioritaire sur la détection par clés.
        switch (strtolower(trim($this->force))) {
            case 'simulated':
                return $this->simulated;
            case 'anthropic':
                return $this->anthropic;
            case 'gemini':
                return $this->gemini;
        }

        if (trim($this->anthropicApiKey) !== '') {
            return $this->anthropic;
        }
        if (trim($this->geminiApiKey) !== '') {
            return $this->gemini;
        }

        return $this->simulated;
    }
}
