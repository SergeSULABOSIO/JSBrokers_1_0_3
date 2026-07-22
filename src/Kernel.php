<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Fixe l'horloge applicative avant tout traitement (web ET CLI) : sans cela
     * l'application hérite silencieusement du date.timezone du php.ini de la
     * machine, qui peut différer d'un serveur à l'autre — et les colonnes
     * DATETIME, naïves, deviendraient ambiguës. Repli sur le réglage PHP courant
     * si APP_TIMEZONE est absent ou invalide.
     */
    public function boot(): void
    {
        $timezone = $_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? null;
        if (is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timezone);
        }

        parent::boot();
    }
}
