<?php

namespace App\Twig\Extension;

use App\Services\ServiceMonnaies;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CurrencyExtension extends AbstractExtension
{
    public function __construct(private ServiceMonnaies $serviceMonnaies) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_currency_code', [$this, 'getDisplayCurrencyCode']),
        ];
    }

    public function getDisplayCurrencyCode(): string
    {
        // Sans monnaie de travail configurée : USD, le défaut d'un cabinet (jamais l'euro,
        // qu'aucun cabinet ne reçoit).
        return $this->serviceMonnaies->getCodeMonnaieAffichage() ?? 'USD';
    }
}
