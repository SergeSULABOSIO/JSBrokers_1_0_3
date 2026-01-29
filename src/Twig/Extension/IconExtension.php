<?php

namespace App\Twig\Extension;

use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class IconExtension extends AbstractExtension
{
    public function __construct(
        private IconCanvasProvider $iconCanvasProvider
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('resolve_icon_name', [$this, 'resolveIconName']),
        ];
    }

    public function resolveIconName(string $iconName): string
    {
        // Si c'est un alias personnalisé (ex: 'assureur'), on le résout.
        // Sinon (ex: 'lucide:file-text'), on retourne le nom tel quel pour ne pas casser les icônes existantes.
        return $this->iconCanvasProvider->resolveIconName($iconName) ?? $iconName;
    }
}