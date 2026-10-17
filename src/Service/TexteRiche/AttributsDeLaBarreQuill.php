<?php

namespace App\Service\TexteRiche;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * LES VALEURS D'ATTRIBUTS QUE LA BARRE D'OUTILS DE QUILL PRODUIT — et elles seules.
 *
 * L'assainisseur autorise des attributs (config/packages/html_sanitizer.yaml) ; ce filtre
 * en borne les VALEURS. C'est le pendant serveur de texte-riche-regles.js : les deux disent
 * la même chose, l'un à l'écriture, l'autre à l'affichage.
 *
 * Aucune valeur admise ne contient de guillemet : c'est ce qui permet à TexteRiche de
 * décoder, après assainissement, les entités que l'assainisseur pose sur les caractères
 * inoffensifs d'un texte (" ' @ + = `).
 */
final class AttributsDeLaBarreQuill implements AttributeSanitizerInterface
{
    private const COULEUR = '/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/i';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['class', 'style', 'data-list', 'contenteditable'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $filtre = match ($attribute) {
            'class' => self::classes($value),
            'style' => self::style($value),
            'data-list' => \in_array($value, ['ordered', 'bullet'], true) ? $value : '',
            'contenteditable' => 'false',
            default => '',
        };

        return $filtre === '' ? null : $filtre;
    }

    /** Les classes de Quill seulement (`ql-align-center`, `ql-ui`…). */
    public static function classes(string $valeur): string
    {
        return implode(' ', array_values(array_filter(
            preg_split('/\s+/', trim($valeur)) ?: [],
            static fn (string $c): bool => (bool) preg_match('/^ql-[a-z0-9-]+$/', $c),
        )));
    }

    /** `color` et `background-color` à valeur de couleur ; rien d'autre. */
    public static function style(string $valeur): string
    {
        $gardees = [];
        foreach (explode(';', $valeur) as $declaration) {
            $parties = explode(':', $declaration, 2);
            if (\count($parties) !== 2) {
                continue;
            }
            $nom = strtolower(trim($parties[0]));
            $couleur = trim($parties[1]);
            if (\in_array($nom, ['color', 'background-color'], true) && preg_match(self::COULEUR, $couleur)) {
                $gardees[] = $nom . ': ' . $couleur;
            }
        }

        return implode('; ', $gardees);
    }
}
