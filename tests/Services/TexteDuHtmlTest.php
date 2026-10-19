<?php

namespace App\Tests\Services;

use App\Doctrine\Fonction\TexteDuHtml;
use App\Entity\Groupe;
use App\Service\TexteRiche\TexteRiche;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TEXTE_DU_HTML ET TexteRiche SONT LIÉS PAR CE TEST — modifier l'un impose de modifier l'autre.
 *
 * La recherche convertit le HTML d'un champ riche en texte DANS la base (fonction DQL
 * TEXTE_DU_HTML) ; l'écran, Ket et les listes le convertissent en PHP (TexteRiche::lisible,
 * c'est-à-dire enTexte() pour le HTML de l'éditeur, la valeur telle quelle pour un texte
 * simple). Deux implémentations d'une même règle divergent un jour : ce test les compare sur
 * un corpus piégé, et vérifie que tout ce que le sanitizer peut écrire est bien décodé.
 */
class TexteDuHtmlTest extends KernelTestCase
{
    /** Le corpus : HTML de l'éditeur (assaini ou hérité) et textes simples. */
    private const CORPUS = [
        'balise au milieu de la phrase' => '<p>Relancer <strong>le client</strong></p>',
        'paragraphes et saut de ligne' => '<p>Un</p><p>deux<br>trois</p>',
        'esperluette' => '<p>A &amp; B</p>',
        'chevrons saisis' => '<p>x &lt;b&gt; y</p>',
        'contenu vide de Quill' => '<p><br></p>',
        'liste' => '<ol><li data-list="bullet">un</li><li>deux</li></ol><p>fin</p>',
        'titre aligné' => '<h2 class="ql-align-center">Titre</h2><p>corps</p>',
        'espaces insécables en entité' => '<p>R&amp;D&nbsp;«&nbsp;ok&nbsp;»</p>',
        'apostrophes et guillemets' => '<p>l&#039;assuré &#39;cité&#39; &quot;q&quot; &#34;r&#34;</p>',
        'arobase plus égal accent grave' => '<p>a&#64;b.cd 3&#43;4&#61;7 &#96;x&#96;</p>',
        'pleine-chasse' => '<p>pleine &#xFF1C;chasse&#xFF1E; &#xFF0B;&#xFF1D;&#xFF20;&#xFF40;</p>',
        'espace insécable en caractère' => "<p>espace\u{00A0}insécable</p>",
        'blancs multiples' => '<p>  beaucoup   de    blancs  </p>',
        'majuscules' => '<P>Majuscules <STRONG>Gras</STRONG></P>',
        'gras au milieu d\'un mot' => '<p>Re<strong>lancer</strong> partiel</p>',
        'couleur et italique' => '<p><span style="color: rgb(230, 0, 0);">rouge</span> et <em>it</em></p>',
        'image base64 héritée' => '<p>x<img src="data:image/png;base64,QUJDRA==">y</p>',
        'sauts de ligne variés' => '<p>l1<br/>l2<br class="x">l3</p>',
        'retour chariot entre blocs' => "<p>un</p>\n<p>deux</p>",
        'insécable en tête' => "\u{00A0}<p>nbsp en tête</p>",
        'texte simple avec chevrons' => 'délai < 10 jours > relance',
        'texte simple « A<B »' => 'remise « A<B »',
        'bloc hérité hors éditeur' => '<blockquote>cité</blockquote><p>après</p>',
    ];

    private function enBase(string $valeur): string
    {
        // Un seul noyau par test : le redémarrer rouvrirait la connexion, et perdrait un
        // `SET NAMES` posé par le test de collation.
        if (!static::$booted) {
            self::bootKernel();
        }
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        // L'argument figure plusieurs fois dans le SQL produit : on le passe en COLONNE d'une
        // table dérivée, comme dans une vraie recherche, plutôt qu'en paramètre répété.
        return (string) $conn->fetchOne('SELECT ' . TexteDuHtml::sql('t.v') . ' FROM (SELECT ? AS v) t', [$valeur]);
    }

    public function testLaBaseEtPhpLisentLeMemeTexte(): void
    {
        self::assertGreaterThanOrEqual(15, \count(self::CORPUS));
        $ecarts = [];
        foreach (self::CORPUS as $cas => $valeur) {
            $php = (string) TexteRiche::lisible($valeur);
            $sql = $this->enBase($valeur);
            if ($php !== $sql) {
                $ecarts[] = sprintf('%s : PHP %s ≠ SQL %s', $cas, json_encode($php, JSON_UNESCAPED_UNICODE), json_encode($sql, JSON_UNESCAPED_UNICODE));
            }
        }

        self::assertSame([], $ecarts);
    }

    /**
     * QUELLE QUE SOIT LA COLLATION DE LA CONNEXION. Incident du 2026-10-19 : la requête de
     * contrôle, jouée dans phpMyAdmin (connexion utf8mb4_unicode_ci), a échoué en « Illegal mix
     * of collations » — les littéraux `_utf8mb4 X'…'` prennent la collation PAR DÉFAUT du jeu
     * (general_ci), en conflit avec une valeur en unicode_ci. Le poste de développement, en
     * general_ci, ne le voyait pas. L'argument est désormais converti d'abord en utf8mb4.
     */
    public function testLaConversionNeDependPasDeLaCollationDeLaConnexion(): void
    {
        self::bootKernel();
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $avant = (string) $conn->fetchOne('SELECT @@collation_connection');
        try {
            foreach (['utf8mb4_unicode_ci', 'utf8mb4_general_ci'] as $collation) {
                $conn->executeStatement("SET NAMES utf8mb4 COLLATE $collation");
                foreach (self::CORPUS as $cas => $valeur) {
                    self::assertSame((string) TexteRiche::lisible($valeur), $this->enBase($valeur), "$cas, connexion $collation");
                }
            }
        } finally {
            $conn->executeStatement("SET NAMES utf8mb4 COLLATE $avant");
        }
    }

    public function testQuelquesLecturesAttendues(): void
    {
        self::assertSame('Relancer le client', $this->enBase(self::CORPUS['balise au milieu de la phrase']));
        self::assertSame('A & B', $this->enBase(self::CORPUS['esperluette']));
        self::assertSame('', $this->enBase(self::CORPUS['contenu vide de Quill']));
        self::assertSame('xy', $this->enBase(self::CORPUS['image base64 héritée']), 'Le base64 d\'une image n\'est pas du texte.');
        self::assertSame('délai < 10 jours > relance', $this->enBase(self::CORPUS['texte simple avec chevrons']), 'Un texte simple n\'est pas lu comme du HTML.');
    }

    /**
     * L'INVARIANT : sanitizer ⊂ TEXTE_DU_HTML. Tout caractère que l'assainisseur encode doit
     * revenir décodé par la base. Une montée de Symfony qui lui ferait écrire une entité
     * nouvelle fait échouer ce test, au lieu de rendre une recherche muette.
     */
    public function testToutCeQueLeSanitizerEcritEstDecode(): void
    {
        self::bootKernel();
        $assaini = static::getContainer()->get(TexteRiche::class)
            ->assainir("<p>a & b < c > d \" e ' f + g = h @ i ` j ＜k＞ ＋ ＝ ＠ ｀ l\u{00A0}m</p>");

        preg_match_all('/&#?[a-zA-Z0-9]+;/', $assaini, $entites);
        $inconnues = array_diff(array_unique($entites[0]), array_keys(TexteDuHtml::ENTITES));
        self::assertSame([], array_values($inconnues), 'Entités écrites par le sanitizer mais pas décodées par TEXTE_DU_HTML.');

        self::assertSame((string) TexteRiche::lisible($assaini), $this->enBase($assaini));
    }

    public function testLaFonctionSeParseEnDql(): void
    {
        self::bootKernel();
        $sql = static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT g.id FROM ' . Groupe::class . " g WHERE TEXTE_DU_HTML(g.description) LIKE :q ESCAPE '!'")
            ->getSQL();

        self::assertStringContainsString('REGEXP_REPLACE', $sql);
        self::assertStringNotContainsString('\\', $sql, 'Aucun antislash dans le SQL produit.');
    }
}
