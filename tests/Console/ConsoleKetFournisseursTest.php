<?php

namespace App\Tests\Console;

use App\Ai\Engine\AiEngineInterface;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Entity\Utilisateur;
use App\Token\ParametresTokenService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ÉCRAN DE CONSOLE QUI PILOTE LES FOURNISSEURS DE KET.
 *
 * Trois choses à protéger, dans cet ordre d'importance :
 *  - la POLITIQUE ENREGISTRÉE EST CELLE QUI S'APPLIQUE. Un écran de réglage qui
 *    n'a pas d'effet est pire que pas d'écran du tout : l'agent croit avoir agi.
 *  - l'ATOMICITÉ : une famille au JSON cassé n'enregistre RIEN, pas même les
 *    quatre autres — sinon l'écran est à moitié pris en compte et personne ne
 *    sait laquelle manque.
 *  - l'accès, super-admin seulement, comme le plan tarifaire et le CRM.
 *
 * ⚠ `plateforme_parametres` est un singleton GLOBAL sans rollback : on le purge en
 * setUp ET tearDown, sinon on fait échouer des tests d'autres fichiers.
 */
class ConsoleKetFournisseursTest extends WebTestCase
{
    private const USER = 'phpunit-ketfourn-user@test.local';
    private const SUPER = 'phpunit-ketfourn-super@test.local';
    private const PASSWORD = 'Test1234!';
    private const URL = '/console/ket/fournisseurs';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        foreach ([self::USER => [], self::SUPER => ['ROLE_SUPER_ADMIN']] as $email => $roles) {
            $u = (new Utilisateur())->setEmail($email)->setNom('PHPUnit ' . $email)->setVerified(true);
            $u->setRoles($roles);
            $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
            $em->persist($u);
        }
        $em->flush();
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
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::USER, self::SUPER]],
            ['e' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM plateforme_parametres');
        $this->em()->clear();
        static::getContainer()->get(ParametresTokenService::class)->refresh();
        static::getContainer()->get(PolitiqueDesFournisseurs::class)->refresh();
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testLaPageEstReserveeAuSuperAdmin(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * L'écran montre les VALEURS EFFECTIVES, pas la seule personnalisation. Sur une
     * base vierge, l'agent doit voir la politique réellement en vigueur — celle du
     * serveur — et non cinq champs vides qui lui feraient croire que rien n'est
     * configuré.
     */
    public function testLaPageMontreLaPolitiqueEffective(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();

        $champ = $crawler->filter('input[name="ket_fournisseurs[moteurJson]"]');
        self::assertCount(1, $champ);

        $politique = json_decode($champ->attr('value'), true);
        self::assertSame('chaine', $politique['mode']);
        self::assertContains('anthropic', $politique['ordre']);
        self::assertContains('gemini', $politique['ordre']);

        // Un éditeur par famille : cinq, ni plus ni moins.
        self::assertCount(5, $crawler->filter('[data-controller="fournisseurs-editor"]'));
    }

    /**
     * LE TEST QUI JUSTIFIE L'ÉCRAN. Enregistrer un ordre doit changer QUI RÉPOND —
     * sinon l'agent règle une page qui ne pilote rien.
     */
    public function testLaPolitiqueEnregistreeEstCelleQuiSApplique(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);
        $form = $crawler->filter('form')->form();

        // Le moteur simulé en tête : c'est le seul toujours disponible en test, donc
        // le seul dont on puisse affirmer qu'il répondra.
        $form['ket_fournisseurs[moteurJson]'] = json_encode([
            'mode'  => 'chaine',
            'ordre' => ['simulated', 'anthropic', 'gemini'],
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects(self::URL);

        static::getContainer()->get(PolitiqueDesFournisseurs::class)->refresh();
        self::assertSame(
            'simulated,anthropic,gemini',
            static::getContainer()->get(PolitiqueDesFournisseurs::class)->ordre('moteur'),
        );
        self::assertSame('simulated', static::getContainer()->get(AiEngineInterface::class)->name());
    }

    /**
     * ÉPINGLER COUPE LES AUTRES : la liste effective se réduit au premier nom. Sans
     * cela, « épinglé » ne serait qu'une étiquette.
     */
    public function testEpinglerNeLaisseQuUnSeulFournisseur(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);
        $form = $crawler->filter('form')->form();

        $form['ket_fournisseurs[voixJson]'] = json_encode([
            'mode'  => 'epingle',
            'ordre' => ['gemini', 'elevenlabs'],
        ]);
        $this->client->submit($form);

        static::getContainer()->get(PolitiqueDesFournisseurs::class)->refresh();
        self::assertSame('gemini', static::getContainer()->get(PolitiqueDesFournisseurs::class)->ordre('voix'));
    }

    /**
     * ATOMICITÉ. Une famille illisible et rien ne part en base — pas même les
     * quatre autres, saisies correctement dans la même soumission.
     *
     * On interroge le SQL BRUT : le conteneur est partagé entre le test et la
     * requête, donc l'entité en mémoire ne prouverait rien.
     */
    public function testUnJsonInvalideNEnregistreRienDuTout(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);
        $form = $crawler->filter('form')->form();

        $form['ket_fournisseurs[moteurJson]'] = json_encode(['ordre' => ['simulated']]);
        $form['ket_fournisseurs[voixJson]'] = '{ ceci n’est pas du JSON';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422, 'Le formulaire est réaffiché avec son erreur.');
        self::assertNull(
            $this->em()->getConnection()->fetchOne('SELECT ket_fournisseurs FROM plateforme_parametres') ?: null,
            'Une seule famille illisible et RIEN ne s’enregistre.',
        );
    }

    /**
     * RÉARMER efface la marque d'épuisement — sans quoi une marque posée à tort
     * laisse un fournisseur hors jeu jusqu'à son échéance, sans autre recours qu'un
     * accès serveur.
     */
    public function testLeRearmementEffaceLaMarque(): void
    {
        $memoire = static::getContainer()->get(MemoireDEpuisement::class);
        $cle = MemoireDEpuisement::cle('moteur', 'gemini', 'modele-de-test');
        $memoire->marquer($cle, 3600);
        self::assertTrue($memoire->estEpuise($cle));

        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);
        // Le jeton se lit sur la PAGE, pas dans le conteneur : hors requête, il n'y
        // a pas de session, donc pas de jeton à fabriquer.
        $token = $crawler->filter('#kf-rearmer-form input[name="_token"]')->attr('value');

        $this->client->request('POST', self::URL . '/rearmer', ['cle' => $cle, '_token' => $token]);

        self::assertResponseRedirects(self::URL);
        self::assertFalse($memoire->estEpuise($cle));
    }

    /**
     * LE BOUTON « ENREGISTRER » DOIT APPARTENIR AU FORMULAIRE.
     *
     * Incident du 2026-09-22 : le formulaire de réarmement avait été posé DANS le
     * corps du formulaire principal. Un `<form>` imbriqué est du HTML invalide, et
     * le navigateur le traite d'une façon qu'aucun test de ce projet ne reproduisait
     * jusqu'ici : il IGNORE la balise ouvrante du second formulaire, mais HONORE sa
     * fermante — qui referme donc le PREMIER. Tout ce qui suit, y compris le bouton
     * d'envoi et la barre de progression, se retrouvait hors formulaire : un clic ne
     * partait nulle part, sans message, sans erreur en console, sans rien.
     *
     * POURQUOI LE CRAWLER NE L'A PAS VU. `$crawler->filter('form')->form()` s'appuie
     * sur un analyseur permissif qui, lui, crée bien le formulaire imbriqué : les six
     * tests ci-dessus soumettaient un formulaire que le navigateur, lui, n'avait pas.
     * D'où cette assertion sur le HTML BRUT, la seule qui parle le même langage que
     * la règle d'analyse du navigateur.
     */
    public function testAucunFormulaireImbriqueNeCoupeLeBoutonDEnvoi(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', self::URL);

        $html = (string) $this->client->getResponse()->getContent();

        // Un point de départ à coup sûr DANS le formulaire principal : son premier
        // champ. Et un point d'arrivée : le bouton qui doit l'envoyer.
        $debut = strpos($html, 'name="ket_fournisseurs[moteurJson]"');
        self::assertNotFalse($debut, 'Le champ de la famille « moteur » a disparu de la page.');

        $bouton = strpos($html, '<button type="submit"', $debut);
        self::assertNotFalse($bouton, 'Le bouton d’envoi ne suit plus les champs.');

        $entreDeux = substr($html, $debut, $bouton - $debut);
        self::assertStringNotContainsString(
            '</form>',
            $entreDeux,
            'Une balise </form> referme le formulaire AVANT son bouton d’envoi : le bouton sera inerte.',
        );
        self::assertStringNotContainsString(
            '<form',
            $entreDeux,
            'Un formulaire imbriqué : le navigateur refermera le formulaire principal avant son bouton.',
        );
    }

    /** Un réarmement sans jeton CSRF valide ne doit rien effacer. */
    public function testUnRearmementSansJetonNeFaitRien(): void
    {
        $memoire = static::getContainer()->get(MemoireDEpuisement::class);
        $cle = MemoireDEpuisement::cle('moteur', 'gemini', 'modele-de-test');
        $memoire->marquer($cle, 3600);

        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('POST', self::URL . '/rearmer', ['cle' => $cle, '_token' => 'faux']);

        self::assertTrue($memoire->estEpuise($cle));
    }
}
