<?php

namespace App\Twig\Extension;

use App\Ai\Fichier\FichierAttachePolicy;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose au front la politique des fichiers attachables au chat de l'assistant
 * IA (`attached_file_limits()`) : taille max, nombre max et formats autorisés.
 * Source UNIQUE (FichierAttachePolicy) partagée par la validation JS (avant
 * upload) et la contrainte serveur — une seule vérité pour les deux barrières.
 */
class FichierAttacheExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('attached_file_limits', [FichierAttachePolicy::class, 'limitesFront']),
        ];
    }
}
