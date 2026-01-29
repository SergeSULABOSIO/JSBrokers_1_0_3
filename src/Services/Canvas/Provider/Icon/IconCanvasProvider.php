<?php

namespace App\Services\Canvas\Provider\Icon;

class IconCanvasProvider
{
    /**
     * @var array<string, string>
     */
    private const ICON_ALIAS_MAP = [
        'assureur' => 'wpf:security-checked',
        'contact' => 'hugeicons:contact-01',
        // Ajoutez ici d'autres alias au fur et à mesure.
    ];

    /**
     * Résout un nom d'alias d'icône en son vrai nom (ex: 'assureur' -> 'wpf:security-checked').
     * Si le nom n'est pas un alias, il retourne null pour que l'appelant puisse utiliser le nom original.
     */
    public function resolveIconName(string $alias): ?string
    {
        return self::ICON_ALIAS_MAP[$alias] ?? null;
    }
}
