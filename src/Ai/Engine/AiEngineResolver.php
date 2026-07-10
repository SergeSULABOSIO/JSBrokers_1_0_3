<?php

namespace App\Ai\Engine;

use App\Ai\AiReply;
use App\Ai\AiRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sélectionne le moteur de l'assistant à l'exécution : dès qu'une clé
 * ANTHROPIC_API_KEY est configurée, le moteur réel (API Claude) répond ;
 * sans clé, repli automatique sur le simulateur déterministe — les
 * environnements de dev/test fonctionnent donc sans réseau ni secret.
 *
 * C'est ce service que l'alias AiEngineInterface pointe (services.yaml).
 */
final class AiEngineResolver implements AiEngineInterface
{
    public function __construct(
        private readonly SimulatedAiEngine $simulated,
        private readonly AnthropicAiEngine $anthropic,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $apiKey,
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
        return trim($this->apiKey) !== '' ? $this->anthropic : $this->simulated;
    }
}
