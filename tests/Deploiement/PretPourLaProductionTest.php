<?php

namespace App\Tests\Deploiement;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Les invariants de la MISE EN PRODUCTION, verrouillés par des tests.
 *
 * ── POURQUOI CES TESTS EXISTENT ─────────────────────────────────────────────
 * Chacun d'eux correspond à un défaut qui, au 2026-09-13, aurait empêché le
 * site de fonctionner sur l'hébergement mutualisé — et qu'aucun test ne
 * signalait, parce que tous ces défauts sont INVISIBLES EN DÉVELOPPEMENT :
 * le serveur de développement réécrit les URL tout seul, il a Internet, il
 * envoie ses e-mails dans un profiler, et personne ne redéploie par-dessus une
 * session ouverte.
 *
 * Un test qui ne passe qu'en production n'aide personne. Ceux-ci lisent la
 * configuration et les fichiers, et valent donc partout — c'est ce qui permet
 * de les faire échouer sur le poste, avant la publication, plutôt que sur le
 * serveur, après.
 */
final class PretPourLaProductionTest extends KernelTestCase
{
    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Sans ce fichier, Apache cherche un fichier réel pour chaque URL : toutes
     * les routes de l'application répondent 404, et seule « / » fonctionne.
     * C'est le défaut n°1 de la mise en production, et le plus silencieux —
     * rien, en développement, ne peut le révéler.
     */
    public function testLaRacineWebPorteSesReglesDeReecriture(): void
    {
        $htaccess = self::racine() . '/public/.htaccess';

        self::assertFileExists($htaccess, 'public/.htaccess est INDISPENSABLE sur Apache : sans lui, toutes les routes répondent 404.');

        $contenu = (string) file_get_contents($htaccess);
        self::assertStringContainsString('RewriteEngine On', $contenu);
        self::assertStringContainsString('index.php', $contenu, 'La règle de repli vers le contrôleur frontal doit être présente.');
    }

    /**
     * Les dossiers qui reçoivent des fichiers TÉLÉVERSÉS doivent refuser de les
     * exécuter : un document nommé « facture.php » y deviendrait sinon un
     * interpréteur de commandes à distance, ouvert à quiconque peut joindre une
     * pièce à un sinistre.
     */
    public function testLesDossiersDeTeleversementRefusentLExecution(): void
    {
        foreach (['public/uploads', 'public/images/entreprises'] as $dossier) {
            $htaccess = self::racine() . '/' . $dossier . '/.htaccess';

            self::assertFileExists($htaccess, sprintf('%s reçoit des fichiers utilisateurs : il lui faut un .htaccess qui interdit PHP.', $dossier));
            self::assertStringContainsString('RemoveHandler', (string) file_get_contents($htaccess));
        }
    }

    /**
     * Le dossier existe-t-il au clone ? EntreprisePDFMessageHandler y écrit ;
     * sans .gitkeep, l'écriture échoue sur un dossier inexistant et le message
     * part en file « failed », où personne ne le regarde.
     */
    public function testLeDossierDesPdfSurvitAuClone(): void
    {
        self::assertFileExists(self::racine() . '/public/pdfs/.gitkeep');
    }

    /**
     * .env est VERSIONNÉ sur un dépôt PUBLIC. Tout ce qu'on y écrit est lisible
     * par n'importe qui : il ne doit porter que des valeurs de développement.
     *
     * Le secret qui s'y trouvait signait les cookies « remember_me » de sept
     * jours ET les signatures de webhook de paiement (config/services.yaml).
     */
    public function testLeFichierEnvVersionneNePortePasDeSecretReel(): void
    {
        $env = (string) file_get_contents(self::racine() . '/.env');

        self::assertStringNotContainsString(
            'APP_SECRET=cbd98f790879efc71d1a657d3223782c',
            $env,
            'Le secret compromis est de retour dans .env — il est public, et il signe les cookies de session.'
        );

        // Un APP_SECRET de développement se reconnaît à ce qu'il ne ressemble
        // PAS à un secret : 32 caractères hexadécimaux, c'est une vraie clé.
        if (preg_match('/^APP_SECRET=([^\r\n]*)/m', $env, $trouve)) {
            self::assertDoesNotMatchRegularExpression(
                '/^[0-9a-f]{32,}$/i',
                trim($trouve[1]),
                'APP_SECRET de .env ressemble à une vraie clé. Les secrets vivent dans .env.local, sur le serveur.'
            );
        }

        self::assertMatchesRegularExpression(
            '/^AI_ENGINE=\s*$/m',
            $env,
            'AI_ENGINE doit rester VIDE dans .env : forcer « gemini » sans clé fait échouer l\'assistant au lieu de le laisser retomber sur le simulateur.'
        );
    }

    /**
     * Sans « default_uri », les URL fabriquées hors requête HTTP — crons,
     * commandes, gestionnaires de messages — pointent « http://localhost »,
     * c'est-à-dire le poste du DESTINATAIRE. Trois chemins du code en
     * dépendent, dont les campagnes CRM envoyées à de vrais clients.
     */
    public function testLesLiensFabriquesHorsRequeteOntUneBase(): void
    {
        // Le test ne peut PAS exiger un hôte de production : en développement et
        // en test, « localhost » est la bonne réponse. Ce qui doit être vrai
        // PARTOUT, c'est que le routeur lise DEFAULT_URI au lieu de retomber sur
        // l'implicite de Symfony — car c'est cet implicite, et lui seul, qui
        // produisait « http://localhost » dans les e-mails envoyés par un cron
        // de production.
        $routage = (string) file_get_contents(self::racine() . '/config/packages/routing.yaml');
        self::assertMatchesRegularExpression(
            '/^\s*default_uri:\s*.%env\(DEFAULT_URI\)%/m',
            $routage,
            'default_uri doit venir de l\'environnement, sinon les liens fabriqués hors requête HTTP pointent « http://localhost ».'
        );

        // Et la déclaration doit réellement ABOUTIR jusqu'au routeur : le
        // contexte doit porter ce que DEFAULT_URI annonce, quel que soit
        // l'environnement. C'est le câblage qu'on vérifie, pas la valeur.
        $attendu = parse_url((string) ($_ENV['DEFAULT_URI'] ?? ''));
        self::assertIsArray($attendu, 'DEFAULT_URI doit être définie (.env, ou .env.local sur le serveur).');
        self::assertArrayHasKey('host', $attendu, 'DEFAULT_URI doit être une URL absolue.');

        self::bootKernel();
        $contexte = self::getContainer()->get('router')->getContext();

        self::assertSame($attendu['host'], $contexte->getHost(), 'Le routeur ne lit pas DEFAULT_URI.');

        if (isset($attendu['port'])) {
            $port = 'https' === ($attendu['scheme'] ?? 'http')
                ? $contexte->getHttpsPort()
                : $contexte->getHttpPort();
            self::assertSame($attendu['port'], $port, 'Le port de DEFAULT_URI n\'atteint pas le contexte du routeur.');
        }
    }

    /**
     * Les sessions doivent vivre HORS de var/cache. Sinon, « cache:clear » —
     * c'est-à-dire CHAQUE déploiement — déconnecte tous les utilisateurs, et
     * aucun ordre d'opérations dans le script de déploiement n'y change rien :
     * c'est le dossier lui-même qui disparaît.
     */
    public function testLesSessionsSurviventAUnDeploiement(): void
    {
        $framework = (string) file_get_contents(self::racine() . '/config/packages/framework.yaml');

        self::assertMatchesRegularExpression(
            '/save_path:\s*.%kernel\.project_dir%\/var\/sessions/',
            $framework,
            'Les sessions doivent être rangées hors de var/cache, sinon chaque déploiement déconnecte tout le monde.'
        );
    }

    /**
     * En production, les e-mails doivent partir PENDANT la requête : aucun
     * superviseur ne maintient de worker vivant sur un hébergement mutualisé,
     * et « async » sans worker ne retarde pas les e-mails — il les perd de vue,
     * empilés dans messenger_messages, sans erreur ni trace.
     */
    public function testLesEmailsPartentPendantLaRequeteEnProduction(): void
    {
        $messenger = (string) file_get_contents(self::racine() . '/config/packages/messenger.yaml');

        $apresWhenProd = strstr($messenger, 'when@prod:');
        self::assertIsString($apresWhenProd, 'config/packages/messenger.yaml doit porter un bloc when@prod.');

        self::assertMatchesRegularExpression(
            '/Symfony\\\\Component\\\\Mailer\\\\Messenger\\\\SendEmailMessage:\s*sync/',
            $apresWhenProd,
            'Sans ce routage, AUCUN e-mail ne part en production : ni inscription, ni invitation, ni lien de relevé client.'
        );
    }

    /**
     * Aucun document produit CÔTÉ SERVEUR ne doit dépendre d'un CDN au moment
     * du rendu : le serveur sortirait alors sur Internet, en synchrone, à
     * chaque PDF — ce qu'un mutualisé fait mal, quand il le fait.
     */
    public function testAucunGabaritDeDocumentNAppelleUnCdn(): void
    {
        $gabarit = self::racine() . '/templates/admin/note/note_preview.html.twig';
        $contenu = (string) file_get_contents($gabarit);

        self::assertStringNotContainsString(
            'cdn.jsdelivr.net',
            $contenu,
            'note_preview.html.twig est rendu par DomPDF : un <link> distant y devient un téléchargement HTTPS synchrone à chaque PDF.'
        );

        $controleur = (string) file_get_contents(self::racine() . '/src/Controller/Admin/NoteController.php');
        self::assertStringNotContainsString(
            "set('isRemoteEnabled', true)",
            $controleur,
            'DomPDF ne doit pas avoir le droit de sortir sur le réseau.'
        );
    }

    /**
     * La feuille de style incorporée dans le PDF doit être RÉSOLVABLE. Le
     * namespace Twig « @assetsvendor » pointe sur assets/vendor, versionné
     * justement pour que le rendu ne dépende de rien d'extérieur.
     */
    public function testLaFeuilleDeStyleDuPdfEstResolvableLocalement(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $source = $twig->getLoader()->getSourceContext('@assetsvendor/bootstrap/dist/css/bootstrap.min.css');

        self::assertNotSame('', trim($source->getCode()), 'Le Bootstrap local est introuvable : le PDF de note sortirait sans style.');
    }

    /**
     * composer.json doit DÉCLARER les extensions dont l'application a besoin.
     * Les polyfills ctype / iconv / mbstring sont neutralisés par le bloc
     * « replace » : pour ces trois-là, il n'existe aucun filet. Sans
     * déclaration, « composer install » ne détecte rien et c'est l'application
     * qui tombe en erreur fatale, en production, à la première requête.
     */
    public function testLesExtensionsPhpSontDeclarees(): void
    {
        $composer = json_decode((string) file_get_contents(self::racine() . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $requises = array_keys($composer['require']);

        foreach (['ext-mbstring', 'ext-intl', 'ext-gd', 'ext-zip', 'ext-dom', 'ext-pdo_mysql', 'ext-curl', 'ext-fileinfo'] as $extension) {
            self::assertContains(
                $extension,
                $requises,
                sprintf('%s est indispensable et n\'est pas déclarée : l\'absence se découvrirait en production, pas au déploiement.', $extension)
            );
        }
    }

    /**
     * Les dépendances front doivent être VERSIONNÉES. C'est ce qui supprime la
     * dépendance du déploiement à cdn.jsdelivr.net — un accès qu'un mutualisé
     * ne garantit pas, et dont l'échec laisse le site sans JS ni CSS.
     */
    public function testLesDependancesFrontSontVersionnees(): void
    {
        $gitignore = (string) file_get_contents(self::racine() . '/.gitignore');

        self::assertDoesNotMatchRegularExpression(
            '/^\/assets\/vendor\/\s*$/m',
            $gitignore,
            'assets/vendor doit rester versionné : sinon le déploiement dépend à nouveau d\'un CDN.'
        );

        self::assertFileExists(self::racine() . '/assets/vendor/installed.php');
    }
}
