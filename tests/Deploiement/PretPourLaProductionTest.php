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
     * Rien de ce qui n'est pas destine au navigateur ne doit sortir de la
     * racine web.
     *
     * Le piege verifie ici : les journaux d'erreurs PHP n'ont PAS d'extension.
     * « error_log » echappait donc a la regle qui bloque *.log, et PHP l'ecrit
     * dans le repertoire du script courant — c'est-a-dire dans public/, quoi
     * qu'on fasse. Constate le 2026-09-13 sur joseara.com : le fichier est
     * apparu tout seul des la premiere requete. Servi en clair, il donne les
     * traces d'execution, les chemins absolus du serveur, et parfois des
     * fragments de requetes.
     */
    public function testLaRacineWebNeLaisseFuirNiConfigurationNiJournaux(): void
    {
        $htaccess = (string) file_get_contents(self::racine() . '/public/.htaccess');

        // Les motifs declares dans le .htaccess, extraits tels quels : on teste
        // ce que le serveur appliquera, pas une regle recopiee a cote.
        preg_match_all('/<FilesMatch\s+"([^"]+)"\s*>\s*Require all denied/i', $htaccess, $trouves);
        $motifs = $trouves[1] ?? [];

        self::assertNotEmpty($motifs, 'Le .htaccess doit refuser explicitement des familles de fichiers.');

        $interdits = ['.env', '.env.local', 'error_log', 'php_errorlog', 'prod.log', 'services.yaml', 'composer.lock'];

        foreach ($interdits as $nom) {
            $bloque = false;
            foreach ($motifs as $motif) {
                if (preg_match('/' . str_replace('/', '\/', $motif) . '/i', $nom)) {
                    $bloque = true;
                    break;
                }
            }
            self::assertTrue($bloque, sprintf('« %s » serait servi en clair depuis la racine web.', $nom));
        }

        // Et l'inverse : les fichiers que le navigateur DOIT pouvoir lire.
        // asset-map:compile ecrit ces deux-la, et sans eux aucun script ne charge.
        foreach (['manifest.json', 'importmap.json', 'app-3f8a2b9c1d.js'] as $nom) {
            foreach ($motifs as $motif) {
                self::assertDoesNotMatchRegularExpression(
                    '/' . str_replace('/', '\/', $motif) . '/i',
                    $nom,
                    sprintf('« %s » doit rester accessible : le front en depend.', $nom)
                );
            }
        }
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
     * Le jeu de caractères des tables ne doit dépendre d'AUCUN serveur.
     *
     * Le « charset » de DATABASE_URL ne règle que la connexion. Ce qu'une table
     * adopte à sa création vient du défaut de la BASE — donc de l'hébergeur.
     * Celui de joseara.com annonce latin1 : sans cette déclaration, les 27
     * CREATE TABLE écrits à la main (crm_*, demande_conge, assistant_document,
     * les congés) seraient nés en latin1 à côté de 26 autres en utf8mb4.
     *
     * Et la panne n'aurait pas ressemblé à un problème d'encodage : une
     * jointure entre deux tables de collations différentes échoue sur
     * « Illegal mix of collations », à un endroit qui ne dit rien de la cause.
     */
    public function testLeJeuDeCaracteresDesTablesNeDependDAucunServeur(): void
    {
        $doctrine = (string) file_get_contents(self::racine() . '/config/packages/doctrine.yaml');

        self::assertMatchesRegularExpression(
            '/default_table_options:\s+charset:\s*utf8mb4/',
            $doctrine,
            'doctrine.yaml doit imposer utf8mb4 aux tables créées, sinon elles héritent du défaut de l\'hébergeur.'
        );

        self::assertMatchesRegularExpression(
            '/collate:\s*utf8mb4_unicode_ci/',
            $doctrine,
            'La collation doit être utf8mb4_unicode_ci — celle des tables déjà créées, et la seule qui trie correctement les accents.'
        );

        // Et la déclaration doit ABOUTIR jusqu'à la connexion : c'est Doctrine
        // qu'on interroge, pas le fichier. Une clé mal placée dans le YAML
        // serait acceptée sans broncher et n'aurait aucun effet.
        self::bootKernel();
        $parametres = self::getContainer()->get('doctrine.dbal.default_connection')->getParams();

        self::assertSame(
            'utf8mb4',
            $parametres['defaultTableOptions']['charset'] ?? null,
            'Le charset par défaut n\'atteint pas la connexion Doctrine.'
        );
        self::assertSame(
            'utf8mb4_unicode_ci',
            $parametres['defaultTableOptions']['collate'] ?? null,
            'La collation par défaut n\'atteint pas la connexion Doctrine.'
        );
    }

    /**
     * L'application doit DÉCLARER ses exigences PHP, et pas seulement compter
     * sur un panneau d'hébergeur.
     *
     * « max_input_vars » n'est pas exposé par le sélecteur PHP de CloudLinux —
     * or c'est le réglage le plus traître de l'installation : au-delà de la
     * limite, PHP tronque le POST EN SILENCE et l'enregistrement perd des
     * champs, sans erreur nulle part. Le poser dans un fichier versionné, c'est
     * le faire survivre au changement d'hébergeur, à la réinitialisation du
     * panneau, et à l'oubli de celui qui installera la prochaine instance.
     */
    public function testLApplicationDeclareSesExigencesPhp(): void
    {
        $userIni = self::racine() . '/public/.user.ini';

        self::assertFileExists($userIni, 'public/.user.ini porte les exigences PHP que le panneau de l\'hébergeur n\'expose pas toutes.');

        $contenu = (string) file_get_contents($userIni);

        self::assertMatchesRegularExpression(
            '/^\s*max_input_vars\s*=\s*([5-9]\d{3}|\d{5,})/m',
            $contenu,
            'max_input_vars doit valoir au moins 5000 : en dessous, les formulaires canevas perdent des champs sans le dire.'
        );

        // post_max_size doit DÉPASSER upload_max_filesize : la requête
        // transporte le fichier PLUS les champs du formulaire. À valeur égale,
        // un fichier à la taille maximale est rejeté — et le message d'erreur
        // parle du POST, pas du fichier, ce qui envoie chercher au mauvais endroit.
        preg_match('/^\s*post_max_size\s*=\s*(\d+)M/m', $contenu, $post);
        preg_match('/^\s*upload_max_filesize\s*=\s*(\d+)M/m', $contenu, $upload);

        self::assertNotEmpty($post, 'post_max_size doit être déclarée.');
        self::assertNotEmpty($upload, 'upload_max_filesize doit être déclarée.');
        self::assertGreaterThan(
            (int) $upload[1],
            (int) $post[1],
            'post_max_size doit dépasser upload_max_filesize, sinon un fichier à la taille maximale est refusé.'
        );
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
