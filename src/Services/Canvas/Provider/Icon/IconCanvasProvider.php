<?php

namespace App\Services\Canvas\Provider\Icon;

class IconCanvasProvider
{
    /**
     * @var array<string, string>
     */
    private const ICON_MAP = [
        'assureur' => 'segments/icones/assureur.html.twig',
        'contact' => 'segments/icones/contact.html.twig',
        // Ajoutez ici d'autres icônes au fur et à mesure que vous créez les fichiers Twig.
    ];

    public function getTemplateForIcon(string $iconName): ?string
    {
        return self::ICON_MAP[$iconName] ?? null;
    }
}
