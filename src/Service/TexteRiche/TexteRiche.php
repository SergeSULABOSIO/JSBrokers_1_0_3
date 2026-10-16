<?php

namespace App\Service\TexteRiche;

/**
 * LE TEXTE RICHE — SOURCE UNIQUE.
 *
 * Tout `<textarea>` d'un dialogue est un éditeur Quill (_form_row.html.twig) : ces champs
 * stockent du HTML. Ket, les imports et les workflows y écrivent, eux, du texte brut.
 *
 * Là où un tel champ se LIT comme du texte (e-mail, libellé de choix, aperçu), on n'en
 * rend jamais le HTML : on en garde la substance. C'est la conversion qu'appliquait déjà,
 * seule, la complétude du cabinet (OnboardingCompletude), ici rendue partageable.
 *
 * Limite connue : un « < » collé à une lettre (« x<y ») est lu comme une balise par
 * strip_tags et disparaît ; « a < b », avec espace, est conservé.
 */
final class TexteRiche
{
    /** Balises de bloc remplacées par une espace : « <p>Un</p><p>deux</p> » ≠ « Undeux ». */
    private const FINS_DE_BLOC = ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</h1>', '</h2>', '</h3>'];

    public static function enTexte(?string $brut): string
    {
        if ($brut === null || $brut === '') {
            return '';
        }

        $texte = strip_tags(str_ireplace(self::FINS_DE_BLOC, ' ', $brut));
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // L'espace insécable que Quill écrit pour les blancs successifs est une espace.
        $texte = str_replace("\u{00A0}", ' ', $texte);

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }
}
