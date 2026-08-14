<?php

namespace App\Ai;

/**
 * Normalisation de texte partagée par le moteur simulé et ses outils :
 * minuscules + accents retirés, pour un matching par mots-clés robuste.
 *
 * SOURCE UNIQUE, ET CE N'EST PAS UN LUXE. Trois normalisations coexistaient :
 * celle-ci, correcte, et deux copies fondées sur `iconv('UTF-8', 'ASCII//TRANSLIT')`.
 * Or sous Windows iconv ne translittère pas, il DÉCOMPOSE : « Société Générale »
 * devenait « soci`et`e g`en`erale », puis « soci et e g en erale » une fois la
 * ponctuation filtrée. Deux chaînes passées par la même moulinette continuaient de
 * s'égaler — les artefacts s'annulent —, ce qui rendait le défaut invisible ; mais
 * « Societe Generale » saisi sans accents ne pouvait PLUS JAMAIS égaler « Société
 * Générale » lu en base. La correspondance EXACTE de ResolveurDeReferences était donc
 * morte sur tout nom accentué, et un enregistrement existant pouvait être déclaré
 * introuvable — c'est-à-dire recréé en double.
 *
 * Toute nouvelle comparaison de libellés passe par ici. Aucune n'écrit sa propre table.
 */
final class AiText
{
    /**
     * Table EXPLICITE. Volontairement pas d'iconv, pas de \Normalizer (l'extension
     * intl n'est pas garantie) : le résultat ne doit dépendre ni de la plateforme,
     * ni de la locale, ni d'une extension optionnelle.
     */
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o', 'ò' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    public static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
    }

    /**
     * CLÉ DE COMPARAISON de deux libellés : minuscules, sans accents, toute
     * ponctuation ramenée à une espace simple.
     *
     * « Police N° 12 » et « police  n 12 » donnent la même clé ; « SUNU IARD RDC »
     * et « sunu-iard-rdc » aussi. C'est ce qui permet de dire qu'un enregistrement
     * EXISTE DÉJÀ au lieu d'en créer un second sous une orthographe voisine.
     */
    public static function cle(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', self::normalize(trim($text))));
    }
}
