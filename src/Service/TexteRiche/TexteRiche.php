<?php

namespace App\Service\TexteRiche;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * LE TEXTE RICHE — SOURCE UNIQUE.
 *
 * Tout `<textarea>` d'un dialogue est un éditeur Quill (_form_row.html.twig) : ces champs
 * stockent du HTML (registre : ChampsEnTexteRiche). Ket, les imports et les workflows y
 * écrivent, eux, du texte brut.
 *
 *  - estRiche()  : la valeur est-elle le HTML de l'éditeur ? Quill enveloppe TOUJOURS son
 *                  contenu dans un bloc (<p>, <ol>, <ul>, <h1>-<h3>) — vérifié sur la base :
 *                  toutes les valeurs balisées commencent par <p>. Un texte simple n'est
 *                  jamais lu comme du HTML (même règle que texte-riche-regles.js).
 *  - assainir()  : le HTML de l'éditeur, réduit à ce que la barre d'outils produit
 *                  (config/packages/html_sanitizer.yaml, AttributsDeLaBarreQuill). Appelé à
 *                  l'ÉCRITURE par AssainissementDuTexteRicheListener.
 *  - enTexte()   : la substance, sans balises ni entités, là où le champ se LIT comme du
 *                  texte (e-mail, libellé, aperçu, export, Ket).
 *
 * Limite connue d'enTexte : un « < » collé à une lettre (« x<y ») est lu comme une balise
 * par strip_tags et disparaît ; « a < b », avec espace, est conservé.
 */
final class TexteRiche
{
    /** Balises de bloc remplacées par une espace : « <p>Un</p><p>deux</p> » ≠ « Undeux ». */
    private const FINS_DE_BLOC = ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</h1>', '</h2>', '</h3>'];

    /**
     * Entités que l'assainisseur pose sur des caractères INOFFENSIFS d'un texte : les
     * décoder évite qu'un paragraphe gonfle (« c@d.com » → « c&#64;d.com ») au point de
     * dépasser la colonne. Sûr ici : aucune valeur d'attribut admise ne contient de
     * guillemet (AttributsDeLaBarreQuill). `&lt;` et `&amp;` restent encodés.
     */
    // L'apostrophe sort en `&#039;` (htmlspecialchars, ENT_QUOTES) : sans cette clé, chaque
    // apostrophe d'un texte français coûtait 5 caractères, jusqu'au refus 422.
    private const ENTITES_INOFFENSIVES = ['&#34;' => '"', '&#039;' => "'", '&#39;' => "'", '&#64;' => '@', '&#43;' => '+', '&#61;' => '=', '&#96;' => '`'];

    public function __construct(
        // CIBLÉ EXPLICITEMENT : sans #[Target], un nom de paramètre qui ne colle pas à
        // l'alias livre en silence l'assainisseur PAR DÉFAUT, sans cette liste blanche.
        #[Target('texte_riche')]
        private readonly HtmlSanitizerInterface $texteRicheSanitizer,
    ) {
    }

    public static function estRiche(mixed $valeur): bool
    {
        // Espace insécable et BOM comptés comme des blancs, comme le `\s` du navigateur
        // (texte-riche-regles.js) : sinon le serveur laissait passer sans l'assainir une
        // valeur que l'écran lisait comme riche.
        return \is_string($valeur) && 1 === preg_match('/^[\s\x{00A0}\x{FEFF}]*<(p|ol|ul|h[1-3])[\s>]/iu', $valeur);
    }

    /** Le HTML de l'éditeur, réduit à la liste blanche de la barre d'outils. */
    public function assainir(string $html): string
    {
        return strtr($this->texteRicheSanitizer->sanitize($html), self::ENTITES_INOFFENSIVES);
    }

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

    /**
     * Ce qu'un écran affiche d'un champ QUI PEUT être riche : le HTML de l'éditeur devient
     * du texte, un texte simple reste tel quel (« A<B » n'est pas une balise à retirer), une
     * autre valeur (nombre, date, null) passe sans changement. Le résultat reste à échapper.
     */
    public static function lisible(mixed $valeur): mixed
    {
        return self::estRiche($valeur) ? self::enTexte($valeur) : $valeur;
    }
}
