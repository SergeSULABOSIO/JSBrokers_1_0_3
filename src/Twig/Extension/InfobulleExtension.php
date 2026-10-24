<?php

namespace App\Twig\Extension;

use App\Service\Infobulle\InfobulleContexte;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ infobulle_contexte(typeLabel, label, fiche)|json_encode|e('html_attr') }}` : le contenu de
 * l'infobulle unique d'une puce de contexte du chat de Ket (InfobulleContexte).
 */
class InfobulleExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('infobulle_contexte', [InfobulleContexte::class, 'sections']),
        ];
    }
}
