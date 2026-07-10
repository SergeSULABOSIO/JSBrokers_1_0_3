<?php

namespace App\Ai;

/**
 * Normalisation de texte partagée par le moteur simulé et ses outils :
 * minuscules + accents retirés, pour un matching par mots-clés robuste.
 */
final class AiText
{
    public static function normalize(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');

        return strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'œ' => 'oe',
        ]);
    }
}
