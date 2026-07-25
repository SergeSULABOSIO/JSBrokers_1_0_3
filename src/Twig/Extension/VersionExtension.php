<?php

namespace App\Twig\Extension;

use App\Services\VersionService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la version applicative aux gabarits :
 * `Version {{ app_version() }} — {{ app_version_date()|date('d/m/Y') }}`.
 * À ne pas confondre avec la version des CGU (App\Legal\Cgu), qui reste dédiée
 * à la page des conditions et à la preuve d'acceptation juridique.
 */
class VersionExtension extends AbstractExtension
{
    public function __construct(private VersionService $versionService) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_version', [$this->versionService, 'getVersion']),
            new TwigFunction('app_version_date', [$this->versionService, 'getDate']),
        ];
    }
}
