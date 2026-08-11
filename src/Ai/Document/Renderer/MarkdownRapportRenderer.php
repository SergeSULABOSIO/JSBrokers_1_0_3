<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;

/**
 * Rapport en MARKDOWN — le format des gens qui vont le réutiliser ailleurs (wiki,
 * dépôt, éditeur de texte, autre outil).
 *
 * La grammaire écrite ici est exactement celle que {@see \App\Ai\Export\MessageMarkdownParser}
 * sait relire : titres `#`, listes `-`, tableaux GFM. Ce n'est pas une coquetterie —
 * cela garantit qu'un rapport produit puis re-attaché à une conversation sera compris
 * par le même parseur, sans zone d'ombre.
 */
final class MarkdownRapportRenderer implements RapportRendererInterface
{
    public function format(): DocumentFormat
    {
        return DocumentFormat::Md;
    }

    /** Le thème est ignoré : du markdown n'a pas de couleurs, il en reçoit chez son lecteur. */
    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied, ThemeDocument $theme): string
    {
        $lignes = [
            '# ' . $spec->titre,
            '',
            '*' . $pied->entreprise . ' — produit le ' . $pied->produitLe->format('d/m/Y à H:i') . '*',
            '',
            '## 1. Objet du document',
            '',
            $spec->problematique,
            '',
            '## 2. Introduction',
            '',
            $spec->introduction,
            '',
        ];

        if ($spec->definitions !== []) {
            $lignes[] = '### Définitions des termes employés';
            $lignes[] = '';
            foreach ($spec->definitions as $definition) {
                $lignes[] = '- **' . $definition['terme'] . '** : ' . $definition['explication'];
            }
            $lignes[] = '';
        }

        $lignes[] = '## 3. Résultat';
        $lignes[] = '';

        foreach ($sections as $index => $section) {
            if (($section['titre'] ?? '') !== '') {
                $lignes[] = '### 3.' . ($index + 1) . ' ' . $section['titre'];
                $lignes[] = '';
            }
            foreach ($section['blocs'] as $bloc) {
                array_push($lignes, ...$this->bloc($bloc));
            }
        }

        $lignes[] = '## 4. Conclusion';
        $lignes[] = '';
        $lignes[] = $spec->conclusion;
        $lignes[] = '';
        $lignes[] = '---';
        $lignes[] = '';
        $lignes[] = '*' . $pied->ligne() . '*';
        $lignes[] = '';

        return implode("\n", $lignes);
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
                return ['#### ' . $bloc['texte'], ''];

            case 'paragraphe':
                return [$bloc['texte'], ''];

            case 'code':
                return ['```', $bloc['texte'], '```', ''];

            case 'liste':
                $lignes = [];
                foreach ($bloc['items'] as $rang => $item) {
                    $lignes[] = ($bloc['ordonnee'] ? ($rang + 1) . '.' : '-') . ' ' . $item;
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
        $entetes = $bloc['entetes'];
        if ($entetes === []) {
            // Un tableau GFM sans en-tête n'est pas un tableau : on prend la
            // largeur de la première ligne pour en fabriquer un, vide mais valide.
            $largeur = count($bloc['lignes'][0] ?? []);
            if ($largeur === 0) {
                return [];
            }
            $entetes = array_fill(0, $largeur, '');
        }

        $lignes = [];
        if (($bloc['titre'] ?? null) !== null) {
            $lignes[] = '**' . $bloc['titre'] . '**';
            $lignes[] = '';
        }

        $lignes[] = '| ' . implode(' | ', array_map($this->cellule(...), $entetes)) . ' |';
        // LA LIGNE DE SÉPARATION PORTE L'ALIGNEMENT. C'est le seul endroit où le
        // markdown sait l'écrire : sans « ---: », un fichier rouvert dans un wiki ou
        // un éditeur perd pour de bon l'information que le rapport avait reçue.
        $lignes[] = '| ' . implode(' | ', array_map(
            $this->separateur(...),
            array_slice(array_pad((array) ($bloc['alignements'] ?? []), count($entetes), 'left'), 0, count($entetes)),
        )) . ' |';

        foreach ($bloc['lignes'] as $ligne) {
            // On COMPLÈTE, on ne tronque plus. Les lignes arrivent rectangulaires de
            // RapportAssembleur ; ce padding n'est qu'une ceinture. Le `slice` qui se
            // trouvait ici faisait disparaître toute cellule au-delà de la largeur de
            // l'en-tête — une donnée écrite par le modèle, effacée en silence, et
            // présente dans les cinq autres formats.
            $ligne = array_pad(array_values($ligne), count($entetes), '');
            $lignes[] = '| ' . implode(' | ', array_map($this->cellule(...), $ligne)) . ' |';
        }
        $lignes[] = '';

        return $lignes;
    }

    /** La cellule de séparation qui encode l'alignement d'une colonne. */
    private function separateur(string $alignement): string
    {
        return match ($alignement) {
            'right'  => '---:',
            'center' => ':---:',
            default  => '---',
        };
    }

    /** Une barre verticale dans une cellule couperait la colonne en deux. */
    private function cellule(string $valeur): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $valeur);
    }
}
