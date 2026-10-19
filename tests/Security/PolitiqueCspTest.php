<?php

namespace App\Tests\Security;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA CONTENT-SECURITY-POLICY (chantier 9).
 *
 * Le filet qui reste quand tout le reste a échoué : même si une faille d'échappement
 * passait, le navigateur refuserait d'exécuter un script que nous n'avons pas prévu. Ce
 * test tient la politique — portée sur le HTML seulement, nonce par requête, nonce dans
 * script-src et JAMAIS dans style-src (il y annulerait 'unsafe-inline') — et vérifie que
 * chaque <script> inline des pages porte le nonce de SA réponse.
 */
class PolitiqueCspTest extends WebTestCase
{
    private const EMAIL = 'phpunit-csp@test.local';
    private const ENT = 'PHPUnit CSP SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE i FROM invite i JOIN utilisateur u ON u.id = i.utilisateur_id WHERE u.email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = ?', [self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    /** @return array<string, string> directive => valeur */
    private function politique(): array
    {
        $entete = (string) $this->client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
        self::assertNotSame('', $entete, 'Aucune politique sur une page HTML.');
        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $entete))) as $directive) {
            [$nom, $valeur] = array_pad(explode(' ', $directive, 2), 2, '');
            $directives[$nom] = $valeur;
        }

        return $directives;
    }

    private function nonce(): string
    {
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $this->politique()['script-src'] ?? '', $m), 'Pas de nonce dans script-src.');

        return $m[1];
    }

    public function testUnePageHtmlPorteLaPolitique(): void
    {
        $this->client->request('GET', 'https://localhost/login');
        $directives = $this->politique();

        self::assertSame("'self'", $directives['default-src'] ?? null);
        self::assertStringContainsString("'report-sample'", $directives['script-src'] ?? '');
        self::assertStringNotContainsString("'unsafe-inline'", $directives['script-src'] ?? '', 'Les scripts inline ne passent que par le nonce.');
        self::assertStringNotContainsString("'unsafe-eval'", $directives['script-src'] ?? '');
        self::assertSame("'none'", $directives['object-src'] ?? null);
        self::assertSame("'self'", $directives['base-uri'] ?? null);
        self::assertSame("'self'", $directives['form-action'] ?? null);
        self::assertSame("'self'", $directives['frame-ancestors'] ?? null);
        self::assertSame('/api/journal/csp', $directives['report-uri'] ?? null);
        self::assertSame('csp', $directives['report-to'] ?? null);
        self::assertSame('csp="/api/journal/csp"', $this->client->getResponse()->headers->get('Reporting-Endpoints'));
    }

    /**
     * En HTTP, `report-to` ne partirait pas — et sa seule présence ferait ignorer
     * `report-uri` : il n'est déclaré qu'en HTTPS (constaté au navigateur, 2026-10-19).
     */
    public function testEnHttpSeulReportUriEstDeclare(): void
    {
        $this->client->request('GET', 'http://localhost/login');
        $directives = $this->politique();

        self::assertSame('/api/journal/csp', $directives['report-uri'] ?? null);
        self::assertArrayNotHasKey('report-to', $directives);
        self::assertFalse($this->client->getResponse()->headers->has('Reporting-Endpoints'));
    }

    /** Un nonce dans style-src y annulerait 'unsafe-inline' : les 1 594 attributs style= casseraient. */
    public function testLeNonceNEstJamaisDansStyleSrc(): void
    {
        $this->client->request('GET', '/login');
        $style = $this->politique()['style-src'] ?? '';

        self::assertStringContainsString("'unsafe-inline'", $style);
        self::assertStringNotContainsString('nonce-', $style);
    }

    public function testLeNonceChangeAChaqueRequete(): void
    {
        $this->client->request('GET', '/login');
        $premier = $this->nonce();
        $this->client->request('GET', '/login');

        self::assertNotSame($premier, $this->nonce());
        self::assertGreaterThanOrEqual(22, \strlen($premier), 'Au moins 128 bits d\'aléa.');
    }

    /** @return list<string> les <script> SANS src de la réponse, chacun avec son attribut nonce (ou '') */
    private function noncesDesScriptsInline(): array
    {
        preg_match_all('/<script\b(?![^>]*(?<![\w-])src=)([^>]*)>/i', (string) $this->client->getResponse()->getContent(), $m);

        return array_map(static fn (string $attributs): string => preg_match('/\bnonce="([^"]*)"/', $attributs, $n) ? $n[1] : '', $m[1]);
    }

    public function testChaqueScriptInlineDeLaConnexionPorteLeNonceDeSaReponse(): void
    {
        $this->client->request('GET', '/login');
        $nonce = $this->nonce();
        $nonces = $this->noncesDesScriptsInline();

        self::assertNotEmpty($nonces, 'La page de connexion a des scripts inline (sonde du terminal, importmap).');
        self::assertSame(array_fill(0, \count($nonces), $nonce), $nonces);
    }

    public function testChaqueScriptInlineDeLEspaceDeTravailPorteLeNonceDeSaReponse(): void
    {
        $em = $this->em();
        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('CSP')->setVerified(true)->setPassword('x');
        $em->persist($user);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($ent);
        $user->setConnectedTo($ent);
        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($ent);
        $em->persist($invite);
        $em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $invite->getId(), $ent->getId()));
        self::assertResponseIsSuccessful();
        $nonce = $this->nonce();
        $nonces = $this->noncesDesScriptsInline();

        self::assertGreaterThanOrEqual(3, \count($nonces), 'Sonde, menu (état avant rendu) et importmap.');
        self::assertSame(array_fill(0, \count($nonces), $nonce), $nonces);
    }

    /**
     * Une CSS importée par le JS devient un module `data:application/javascript,` que
     * script-src refuse : les feuilles sont posées en <link>, aucune n'est importée.
     */
    public function testLesFeuillesSontDesLiensEtJamaisDesModulesData(): void
    {
        $this->client->request('GET', '/login');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(1, preg_match('#<script type="importmap"[^>]*>(.*?)</script>#s', $html, $m));
        $carte = json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
        // Une entrée `data:` NON importée ne s'exécute jamais (CSS listées dans importmap.php) ;
        // seule compte une entrée que le graphe de l'application importe : les préchargées.
        self::assertSame([], array_keys(array_filter($carte['imports'], static fn (string $url): bool => 'data:application/javascript,' === $url)), 'CSS importée par le JS : la poser en <link> (segments/_feuilles_de_l_application).');
        foreach (['styles/app', 'styles/interactive-menu', 'tom-select.bootstrap5', 'ux-toggle-password/style.min'] as $feuille) {
            self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/[^"]*' . preg_quote($feuille, '#') . '-[^"]+\.css">#', $html);
        }
    }

    public function testUneReponseJsonNePortePasDePolitique(): void
    {
        $this->client->request('POST', '/api/payment/webhook/fournisseur-inconnu');

        self::assertStringContainsString('json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertFalse($this->client->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
        self::assertFalse($this->client->getResponse()->headers->has('Content-Security-Policy'));
    }

    /** Garde-fou statique : un <script> inline nouveau, sans le nonce, serait bloqué en production. */
    public function testAucunScriptInlineDesGabaritsSansNonce(): void
    {
        $sansNonce = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            $chemin = str_replace('\\', '/', $fichier->getPathname());
            // E-mails et PDF ne passent pas par un navigateur : hors CSP.
            if (!str_ends_with($chemin, '.twig') || preg_match('#/templates/emails/|/pdf/|_pdf\.html|message_word#', $chemin)) {
                continue;
            }
            // Les commentaires Twig parlent de <script> sans en être : on les blanchit, lignes gardées.
            $html = (string) preg_replace_callback('/\{#.*?#\}/s', static fn (array $c): string => str_repeat("\n", substr_count($c[0], "\n")), (string) file_get_contents($chemin));
            // `(?<![\w-])src=` : un `data-src=` n'est pas une source.
            preg_match_all('/<script\b(?![^>]*(?<![\w-])src=)(?![^>]*type="text\/php")([^>]*)>/i', $html, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$attributs, $position]) {
                if (!str_contains($attributs, 'nonce="{{ csp_nonce() }}"')) {
                    $sansNonce[] = substr($chemin, strpos($chemin, '/templates/') + 1) . ':' . (substr_count(substr($html, 0, $position), "\n") + 1);
                }
            }
        }

        self::assertSame([], $sansNonce, '<script> inline sans nonce="{{ csp_nonce() }}".');
    }
}
