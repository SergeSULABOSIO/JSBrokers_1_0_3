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
 *   ['type' => 'tableau',    'titre' => ?string, 'entetes' => list<string>,
 *                            'alignements' => list<string>, 'lignes' => list<list<string>>,
 *                            'totaux' => list<bool>]
 *   ['type' => 'code',       'texte' => string]
 *
 * ── LES TABLEAUX SORTENT D'ICI RECTANGULAIRES, ALIGNÉS ET MARQUÉS ───────────────
 * Trois propriétés que chaque rendu recevait autrefois à sa charge, ou pas du tout.
 *
 * RECTANGULAIRES. Le modèle produit des lignes irrégulières — une ligne sans sa
 * dernière valeur, une ligne avec une cellule de trop. Le HTML rendait alors des
 * `<tr>` de longueurs différentes : la ligne « Orange RDC SA », privée de sa
 * commission, remontait son montant d'une colonne et se lisait sous le mauvais
 * en-tête. Dans un document comptable, c'est le pire défaut possible. On complète
 * donc ici, une fois, pour les six.
 *
 * ALIGNÉS. L'alignement GFM (`---:`) est une DONNÉE : c'est lui qui met les
 * montants les uns sous les autres et rend deux chiffres comparables d'un coup
 * d'œil. Le parseur le lit, le chat l'honore, l'export de bulle aussi — le rapport
 * était le seul à le jeter en route.
 *
 * MARQUÉS. La ligne de totaux est repérée par le parseur (« **TOTAL** ») ; sans ce
 * drapeau, le chiffre qui résume tout le tableau se lit comme une ligne de données
 * de plus.
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
            'tableau' => self::tableau(
                entetes: array_map(self::plat(...), array_values((array) ($bloc['entetes'] ?? []))),
                lignes: array_map(
                    static fn (array $ligne) => array_map(self::plat(...), array_values($ligne)),
                    array_values((array) ($bloc['lignes'] ?? [])),
                ),
                alignements: array_values(array_filter((array) ($bloc['alignements'] ?? []), 'is_string')),
                totaux: array_map(static fn (mixed $t) => (bool) $t, array_values((array) ($bloc['totaux'] ?? []))),
            ),
            'chart' => self::chartEnTableau($bloc),
            default => null,
        };
    }

    /**
     * LE TABLEAU NORMALISÉ — rectangulaire, aligné, marqué. Point de passage unique
     * des tableaux GFM comme des graphiques convertis.
     *
     * La largeur retenue est la PLUS GRANDE observée, en-tête comprise : on complète,
     * on ne coupe jamais. Une cellule surnuméraire est une donnée que le modèle a
     * écrite ; la faire disparaître au motif que l'en-tête est plus court reviendrait
     * à choisir, en silence, ce que le lecteur a le droit de voir.
     *
     * @param list<string>       $entetes
     * @param list<list<string>> $lignes
     * @param list<string>       $alignements
     * @param list<bool>         $totaux
     *
     * @return array<string, mixed>
     */
    private static function tableau(array $entetes, array $lignes, array $alignements, array $totaux, ?string $titre = null): array
    {
        $colonnes = max(count($entetes), 0, ...array_map('count', $lignes));

        return [
            'type'    => 'tableau',
            'titre'   => $titre,
            // Un en-tête complété reste un en-tête : sans cela, une colonne de
            // données existerait sans intitulé au-dessus d'elle.
            'entetes' => $entetes === [] ? [] : array_pad($entetes, $colonnes, ''),
            // 'left' par défaut : c'est déjà la valeur de repli du parseur pour une
            // colonne dont la ligne de séparation ne dit rien.
            'alignements' => array_slice(array_pad($alignements, $colonnes, 'left'), 0, $colonnes),
            'lignes'      => array_map(
                static fn (array $ligne) => array_pad(array_values($ligne), $colonnes, ''),
                $lignes,
            ),
            // Un drapeau par ligne, dans le même ordre : un tableau sans ligne de
            // totaux en porte autant de `false` que de lignes.
            'totaux' => array_slice(array_pad($totaux, count($lignes), false), 0, count($lignes)),
        ];
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

        return self::tableau(
            entetes: $entetes,
            lignes: $lignes,
            // La colonne des étiquettes à gauche, les valeurs à droite : ce sont des
            // nombres, et c'est ainsi qu'ils se comparent.
            alignements: array_merge(['left'], array_fill(0, count($series), 'right')),
            totaux: [],
            titre: $titre ?? ($legende !== '' ? $legende : null),
        );
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
