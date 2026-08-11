<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;

/**
 * Rapport en TEXTE BRUT — le format qui s'ouvre partout, y compris dans trente ans.
 *
 * Deux partis pris de lisibilité :
 *  - fins de ligne CRLF, parce que le Bloc-notes de Windows reste le lecteur le
 *    plus probable et qu'un LF seul y produit un pavé d'une seule ligne ;
 *  - tableaux alignés en colonnes de largeur fixe, calculées sur le contenu réel.
 *    Sans cet alignement, un tableau de chiffres en texte brut est illisible — et
 *    c'est précisément ce qu'un courtier vient y chercher.
 */
final class TexteRapportRenderer implements RapportRendererInterface
{
    private const EOL = "\r\n";

    /** Au-delà, une colonne est tronquée : mieux vaut un tableau lisible que fidèle. */
    private const LARGEUR_MAX_COLONNE = 40;

    public function format(): DocumentFormat
    {
        return DocumentFormat::Txt;
    }

    /** Le thème est ignoré : un fichier texte n'a pas de couleurs. */
    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied, ThemeDocument $theme): string
    {
        $lignes = [];

        $lignes[] = mb_strtoupper($spec->titre);
        $lignes[] = str_repeat('=', min(78, max(10, mb_strlen($spec->titre))));
        $lignes[] = $pied->entreprise . ' — produit le ' . $pied->produitLe->format('d/m/Y à H:i');
        $lignes[] = '';

        array_push($lignes, ...$this->titre('1. OBJET DU DOCUMENT'), ...[$spec->problematique, '']);
        array_push($lignes, ...$this->titre('2. INTRODUCTION'), ...[$spec->introduction, '']);

        if ($spec->definitions !== []) {
            $lignes[] = 'Définitions des termes employés';
            $lignes[] = str_repeat('-', 30);
            foreach ($spec->definitions as $definition) {
                $lignes[] = '  * ' . $definition['terme'] . ' : ' . $definition['explication'];
            }
            $lignes[] = '';
        }

        $lignes = array_merge($lignes, $this->titre('3. RÉSULTAT'));

        foreach ($sections as $index => $section) {
            if (($section['titre'] ?? '') !== '') {
                $lignes[] = '3.' . ($index + 1) . ' ' . $section['titre'];
                $lignes[] = str_repeat('-', min(78, max(10, mb_strlen($section['titre']) + 4)));
            }
            foreach ($section['blocs'] as $bloc) {
                array_push($lignes, ...$this->bloc($bloc));
            }
        }

        array_push($lignes, ...$this->titre('4. CONCLUSION'), ...[$spec->conclusion, '']);

        $lignes[] = str_repeat('_', 78);
        $lignes[] = $pied->ligne();
        $lignes[] = '';

        return implode(self::EOL, $lignes);
    }

    /** @return list<string> */
    private function titre(string $texte): array
    {
        return [$texte, str_repeat('=', min(78, mb_strlen($texte))), ''];
    }

    /**
     * @param array<string, mixed> $bloc
     *
     * @return list<string>
     */
    private function bloc(array $bloc): array
    {
        switch ($bloc['type']) {
            case 'titre':
                return ['', $bloc['texte'], str_repeat('-', min(78, mb_strlen($bloc['texte']))), ''];

            case 'paragraphe':
                return [$bloc['texte'], ''];

            case 'code':
                return array_merge(
                    array_map(static fn (string $l) => '    ' . $l, explode("\n", $bloc['texte'])),
                    [''],
                );

            case 'liste':
                $lignes = [];
                foreach ($bloc['items'] as $rang => $item) {
                    $lignes[] = '  ' . ($bloc['ordonnee'] ? ($rang + 1) . '.' : '-') . ' ' . $item;
                }
                $lignes[] = '';

                return $lignes;

            case 'tableau':
                return $this->tableau($bloc);

            default:
                return [];
        }
    }

    /**
     * @param array<string, mixed> $bloc
     *
     * @return list<string>
     */
    private function tableau(array $bloc): array
    {
        $toutes = $bloc['entetes'] !== [] ? array_merge([$bloc['entetes']], $bloc['lignes']) : $bloc['lignes'];
        if ($toutes === []) {
            return [];
        }

        $colonnes = max(array_map('count', $toutes));
        $largeurs = array_fill(0, $colonnes, 0);
        foreach ($toutes as $ligne) {
            foreach (array_values($ligne) as $index => $cellule) {
                $largeurs[$index] = min(
                    self::LARGEUR_MAX_COLONNE,
                    max($largeurs[$index], mb_strlen($this->celluleTexte($cellule))),
                );
            }
        }

        $lignes = [];
        if (($bloc['titre'] ?? null) !== null) {
            $lignes[] = $bloc['titre'];
        }

        if ($bloc['entetes'] !== []) {
            $lignes[] = $this->ligneAlignee($bloc['entetes'], $largeurs, $colonnes);
            $lignes[] = implode('-+-', array_map(static fn (int $l) => str_repeat('-', $l), $largeurs));
        }
        foreach ($bloc['lignes'] as $ligne) {
            $lignes[] = $this->ligneAlignee($ligne, $largeurs, $colonnes);
        }
        $lignes[] = '';

        return $lignes;
    }

    /**
     * @param list<string> $ligne
     * @param list<int>    $largeurs
     */
    private function ligneAlignee(array $ligne, array $largeurs, int $colonnes): string
    {
        $cellules = [];
        for ($index = 0; $index < $colonnes; $index++) {
            $texte = $this->celluleTexte($ligne[$index] ?? '');
            if (mb_strlen($texte) > $largeurs[$index]) {
                $texte = mb_substr($texte, 0, max(1, $largeurs[$index] - 1)) . '…';
            }
            // mb_str_pad n'existe qu'à partir de PHP 8.3 : on complète à la main
            // pour que l'alignement tienne aussi sur du texte accentué.
            $cellules[] = $texte . str_repeat(' ', max(0, $largeurs[$index] - mb_strlen($texte)));
        }

        return rtrim(implode(' | ', $cellules));
    }

    private function celluleTexte(string $valeur): string
    {
        return trim(str_replace(["\n", "\r"], ' ', $valeur));
    }
}
