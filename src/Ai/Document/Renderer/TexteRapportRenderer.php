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
 *  - tableaux alignés en colonnes de largeur fixe, calculées sur le contenu réel,
 *    et calés selon l'alignement de leur colonne. Sans cet alignement, un tableau
 *    de chiffres en texte brut est illisible — et c'est précisément ce qu'un
 *    courtier vient y chercher.
 *
 * AUCUNE TRONCATURE. Les colonnes étaient plafonnées à quarante caractères, un
 * nom de client plus long partait avec des points de suspension : le .txt était
 * alors le seul des six formats à ne pas porter la donnée entière. Un fichier
 * qu'on choisit pour sa longévité ne doit rien perdre en chemin ; c'est la largeur
 * de ligne qui cède, pas le contenu.
 */
final class TexteRapportRenderer implements RapportRendererInterface
{
    private const EOL = "\r\n";

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
                // Aucun plafond : une colonne fait la largeur de son plus long
                // contenu. Un plafond tronquait « CASH MANAGEMENT SOLUTIONS (CMS)
                // SARL BUKAVU » en « CASH MANAGEMENT SOLUTIONS (CMS) SARL BUK… » —
                // le nom du client, amputé dans le seul format texte alors que les
                // cinq autres le portaient en entier. Une ligne longue reste lisible ;
                // une donnée coupée, non.
                $largeurs[$index] = max($largeurs[$index], mb_strlen($this->celluleTexte($cellule)));
            }
        }

        $alignements = array_slice(
            array_pad((array) ($bloc['alignements'] ?? []), $colonnes, 'left'),
            0,
            $colonnes,
        );

        $lignes = [];
        if (($bloc['titre'] ?? null) !== null) {
            $lignes[] = $bloc['titre'];
        }

        if ($bloc['entetes'] !== []) {
            $lignes[] = $this->ligneAlignee($bloc['entetes'], $largeurs, $colonnes, $alignements);
            $lignes[] = implode('-+-', array_map(static fn (int $l) => str_repeat('-', $l), $largeurs));
        }
        foreach ($bloc['lignes'] as $ligne) {
            $lignes[] = $this->ligneAlignee($ligne, $largeurs, $colonnes, $alignements);
        }
        $lignes[] = '';

        return $lignes;
    }

    /**
     * Une ligne du tableau, chaque cellule calée selon l'alignement de SA colonne.
     *
     * En texte brut, l'alignement n'est pas un ornement : c'est la seule chose qui
     * met les unités des montants les unes sous les autres. Une colonne de chiffres
     * calée à gauche est illisible — et c'est précisément ce qu'un courtier vient
     * chercher dans ce fichier.
     *
     * @param list<string> $ligne
     * @param list<int>    $largeurs
     * @param list<string> $alignements
     */
    private function ligneAlignee(array $ligne, array $largeurs, int $colonnes, array $alignements): string
    {
        $cellules = [];
        for ($index = 0; $index < $colonnes; $index++) {
            $texte = $this->celluleTexte($ligne[$index] ?? '');
            // mb_str_pad n'existe qu'à partir de PHP 8.3 : on complète à la main
            // pour que l'alignement tienne aussi sur du texte accentué.
            $manque = max(0, $largeurs[$index] - mb_strlen($texte));
            $cellules[] = match ($alignements[$index] ?? 'left') {
                'right'  => str_repeat(' ', $manque) . $texte,
                'center' => str_repeat(' ', intdiv($manque, 2)) . $texte . str_repeat(' ', $manque - intdiv($manque, 2)),
                default  => $texte . str_repeat(' ', $manque),
            };
        }

        return rtrim(implode(' | ', $cellules));
    }

    private function celluleTexte(string $valeur): string
    {
        return trim(str_replace(["\n", "\r"], ' ', $valeur));
    }
}
