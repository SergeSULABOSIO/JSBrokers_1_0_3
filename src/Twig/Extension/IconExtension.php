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

    /**
     * Résout un nom d'alias d'icône en son vrai nom.
     * Si le nom fourni est un alias connu (ex: 'assureur'), il retourne le vrai nom (ex: 'wpf:security-checked').
     * Sinon, il retourne le nom original tel quel (ex: 'lucide:file-text').
     *
     * @param string $iconName Le nom de l'icône ou son alias.
     * @return string Le nom résolu de l'icône.
     */
    public function resolveIconName(string $iconName): string
    {
        return $this->iconCanvasProvider->resolveIconName($iconName) ?? $iconName;
    }
}