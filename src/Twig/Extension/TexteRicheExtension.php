<?php

namespace App\Twig\Extension;

use App\Service\TexteRiche\TexteRiche;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ champ|en_texte }}` : un champ saisi dans l'éditeur riche, lu comme du texte — sans
 * balises ni entités. Le résultat n'est PAS marqué sûr : Twig l'échappe comme tout texte.
 *
 * `{{ valeur|texte_lisible }}` : une valeur qui PEUT être riche (cellule de liste, motif
 * saisi à l'écran ou par Ket) — convertie si c'est le HTML de l'éditeur, intacte sinon.
 */
class TexteRicheExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('en_texte', [TexteRiche::class, 'enTexte']),
            new TwigFilter('texte_lisible', [TexteRiche::class, 'lisible']),
        ];
    }
}
