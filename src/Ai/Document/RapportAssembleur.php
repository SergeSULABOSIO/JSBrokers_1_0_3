<?php

namespace App\Ai\Document;

use App\Ai\Export\MessageMarkdownParser;

/**
 * TRADUIT le markdown de Ket en blocs NORMALISÉS que les six rendus consomment à
 * l'identique.
 *
 * On réutilise {@see MessageMarkdownParser}, déjà écrit, déjà testé, et dont la
 * grammaire est verrouillée par un corpus partagé avec le renderer JavaScript. On
 * n'y touche pas : on l'aplatit.
 *
 * L'APLATISSEMENT EST LE POINT. Le parseur rend un arbre riche (fragments gras,
 * italiques, pastilles, séries de graphique) parfaitement adapté à une bulle de
 * chat. Un document, lui, a besoin de chaînes : une cellule Excel ne contient pas
 * un fragment italique, un paragraphe Word se compose de texte. Sans cette couche,
 * chacun des six rendus réimplémenterait le même parcours d'arbre — six occasions
 * de diverger. Ici, une seule.
 *
 * Formes produites, et rien d'autre :
 *   ['type' => 'titre',      'texte' => string]
 *   ['type' => 'paragraphe', 'texte' => string]
 *   ['type' => 'liste',      'ordonnee' => bool, 'items' => list<string>]
 *   ['type' => 'tableau',    'titre' => ?string, 'entetes' => list<string>, 'lignes' => list<list<string>>]
 *   ['type' => 'code',       'texte' => string]
 *
 * Un bloc ```chart devient un TABLEAU. C'est ce que fait déjà l'export de bulle, et
 * pour la même raison : on ne peut pas peindre un canvas dans un .docx, et une
 * image serait un recul — un tableau se sélectionne, s'imprime, se lit à voix haute
 * et se recopie dans un tableur.
 */
final class RapportAssembleur
{
    public function __construct(
        private readonly MessageMarkdownParser $parser,
    ) {
    }

    /**
     * Les blocs normalisés d'un corps de section.
     *
     * @return list<array<string, mixed>>
     */
    public function blocs(string $markdown): array
    {
        $normalises = [];
        foreach ($this->parser->analyser($markdown) as $bloc) {
            $traduit = $this->normaliser($bloc);
            if ($traduit !== null) {
                $normalises[] = $traduit;
            }
        }

        return $normalises;
    }

    /**
     * Les blocs de TOUTES les sections, chacun rattaché à sa section — la forme que
     * consomment les rendus.
     *
     * @return list<array{titre: string, blocs: list<array<string, mixed>>}>
     */
    public function sections(RapportSpec $spec): array
    {
        $sections = [];
        foreach ($spec->sections as $section) {
            $sections[] = [
                'titre' => $section['titre'],
                'blocs' => $this->blocs($section['corps']),
            ];
        }

        return $sections;
    }

    /** @param array<string, mixed> $bloc */
    private function normaliser(array $bloc): ?array
    {
        return match ($bloc['type'] ?? '') {
            'titre' => ['type' => 'titre', 'texte' => self::plat($bloc['inline'] ?? [])],
            'paragraphe' => ['type' => 'paragraphe', 'texte' => self::plat($bloc['inline'] ?? [])],
            'code' => ['type' => 'code', 'texte' => (string) ($bloc['texte'] ?? '')],
            'liste' => [
                'type'     => 'liste',
                'ordonnee' => (bool) ($bloc['ordonnee'] ?? false),
                'items'    => array_map(self::plat(...), array_values((array) ($bloc['items'] ?? []))),
            ],
            'tableau' => [
                'type'    => 'tableau',
                'titre'   => null,
                'entetes' => array_map(self::plat(...), array_values((array) ($bloc['entetes'] ?? []))),
                'lignes'  => array_map(
                    static fn (array $ligne) => array_map(self::plat(...), array_values($ligne)),
                    array_values((array) ($bloc['lignes'] ?? [])),
                ),
            ],
            'chart' => self::chartEnTableau($bloc),
            default => null,
        };
    }

    /**
     * Un graphique en tableau : une colonne d'étiquettes, puis une colonne par
     * série. La légende, quand elle existe, devient le titre du tableau — c'est
     * elle qui dit ce que les chiffres signifient.
     *
     * @param array<string, mixed> $bloc
     */
    private static function chartEnTableau(array $bloc): ?array
    {
        $labels = array_values((array) ($bloc['labels'] ?? []));
        $series = array_values(array_filter((array) ($bloc['series'] ?? []), 'is_array'));
        if ($labels === [] || $series === []) {
            return null;
        }

        $unite = trim((string) ($bloc['unite'] ?? ''));
        $entetes = [''];
        foreach ($series as $serie) {
            $libelle = trim((string) ($serie['label'] ?? ''));
            $entetes[] = $unite !== '' ? trim($libelle . ' (' . $unite . ')') : $libelle;
        }

        $lignes = [];
        foreach ($labels as $index => $label) {
            $ligne = [(string) $label];
            foreach ($series as $serie) {
                $valeur = ((array) ($serie['data'] ?? []))[$index] ?? null;
                $ligne[] = $valeur === null ? '' : (string) $valeur;
            }
            $lignes[] = $ligne;
        }

        $titre = trim((string) ($bloc['titre'] ?? '')) ?: null;
        $legende = trim((string) ($bloc['legende'] ?? ''));

        return [
            'type'    => 'tableau',
            'titre'   => $titre ?? ($legende !== '' ? $legende : null),
            'entetes' => $entetes,
            'lignes'  => $lignes,
        ];
    }

    /**
     * Aplatit une suite de fragments en une chaîne. Les emphases disparaissent —
     * un document porte sa mise en forme par ses styles, pas par des astérisques —
     * mais aucun MOT n'est perdu, y compris le texte des pastilles.
     *
     * @param array<int, array<string, mixed>> $inline
     */
    public static function plat(array $inline): string
    {
        $texte = '';
        foreach ($inline as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }
            $texte .= ($fragment['type'] ?? '') === 'saut' ? "\n" : (string) ($fragment['texte'] ?? '');
        }

        return trim($texte);
    }
}
