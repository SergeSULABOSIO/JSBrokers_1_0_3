<?php

namespace App\Doctrine\Fonction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `TEXTE_DU_HTML(champ)` — LE TEXTE D'UN CHAMP RICHE, LU PAR LA BASE (chantier 11).
 *
 * La recherche faisait son LIKE sur le HTML stocké par l'éditeur : « strong » trouvait la
 * fiche, « Relancer le client » ne la trouvait pas (une balise coupait la phrase). Cette
 * fonction rend, côté MariaDB, ce que TexteRiche::lisible() rend côté PHP — même règle,
 * vérifiée par TexteDuHtmlTest (parité sur un corpus piégé, et invariant « tout ce que le
 * sanitizer écrit est décodé »). MODIFIER L'UNE IMPOSE DE MODIFIER L'AUTRE.
 *
 * - Une valeur qui n'est PAS le HTML de l'éditeur (texte simple écrit par Ket ou hérité :
 *   « délai < 10 jours ») est rendue telle quelle — même critère que TexteRiche::estRiche.
 * - Sinon, dans cet ordre : (1) saut de ligne et fins de bloc → espace ; (2) autres balises →
 *   rien (« Re<strong>lancer</strong> » → « Relancer ») ; (3) entités décodées, `&amp;` EN
 *   DERNIER (pas de double décodage ; décoder avant (2) ferait d'un « &lt;b&gt; » saisi une
 *   balise supprimée) ; (4) espace insécable → espace ; (5) blancs réduits ; (6) TRIM.
 * - Aucun antislash : classes POSIX, littéraux hexadécimaux à introducteur (`_utf8mb4 X'…'`).
 *   L'argument est converti en utf8mb4 AVANT tout (voir sql()) : sans cela, une valeur d'une
 *   autre collation que celle des littéraux échouait en « Illegal mix of collations ».
 * - REGEXP_REPLACE : MariaDB ≥ 10.0.5 (PCRE en 10.4, PCRE2 en 10.5+).
 */
final class TexteDuHtml extends FunctionNode
{
    /** Débuts reconnus comme HTML de l'éditeur (TexteRiche::estRiche). */
    private const DEBUT_RICHE = '^[[:space:]]*<(p|ol|ul|h[1-3])([[:space:]]|>)';

    /** Saut de ligne et fins de bloc → espace : « <p>un</p><p>deux</p> » ≠ « undeux ». */
    private const FINS_DE_BLOC = '<(br[^>]*|/(p|li|div|h[1-6]|blockquote|pre|tr|td|ol|ul))>';

    /**
     * Entités décodées → caractère (hexadécimal UTF-8). Liste tirée de la mesure : ce que le
     * sanitizer peut écrire (htmlspecialchars + ses remplacements, dont 6 pleine-chasse) et
     * ce que Quill écrivait avant l'assainissement (`&nbsp;`). `&amp;` reste à part (dernier).
     */
    public const ENTITES = [
        '&nbsp;' => '20',
        '&lt;' => '3C',
        '&gt;' => '3E',
        '&quot;' => '22',
        '&#34;' => '22',
        '&#39;' => '27',
        '&#039;' => '27',
        '&#64;' => '40',
        '&#43;' => '2B',
        '&#61;' => '3D',
        '&#96;' => '60',
        '&#xFF1C;' => 'EFBC9C',
        '&#xFF1E;' => 'EFBC9E',
        '&#xFF0B;' => 'EFBC8B',
        '&#xFF1D;' => 'EFBC9D',
        '&#xFF20;' => 'EFBCA0',
        '&#xFF40;' => 'EFBD80',
        '&amp;' => '26',
    ];

    private Node $champ;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->champ = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return self::sql($this->champ->dispatch($sqlWalker));
    }

    /** Le SQL de la conversion pour une expression SQL donnée — seule source (la DQL et les tests). */
    public static function sql(string $expression): string
    {
        // ⚠ L'ARGUMENT EST D'ABORD RAMENÉ À utf8mb4 (collation par défaut du jeu). Les littéraux
        // `_utf8mb4 X'…'` prennent cette collation par défaut (general_ci) ; une valeur d'une
        // autre collation au même rang de coercibilité — un littéral sous une connexion en
        // unicode_ci, comme phpMyAdmin — produisait « Illegal mix of collations » (incident du
        // 2026-10-19). Converti, tout parle la même collation, quelles que soient la connexion
        // et la colonne. Casse et accents restent indifférents (general_ci, comme unicode_ci).
        $expression = sprintf('CONVERT(%s USING utf8mb4)', $expression);

        $insecableEnTete = sprintf(
            "REPLACE(REPLACE(%s, _utf8mb4 X'C2A0', ' '), _utf8mb4 X'EFBBBF', ' ')",
            $expression,
        );

        $texte = sprintf("REGEXP_REPLACE(%s, '%s', ' ')", $expression, self::FINS_DE_BLOC);
        $texte = sprintf("REGEXP_REPLACE(%s, '<[^>]*>', '')", $texte);
        foreach (self::ENTITES as $entite => $hexa) {
            $texte = sprintf("REPLACE(%s, '%s', _utf8mb4 X'%s')", $texte, $entite, $hexa);
        }
        $texte = sprintf("REPLACE(%s, _utf8mb4 X'C2A0', ' ')", $texte);
        $texte = sprintf("REGEXP_REPLACE(%s, '[[:space:]]+', ' ')", $texte);

        return sprintf(
            "(CASE WHEN %s REGEXP '%s' THEN TRIM(%s) ELSE %s END)",
            $insecableEnTete,
            self::DEBUT_RICHE,
            $texte,
            $expression,
        );
    }
}
