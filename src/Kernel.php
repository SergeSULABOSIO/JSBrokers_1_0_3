<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        self::exigerUnPosteDeDeveloppement(
            $environment,
            \dirname(__DIR__),
            \PHP_SAPI,
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_FORWARDED']),
        );
        parent::__construct($environment, $debug);
    }

    /**
     * L'ENVIRONNEMENT e2e N'EXISTE QUE SUR LE POSTE DE DÉVELOPPEMENT (chantier 15).
     *
     * Il sert au navigateur piloté (Edge headless) : base de test, e-mails et IA neutralisés,
     * sessions natives à part (config/packages/framework.yaml). Décision de Serge : impossible
     * à activer en production — ni par `.env`, ni par la configuration Apache. Il exige donc
     * `.env.e2e.local`, que git ignore (`/.env.*.local`) et qu'aucun déploiement ne porte, et
     * ne répond qu'aux requêtes de la machine elle-même. Hors de ces conditions : refus net,
     * plutôt qu'un site qui tournerait sur la base de test.
     *
     * Une requête RELAYÉE (`X-Forwarded-For`, `Forwarded`) est refusée aussi : un tunnel local
     * (ngrok, cloudflared, redirection de port) fait passer Internet pour 127.0.0.1.
     */
    public static function exigerUnPosteDeDeveloppement(string $environment, string $racine, string $sapi, ?string $adresse, bool $relayee = false): void
    {
        if ('e2e' !== $environment) {
            return;
        }
        if (!is_file($racine . '/.env.e2e.local')) {
            throw new \LogicException("L'environnement e2e ne démarre que sur un poste de développement (.env.e2e.local absent).");
        }
        if ('cli' !== $sapi && ($relayee || !\in_array($adresse, ['127.0.0.1', '::1'], true))) {
            throw new \LogicException("L'environnement e2e ne répond qu'aux requêtes locales.");
        }
    }

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
