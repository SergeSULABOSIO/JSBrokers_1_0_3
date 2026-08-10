<?php

namespace App\Ai\Export;

/**
 * Analyse le Markdown d'un message de l'assistant en un ARBRE DE BLOCS de texte
 * brut, pour l'export documentaire (PDF, Word, e-mail).
 *
 * ── Frontière de sécurité ────────────────────────────────────────────────────
 * Cette classe ne produit JAMAIS de HTML : elle ne renvoie que du texte brut,
 * structuré. Le HTML naît uniquement dans le fragment Twig
 * `admin/assistant_ia/export/_message_corps.html.twig`, sous autoescape. Aucune
 * concaténation de balise en PHP, donc aucune injection possible depuis le
 * contenu d'un message — quelle qu'en soit l'origine (modèle, utilisateur).
 *
 * ── Spec NORMATIVE de la grammaire ───────────────────────────────────────────
 * La grammaire acceptée est fermée : c'est celle que le prompt système impose à
 * Ket, décrite dans App\Ai\AiContextBuilder::reglesDeMiseEnForme() (le CONTRAT DE
 * PRÉSENTATION : mise en forme, listes, pastilles, tableaux, émojis) et sa section
 * « GRAPHIQUES ». Elle est délibérément plus petite que CommonMark :
 *
 *   - titres `#`…`######`, tous aplatis en un seul niveau (miroir du renderer
 *     JS, qui rend `<p class="aic-md-heading">` quel que soit le niveau) ;
 *   - listes à puces (`-`, `*`, `+`) et numérotées (`1.`, `1)`) ;
 *   - tableaux GFM (ligne d'en-tête + ligne de séparation), dont l'ALIGNEMENT par
 *     colonne (`---:`, `:--:`) et la LIGNE DE TOTAUX (première cellule `**TOTAL**`)
 *     sont restitués — miroir de assets/controllers/assistant-markdown-table.js ;
 *   - les émojis du jeu fermé du contrat, qui ne sont que du texte ;
 *   - blocs de code ``` (et ```chart / ```graphique, cf. plus bas) ;
 *   - inline PLAT, sans imbrication : `**gras**`, `*italique*`, `` `code` `` et
 *     les cinq pastilles `[texte](#success|#danger|#warning|#info|#neutral)` ;
 *     toute autre cible de lien est dégradée en TEXTE, comme côté client ;
 *   - saut de ligne simple significatif (`breaks: true` côté marked).
 *
 * En cas d'évolution du prompt, mettre à jour les deux implémentations : celle-ci
 * et assets/controllers/assistant-markdown-render.js. Le corpus partagé
 * tests/Ai/fixtures/markdown/ documente une convention par fichier.
 *
 * ── Graphiques ───────────────────────────────────────────────────────────────
 * Un bloc ```chart porte un JSON. Dans un DOCUMENT on ne peut pas peindre un
 * canvas Chart.js : on transcrit `labels` × `series` en TABLEAU de données, ce
 * qui est sans perte (et supérieur à une image : sélectionnable, imprimable,
 * accessible). La fidélité visuelle du graphique est couverte par l'export
 * image, qui capture la bulle réelle dans le navigateur.
 */
class MessageMarkdownParser
{
    /** Cibles de lien réservées aux pastilles — miroir de BADGE_VARIANTES (JS). */
    public const BADGE_VARIANTES = ['success', 'danger', 'warning', 'info', 'neutral'];

    /** Bornes des graphiques — miroir de assistant-chart-spec.js. */
    private const CHART_TYPES = ['bar', 'line', 'doughnut', 'pie'];
    private const MAX_SERIES = 6;
    private const MAX_POINTS = 24;
    private const MAX_TITRE = 120;
    private const MAX_LEGENDE = 240;
    private const MAX_UNITE = 12;
    private const MAX_LABEL = 40;
    private const MAX_LABEL_SERIE = 60;

    /**
     * Découpe un message en blocs.
     *
     * Formes de blocs renvoyées :
     *   ['type' => 'titre',       'inline' => Inline[]]
     *   ['type' => 'paragraphe',  'inline' => Inline[]]
     *   ['type' => 'liste',       'ordonnee' => bool, 'items' => Inline[][]]
     *   ['type' => 'tableau',     'entetes' => Inline[][], 'lignes' => Inline[][][]]
     *   ['type' => 'code',        'texte' => string]
     *   ['type' => 'chart',       'titre' => string, 'unite' => string,
     *                             'legende' => string, 'labels' => string[],
     *                             'series' => array{label: string, data: float[]}[]]
     *
     * Inline : ['type' => 'texte'|'gras'|'italique'|'code'|'badge'|'saut',
     *           'texte' => string, 'variante' => string|null]
     *
     * @return array<int, array<string, mixed>>
     */
    public function analyser(string $markdown): array
    {
        $lignes = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $blocs = [];
        $i = 0;
        $nb = count($lignes);

        while ($i < $nb) {
            $ligne = $lignes[$i];

            if (trim($ligne) === '') {
                ++$i;
                continue;
            }

            if (preg_match('/^\s*```(.*)$/', $ligne, $m)) {
                $i = $this->consommerBlocCode($lignes, $i, trim($m[1]), $blocs);
                continue;
            }

            if (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/', $ligne, $m)) {
                $blocs[] = ['type' => 'titre', 'inline' => $this->analyserInline($m[1])];
                ++$i;
                continue;
            }

            if ($this->estDebutTableau($lignes, $i)) {
                $i = $this->consommerTableau($lignes, $i, $blocs);
                continue;
            }

            if ($this->typeDeListe($ligne) !== null) {
                $i = $this->consommerListe($lignes, $i, $blocs);
                continue;
            }

            $i = $this->consommerParagraphe($lignes, $i, $blocs);
        }

        return $blocs;
    }

    // ─────────────────────────────────────────────────────────── blocs ───

    /**
     * Bloc délimité par ```. Une fence non fermée consomme la fin du message
     * (comportement de marked). Un bloc `chart`/`graphique` dont le JSON est
     * inexploitable est IGNORÉ silencieusement — miroir du front, où
     * `construireConfigChart` renvoie null et où rien n'est monté.
     *
     * @param array<int, string> $lignes
     * @param array<int, array<string, mixed>> $blocs
     */
    private function consommerBlocCode(array $lignes, int $depart, string $langue, array &$blocs): int
    {
        $contenu = [];
        $i = $depart + 1;
        $nb = count($lignes);
        while ($i < $nb && !preg_match('/^\s*```\s*$/', $lignes[$i])) {
            $contenu[] = $lignes[$i];
            ++$i;
        }
        $fin = $i < $nb ? $i + 1 : $nb; // saute la fence de fermeture si présente
        $texte = implode("\n", $contenu);

        $langue = mb_strtolower($langue);
        if ($langue === 'chart' || $langue === 'graphique') {
            $chart = $this->analyserChart($texte);
            if ($chart !== null) {
                $blocs[] = $chart;
            }

            return $fin;
        }

        if (trim($texte) !== '') {
            $blocs[] = ['type' => 'code', 'texte' => $texte];
        }

        return $fin;
    }

    /**
     * Tableau GFM : une ligne d'en-tête contenant un « | », suivie d'une ligne de
     * séparation faite de tirets, deux-points, espaces et « | ».
     *
     * @param array<int, string> $lignes
     */
    private function estDebutTableau(array $lignes, int $i): bool
    {
        if (!str_contains($lignes[$i], '|') || !isset($lignes[$i + 1])) {
            return false;
        }

        return (bool) preg_match('/^\s*\|?[\s:|-]*-[\s:|-]*\|[\s:|-]*$/', $lignes[$i + 1]);
    }

    /**
     * @param array<int, string> $lignes
     * @param array<int, array<string, mixed>> $blocs
     */
    private function consommerTableau(array $lignes, int $depart, array &$blocs): int
    {
        $entetes = array_map(fn (string $c): array => $this->analyserInline($c), $this->cellules($lignes[$depart]));
        $alignements = $this->alignements($lignes[$depart + 1], count($entetes));

        $corps = [];
        $totaux = [];
        $i = $depart + 2; // en-tête + séparateur
        $nb = count($lignes);
        while ($i < $nb && trim($lignes[$i]) !== '' && str_contains($lignes[$i], '|')) {
            $cellules = $this->cellules($lignes[$i]);
            $totaux[] = $this->estLigneDeTotaux($cellules);
            $corps[] = array_map(fn (string $c): array => $this->analyserInline($c), $cellules);
            ++$i;
        }

        $blocs[] = [
            'type'        => 'tableau',
            'entetes'     => $entetes,
            'alignements' => $alignements,
            'lignes'      => $corps,
            'totaux'      => $totaux,
        ];

        return $i;
    }

    /**
     * ALIGNEMENT DES COLONNES, lu dans la ligne de séparation — « ---: » à droite,
     * « :--: » au centre, le reste à gauche.
     *
     * POURQUOI CE N'ÉTAIT PAS LU. La ligne de séparation était simplement SAUTÉE
     * (« $depart + 2 »), donc l'alignement disparaissait de l'export comme il
     * disparaissait du chat — où c'était DOMPurify qui le supprimait, l'attribut
     * `align` de marked n'étant pas dans l'allowlist. Un même tableau se lisait donc
     * plat dans les deux sorties. Le chat honore désormais l'alignement (cf.
     * assets/controllers/assistant-markdown-table.js) : sans cette lecture, le PDF et
     * le document Word resteraient les seuls à ne pas le faire.
     *
     * @return array<int, string> une valeur parmi 'left', 'center', 'right' par colonne
     */
    private function alignements(string $separateur, int $nbColonnes): array
    {
        $alignements = [];
        foreach ($this->cellules($separateur) as $cellule) {
            $cellule = trim($cellule);
            $aDroite = str_ends_with($cellule, ':');
            $aGauche = str_starts_with($cellule, ':');
            $alignements[] = match (true) {
                $aGauche && $aDroite => 'center',
                $aDroite             => 'right',
                default              => 'left',
            };
        }

        // Une ligne de séparation mal comptée ne doit jamais décaler les colonnes du
        // corps : on cadre sur le nombre d'en-têtes, en complétant à gauche.
        return array_slice(array_pad($alignements, $nbColonnes, 'left'), 0, $nbColonnes);
    }

    /**
     * La ligne de TOTAUX du contrat de présentation : première cellule en gras portant
     * « TOTAL ». Miroir exact de estLigneDeTotaux() côté navigateur — sans ce marquage,
     * le chiffre qui résume tout le tableau se lit comme une ligne de données de plus.
     *
     * @param array<int, string> $cellules
     */
    private function estLigneDeTotaux(array $cellules): bool
    {
        return (bool) preg_match('/^\*\*\s*TOTAL\b/i', trim($cellules[0] ?? ''));
    }

    /**
     * Cellules d'une ligne de tableau : les « | » de bord sont facultatifs, comme
     * en GFM.
     *
     * @return array<int, string>
     */
    private function cellules(string $ligne): array
    {
        $ligne = trim($ligne);
        $ligne = preg_replace('/^\|/', '', $ligne) ?? $ligne;
        $ligne = preg_replace('/\|$/', '', $ligne) ?? $ligne;

        return array_map('trim', explode('|', $ligne));
    }

    /** Renvoie 'puces', 'numerotee' ou null selon le marqueur de liste. */
    private function typeDeListe(string $ligne): ?string
    {
        if (preg_match('/^\s{0,3}[-*+]\s+\S/', $ligne)) {
            return 'puces';
        }

        return preg_match('/^\s{0,3}\d+[.)]\s+\S/', $ligne) ? 'numerotee' : null;
    }

    /**
     * Liste homogène : un changement de type (puces ⇄ numérotée) ferme la liste
     * et en ouvre une autre. Les continuations indentées sont recollées à l'item.
     *
     * @param array<int, string> $lignes
     * @param array<int, array<string, mixed>> $blocs
     */
    private function consommerListe(array $lignes, int $depart, array &$blocs): int
    {
        $type = $this->typeDeListe($lignes[$depart]);
        $items = [];
        $courant = null;
        $i = $depart;
        $nb = count($lignes);

        while ($i < $nb) {
            $ligne = $lignes[$i];
            $typeLigne = $this->typeDeListe($ligne);

            if ($typeLigne === $type) {
                if ($courant !== null) {
                    $items[] = $courant;
                }
                $courant = preg_replace('/^\s{0,3}(?:[-*+]|\d+[.)])\s+/', '', $ligne) ?? $ligne;
                ++$i;
                continue;
            }

            // Continuation d'un item : ligne indentée, ni vide ni nouveau bloc.
            if ($courant !== null && $typeLigne === null && trim($ligne) !== '' && preg_match('/^\s{2,}\S/', $ligne)) {
                $courant .= ' ' . trim($ligne);
                ++$i;
                continue;
            }

            break;
        }

        if ($courant !== null) {
            $items[] = $courant;
        }

        $blocs[] = [
            'type' => 'liste',
            'ordonnee' => $type === 'numerotee',
            'items' => array_map(fn (string $t): array => $this->analyserInline($t), $items),
        ];

        return $i;
    }

    /**
     * Paragraphe : lignes consécutives jusqu'à une ligne vide ou l'ouverture d'un
     * autre bloc. Les sauts de ligne internes sont CONSERVÉS (jeton `saut`),
     * parce que le rendu client utilise `breaks: true`.
     *
     * @param array<int, string> $lignes
     * @param array<int, array<string, mixed>> $blocs
     */
    private function consommerParagraphe(array $lignes, int $depart, array &$blocs): int
    {
        $inline = [];
        $i = $depart;
        $nb = count($lignes);

        while ($i < $nb) {
            $ligne = $lignes[$i];
            if (trim($ligne) === '') {
                break;
            }
            if ($i > $depart && (
                preg_match('/^\s*```/', $ligne)
                || preg_match('/^\s{0,3}#{1,6}\s+/', $ligne)
                || $this->typeDeListe($ligne) !== null
                || $this->estDebutTableau($lignes, $i)
            )) {
                break;
            }

            if ($inline !== []) {
                $inline[] = ['type' => 'saut', 'texte' => '', 'variante' => null];
            }
            $inline = array_merge($inline, $this->analyserInline($ligne));
            ++$i;
        }

        if ($inline !== []) {
            $blocs[] = ['type' => 'paragraphe', 'inline' => $inline];
        }

        return max($i, $depart + 1);
    }

    // ────────────────────────────────────────────────────────── inline ───

    /**
     * Découpe une ligne en jetons inline PLATS. L'ordre de l'alternation est
     * significatif : le code d'abord (son contenu n'est jamais réinterprété),
     * puis les liens, puis `**gras**` avant `*italique*`.
     *
     * @return array<int, array{type: string, texte: string, variante: string|null}>
     */
    private function analyserInline(string $texte): array
    {
        $motif = '/'
            . '`([^`]+)`'                                  // 1 : code
            . '|\[([^\]\n]*)\]\(([^)\s]*)\)'               // 2 : libellé, 3 : cible
            . '|\*\*([^*]+)\*\*'                           // 4 : gras
            . '|\*([^*\n]+)\*'                             // 5 : italique
            . '/u';

        $jetons = [];
        $position = 0;
        $trouves = preg_match_all($motif, $texte, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if ($trouves) {
            foreach ($matches as $m) {
                [$brut, $offset] = $m[0];
                if ($offset > $position) {
                    $jetons[] = $this->jeton('texte', substr($texte, $position, $offset - $position));
                }
                $jetons[] = $this->jetonDeCorrespondance($m, $brut);
                $position = $offset + strlen($brut);
            }
        }

        if ($position < strlen($texte)) {
            $jetons[] = $this->jeton('texte', substr($texte, $position));
        }

        // Purge les fragments vides nés d'un découpage adjacent.
        return array_values(array_filter(
            $jetons,
            fn (array $j): bool => $j['type'] !== 'texte' || $j['texte'] !== ''
        ));
    }

    /**
     * Contenu d'un groupe, ou null s'il n'a pas participé au match.
     *
     * ATTENTION : avec PREG_OFFSET_CAPTURE, PHP remplit les groupes non capturés
     * qui PRÉCÈDENT un groupe capturé par ['', -1] — ils sont donc « définis ».
     * Seul l'offset -1 distingue de façon fiable « pas capturé » de « capturé
     * vide » (cas réel : le libellé de `[](#info)`).
     *
     * @param array<int, array{0: string, 1: int}> $m
     */
    private function groupe(array $m, int $index): ?string
    {
        return isset($m[$index]) && $m[$index][1] !== -1 ? $m[$index][0] : null;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m groupes capturés (PREG_OFFSET_CAPTURE)
     * @return array{type: string, texte: string, variante: string|null}
     */
    private function jetonDeCorrespondance(array $m, string $brut): array
    {
        $code = $this->groupe($m, 1);
        if ($code !== null) {
            return $this->jeton('code', $code);
        }

        // Lien : pastille si la cible est un des cinq mots-clés réservés, sinon
        // DÉGRADÉ en texte simple (aucun lien cliquable dans ce chat).
        $cible = $this->groupe($m, 3);
        if ($cible !== null) {
            $libelle = $this->groupe($m, 2) ?? '';
            $variante = str_starts_with($cible, '#') ? substr($cible, 1) : null;
            if ($variante !== null && in_array($variante, self::BADGE_VARIANTES, true)) {
                return $this->jeton('badge', $libelle, $variante);
            }

            return $this->jeton('texte', $libelle !== '' ? $libelle : $brut);
        }

        $gras = $this->groupe($m, 4);
        if ($gras !== null) {
            return $this->jeton('gras', $gras);
        }

        $italique = $this->groupe($m, 5);
        if ($italique !== null) {
            return $this->jeton('italique', $italique);
        }

        return $this->jeton('texte', $brut);
    }

    /** @return array{type: string, texte: string, variante: string|null} */
    private function jeton(string $type, string $texte, ?string $variante = null): array
    {
        return ['type' => $type, 'texte' => $texte, 'variante' => $variante];
    }

    // ─────────────────────────────────────────────────────── graphique ───

    /**
     * Valide et normalise la spec JSON d'un bloc ```chart. Miroir MINIMAL de
     * `normaliserSpec()` (assets/controllers/assistant-chart-spec.js) : mêmes
     * bornes et même alignement des séries sur les labels, sans la palette ni la
     * configuration Chart.js, inutiles hors canvas.
     *
     * @return array<string, mixed>|null null = spec inexploitable → bloc ignoré
     */
    private function analyserChart(string $json): ?array
    {
        $brut = json_decode(trim($json), true);
        if (!is_array($brut)) {
            return null;
        }

        $labels = [];
        if (is_array($brut['labels'] ?? null)) {
            foreach (array_slice($brut['labels'], 0, self::MAX_POINTS) as $label) {
                $labels[] = $this->texteCourt(is_scalar($label) ? (string) $label : '', self::MAX_LABEL);
            }
        }
        if ($labels === []) {
            return null;
        }

        $series = [];
        $brutes = is_array($brut['series'] ?? null) ? $brut['series'] : [];
        foreach (array_slice($brutes, 0, self::MAX_SERIES) as $serie) {
            if (!is_array($serie) || !is_array($serie['data'] ?? null)) {
                continue;
            }
            $data = [];
            foreach (array_keys($labels) as $index) {
                $data[] = $this->nombre($serie['data'][$index] ?? null);
            }
            $label = $this->texteCourt(is_scalar($serie['label'] ?? null) ? (string) $serie['label'] : '', self::MAX_LABEL_SERIE);
            $series[] = ['label' => $label !== '' ? $label : 'Série', 'data' => $data];
        }
        if ($series === []) {
            return null;
        }

        $type = in_array($brut['type'] ?? null, self::CHART_TYPES, true) ? $brut['type'] : 'bar';

        return [
            'type' => 'chart',
            'typeGraphique' => $type,
            'titre' => $this->texteCourt(is_scalar($brut['titre'] ?? null) ? (string) $brut['titre'] : '', self::MAX_TITRE),
            'unite' => $this->texteCourt(is_scalar($brut['unite'] ?? null) ? (string) $brut['unite'] : '', self::MAX_UNITE),
            'legende' => $this->texteCourt(is_scalar($brut['legende'] ?? null) ? (string) $brut['legende'] : '', self::MAX_LEGENDE),
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /** Texte court, mono-ligne, borné — miroir de `texteCourt()` (JS). */
    private function texteCourt(string $valeur, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $valeur)), 0, $max);
    }

    /** Nombre fini, virgule décimale acceptée, 0 par défaut — miroir de `nombre()` (JS). */
    private function nombre(mixed $valeur): float
    {
        if (is_int($valeur) || is_float($valeur)) {
            return is_finite((float) $valeur) ? (float) $valeur : 0.0;
        }
        if (is_string($valeur)) {
            $normalise = str_replace([' ', ' ', ','], ['', '', '.'], $valeur);
            return is_numeric($normalise) ? (float) $normalise : 0.0;
        }

        return 0.0;
    }
}
