<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Le rendu correspondant à un format — liste DÉRIVÉE du code, jamais recopiée.
 *
 * Même principe que TrousseCatalogue et OutilsDePlan : ajouter un format se fait en
 * ajoutant une classe taguée, et l'oubli devient impossible plutôt qu'improbable.
 */
final class RapportRendererResolver
{
    /** @var array<string, RapportRendererInterface>|null */
    private ?array $parFormat = null;

    /** @param iterable<RapportRendererInterface> $renderers */
    public function __construct(
        #[AutowireIterator('app.ai_document_renderer')] private readonly iterable $renderers,
    ) {
    }

    /**
     * @throws \InvalidArgumentException si aucun rendu ne couvre ce format (le
     *                                   contrôleur et l'enum le filtrent en amont)
     */
    public function pour(DocumentFormat $format): RapportRendererInterface
    {
        return $this->indexe()[$format->value]
            ?? throw new \InvalidArgumentException(sprintf('Aucun rendu pour le format « %s ».', $format->value));
    }

    public function couvre(DocumentFormat $format): bool
    {
        return isset($this->indexe()[$format->value]);
    }

    /** @return list<DocumentFormat> */
    public function formatsDisponibles(): array
    {
        $formats = array_map(
            static fn (string $valeur) => DocumentFormat::from($valeur),
            array_keys($this->indexe()),
        );

        // Ordre de l'enum : stable d'un appel à l'autre, donc l'interface ne voit
        // pas ses options se réordonner d'un tour de chat au suivant.
        usort($formats, static fn (DocumentFormat $a, DocumentFormat $b) => array_search($a, DocumentFormat::cases(), true)
            <=> array_search($b, DocumentFormat::cases(), true));

        return $formats;
    }

    /** @return array<string, RapportRendererInterface> */
    private function indexe(): array
    {
        if ($this->parFormat !== null) {
            return $this->parFormat;
        }

        $indexe = [];
        foreach ($this->renderers as $renderer) {
            $indexe[$renderer->format()->value] = $renderer;
        }

        return $this->parFormat = $indexe;
    }
}
