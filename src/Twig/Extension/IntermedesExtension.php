<?php

namespace App\Twig\Extension;

use App\Ai\Live\IntermedesDeKet;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose au mode Live le catalogue des intermèdes de Ket (`intermedes_de_ket()`) :
 * les clés des petites phrases qu'il dit pendant qu'il réfléchit.
 *
 * Le navigateur ne reçoit que des CLÉS et les précharge ; le TEXTE, lui, ne quitte
 * jamais le serveur (source unique IntermedesDeKet). Une phrase reformulée ne
 * demande donc aucune modification du JavaScript.
 */
class IntermedesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('intermedes_de_ket', [$this, 'cles']),
        ];
    }

    /** @return array<string, list<string>> moment => clés */
    public function cles(): array
    {
        $cles = [];
        foreach (IntermedesDeKet::catalogue() as $moment => $phrases) {
            $cles[$moment] = array_keys($phrases);
        }

        return $cles;
    }
}
