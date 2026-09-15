<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * LA VEILLE DES ERREURS DU NAVIGATEUR EST RÉELLEMENT INSTALLÉE.
 *
 * ── CE QUE CE TEST PROTÈGE, ET QU'AUCUN AUTRE NE VOIT ───────────────────────
 * Le fichier `assets/veille-erreurs.js` est le SEUL endroit d'où une erreur
 * JavaScript peut sortir du poste de l'utilisateur. S'il cesse d'être importé,
 * ou s'il n'observe plus le bon point d'entrée, rien n'échoue : aucune page ne
 * casse, aucun test ne rougit, aucune alerte ne part — et l'équipe en conclut
 * tranquillement qu'il n'y a plus d'erreurs côté client.
 *
 * C'est le pire mode de panne d'un dispositif de surveillance : il se tait
 * exactement comme quand tout va bien.
 *
 * ── LE PIÈGE QUE RIEN NE SIGNALE : `window.onerror`, PAS `addEventListener` ──
 * ⚠ Stimulus AVALE ses propres exceptions. Dans `Application.handleError`, toute
 * erreur levée par un `connect()`, un `initialize()` ou une action est
 * interceptée, journalisée en console… puis rappelée explicitement sur
 * `window.onerror`, et sur LUI SEUL.
 *
 * Or l'essentiel de l'application vit dans des contrôleurs Stimulus. Remplacer
 * l'AFFECTATION de `window.onerror` par un `window.addEventListener('error')` —
 * ce que toute relecture bien intentionnée proposerait, puisque c'est la forme
 * moderne — rendrait donc la veille aveugle à presque tout le code, sans que
 * rien ne le signale. Ce test est le garde-fou contre cette correction-là.
 *
 * Ces vérifications lisent les fichiers : elles valent sur le poste, avant la
 * publication, et non sur le serveur, après.
 */
final class VeilleErreursNavigateurTest extends TestCase
{
    private const VEILLE = __DIR__ . '/../../assets/veille-erreurs.js';
    private const APP_JS = __DIR__ . '/../../assets/app.js';

    /**
     * Le CODE de la veille, commentaires retirés.
     *
     * ⚠ Sans ce retrait, ce test se fait piéger par sa propre documentation :
     * l'en-tête de `veille-erreurs.js` cite littéralement
     * `window.addEventListener('error', …)` pour expliquer POURQUOI il ne faut
     * pas l'employer — et l'assertion qui interdit cette forme échouerait sur
     * la phrase qui l'interdit.
     *
     * C'est le même piège que dans PretPourLaProductionTest, où le .htaccess
     * cite les valeurs qu'il déconseille : un test qui lit un fichier entier
     * lit aussi ce que ce fichier dit de lui-même.
     */
    private static function veille(): string
    {
        self::assertFileExists(self::VEILLE, 'Sans ce fichier, AUCUNE erreur JavaScript ne quitte le navigateur de l\'utilisateur.');

        $source = (string) file_get_contents(self::VEILLE);

        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);   // blocs /* … */
        $source = (string) preg_replace('#^\s*//.*$#m', '', $source);   // lignes // …

        return $source;
    }

    /**
     * Le cœur du garde-fou : l'affectation, et pas l'écoute.
     */
    public function testLaVeilleAffecteWindowOnerrorEtNonUnEcouteur(): void
    {
        $source = self::veille();

        self::assertMatchesRegularExpression(
            '/window\.onerror\s*=/',
            $source,
            'Stimulus ne rappelle QUE window.onerror : sans cette affectation, les erreurs des contrôleurs — donc l\'essentiel de l\'application — ne remonteraient jamais.'
        );

        self::assertStringNotContainsString(
            "addEventListener('error'",
            $source,
            'Un écouteur « error » ne verrait pas les exceptions de Stimulus, qui les rattrape et les renvoie sur window.onerror uniquement.'
        );
    }

    /**
     * Les promesses rejetées ne passent pas par `window.onerror`. Sans cette
     * seconde écoute, tout le code asynchrone — donc TOUS les appels au serveur
     * — resterait invisible, alors que c'est là que tombent la plupart des
     * pannes réelles.
     */
    public function testLesPromessesRejeteesSontObservees(): void
    {
        self::assertStringContainsString(
            "addEventListener('unhandledrejection'",
            self::veille(),
            'Sans cette écoute, un fetch qui échoue ne laisse aucune trace.'
        );
    }

    /**
     * Le gestionnaire précédent est rappelé.
     *
     * La veille OBSERVE, elle ne confisque pas : si un autre code avait posé
     * son propre `window.onerror`, l'écraser sans le rappeler casserait
     * silencieusement une fonctionnalité pour en installer une autre.
     */
    public function testLeGestionnairePrecedentEstChaine(): void
    {
        self::assertMatchesRegularExpression(
            '/const\s+precedent\s*=\s*window\.onerror/',
            self::veille(),
            'La veille doit chaîner l\'éventuel gestionnaire déjà en place, pas le remplacer.'
        );
    }

    /**
     * Importée AVANT bootstrap.js, donc avant le démarrage de Stimulus.
     *
     * L'ordre compte : une erreur levée pendant l'initialisation d'un
     * contrôleur — le moment le plus fragile du chargement — surviendrait avant
     * l'installation de la veille, et serait perdue.
     */
    public function testLaVeilleEstImporteeAvantStimulus(): void
    {
        self::assertFileExists(self::APP_JS);
        $app = (string) file_get_contents(self::APP_JS);

        self::assertStringContainsString(
            "import './veille-erreurs.js';",
            $app,
            'assets/app.js doit importer la veille, sinon elle n\'est jamais chargée — et rien ne le signale.'
        );

        $positionVeille = strpos($app, "import './veille-erreurs.js';");
        $positionBootstrap = strpos($app, "import './bootstrap.js';");

        self::assertIsInt($positionVeille);
        self::assertIsInt($positionBootstrap, 'assets/app.js doit toujours importer bootstrap.js.');
        self::assertLessThan(
            $positionBootstrap,
            $positionVeille,
            'La veille doit être installée AVANT le démarrage de Stimulus, sinon les erreurs d\'initialisation des contrôleurs échappent à l\'observation.'
        );
    }

    /**
     * Le filtre reste une fonction pure, exportée.
     *
     * C'est ce qui permet de l'éprouver sans navigateur (tests/js/). Un filtre
     * qu'on ne peut pas tester est un filtre dont on ignore ce qu'il laisse
     * passer — et il décide de ce que l'équipe verra ou ne verra jamais.
     */
    public function testLeFiltreResteEprouvableSansNavigateur(): void
    {
        $source = self::veille();

        self::assertStringContainsString(
            'export function meriteUnRapport',
            $source,
            'Le filtre doit rester exporté pour que tests/js/veille-erreurs.test.mjs puisse l\'éprouver.'
        );

        self::assertStringContainsString(
            "typeof window !== 'undefined'",
            $source,
            'L\'installation doit rester conditionnée à l\'existence d\'un navigateur, sans quoi le fichier n\'est plus importable par « node --test ».'
        );

        self::assertFileExists(
            __DIR__ . '/../js/veille-erreurs.test.mjs',
            'Le filtre décide de ce que l\'équipe verra : il ne doit jamais rester sans test.'
        );
    }

    /**
     * LES TROIS BRANCHES SONT SURVEILLÉES, PAS SEULEMENT LE PORTAIL.
     *
     * ── CE QUE CE TEST PROTÈGE ──────────────────────────────────────────────
     * Joseara est trois applications sous un même toit, et la veille n'existe
     * dans chacune que parce qu'elles partagent `base.html.twig`, d'où part
     * l'unique `importmap('app')` qui charge `app.js`.
     *
     * Ce lien est INVISIBLE : rien, dans le gabarit de la Console, ne dit
     * qu'il conditionne la surveillance des erreurs. Le jour où une branche
     * redéclare `{% block importmap %}` pour n'y charger que son propre point
     * d'entrée — geste banal, et qui marche —, elle cesse silencieusement
     * d'être surveillée. Personne ne le verrait : la page fonctionne, et
     * l'absence d'erreurs remontées ressemble à l'absence d'erreurs.
     *
     * L'équipe Joseara travaille dans la Console : c'est précisément la branche
     * qu'on remarquerait le moins, puisqu'on y est soi-même quand ça casse.
     */
    public function testLesTroisBranchesChargentLaVeille(): void
    {
        $racine = \dirname(__DIR__, 2) . '/templates';

        // Le socle commun charge le point d'entrée unique.
        $socle = (string) file_get_contents($racine . '/base.html.twig');
        self::assertStringContainsString(
            "importmap('app')",
            $socle,
            'templates/base.html.twig doit rester le seul point de chargement de app.js.'
        );

        // La Console et l'espace de travail en héritent.
        self::assertStringContainsString(
            "{% extends 'base.html.twig' %}",
            (string) file_get_contents($racine . '/console/base.html.twig'),
            'La Console doit hériter du socle commun, sinon l\'équipe Joseara travaille sans surveillance.'
        );

        // Et PERSONNE ne redéclare le bloc : c'est là qu'une branche se
        // décrocherait sans bruit.
        $declarations = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));
        foreach ($fichiers as $fichier) {
            if (!$fichier->isFile() || 'twig' !== $fichier->getExtension()) {
                continue;
            }

            if (str_contains((string) file_get_contents($fichier->getPathname()), '{% block importmap %}')) {
                $declarations[] = str_replace('\\', '/', $fichier->getPathname());
            }
        }

        self::assertCount(
            1,
            $declarations,
            'Un seul gabarit doit déclarer « block importmap » (le socle). Toute redéclaration retire la veille des erreurs à la branche concernée, sans que rien ne le signale : ' . implode(', ', $declarations)
        );
        self::assertStringEndsWith('/templates/base.html.twig', $declarations[0]);
    }

    /**
     * La route de collecte est celle que le serveur expose.
     *
     * Deux fichiers, deux langages, aucun compilateur pour les accorder : un
     * renommage de la route côté PHP laisserait le JavaScript appeler une URL
     * qui répond 404, et la veille se tairait sans que rien ne le dise.
     */
    public function testLaRouteDeCollecteEstLaMemeDesDeuxCotes(): void
    {
        self::assertStringContainsString(
            "fetch('/api/journal/navigateur'",
            self::veille(),
        );

        $controleur = (string) file_get_contents(__DIR__ . '/../../src/Controller/Api/JournalNavigateurController.php');

        self::assertStringContainsString("#[Route('/api/journal')]", $controleur);
        self::assertStringContainsString("'/navigateur'", $controleur);
    }
}
