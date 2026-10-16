<?php

namespace App\Twig\Extension;

use App\Service\TexteRiche\TexteRiche;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ champ|en_texte }}` : un champ saisi dans l'éditeur riche, lu comme du texte — sans
 * balises ni entités. Le résultat n'est PAS marqué sûr : Twig l'échappe comme tout texte.
 */
class TexteRicheExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('en_texte', [TexteRiche::class, 'enTexte']),
        ];
    }
}
