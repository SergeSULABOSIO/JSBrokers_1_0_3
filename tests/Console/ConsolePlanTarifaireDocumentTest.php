<?php

namespace App\Tests\Console;

use App\Ai\Document\DocumentTarificateur;
use App\Entity\Utilisateur;
use App\Token\ParametresTokenService;
use App\Token\TokenPricing;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Édition du barème des DOCUMENTS produits par l'IA, depuis la console.
 *
 * Trois choses à protéger :
 *  - l'accès (super-admin seulement, comme le reste de la tarification) ;
 *  - l'ATOMICITÉ : un JSON invalide n'enregistre rien du tout, pas même les
 *    scalaires valides saisis en même temps ;
 *  - le trajet complet console → base → facturation, y compris la fusion qui
 *    empêche un format personnalisé d'effacer les autres.
 *
 * ⚠ `plateforme_parametres` est un singleton GLOBAL sans rollback : on le purge en
 * setUp ET tearDown, sinon on fait échouer des tests d'autres fichiers.
 */
class ConsolePlanTarifaireDocumentTest extends WebTestCase
{
    private const USER = 'phpunit-docplan-user@test.local';
    private const SUPER = 'phpunit-docplan-super@test.local';
    private const PASSWORD = 'Test1234!';

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
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /** Remplit les champs obligatoires du formulaire, comme le ferait un agent. */
    private function formulaireComplet(\Symfony\Component\DomCrawler\Form $form): \Symfony\Component\DomCrawler\Form
    {
        $form['plan_tarifaire[freeAllowance]'] = '1000';
        $form['plan_tarifaire[freeWindowHours]'] = '8';
        $form['plan_tarifaire[readWeight]'] = '2';
        $form['plan_tarifaire[defaultWriteWeight]'] = '5';
        $form['plan_tarifaire[usdPerToken]'] = '0.001';

        return $form;
    }

    public function testLaPageEstReserveeAuSuperAdmin(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/console/plan-tarifaire');
        self::assertResponseStatusCodeSame(403);
    }

    /** La section documents est rendue, avec son éditeur et son champ caché. */
    public function testLaPageExposeLeBaremeDesDocuments(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', '/console/plan-tarifaire');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('[data-controller="formats-editor"]'));
        self::assertCount(1, $crawler->filter('input[type="hidden"][name="plan_tarifaire[documentFormatsJson]"]'));
        self::assertCount(1, $crawler->filter('dialog[data-formats-editor-target="dialog"]'));
        foreach (['documentBase', 'documentParPage', 'documentCaracteresParPage'] as $champ) {
            self::assertCount(1, $crawler->filter(sprintf('input[name="plan_tarifaire[%s]"]', $champ)));
        }
        // Aucun retour au JSON brut : la console n'a plus de textarea de ce genre.
        self::assertCount(0, $crawler->filter('textarea[name="plan_tarifaire[documentFormatsJson]"]'));
    }

    /** Les champs sont préremplis avec les valeurs EFFECTIVES, pas vides. */
    public function testLesChampsSontPreremplisAvecLesValeursEffectives(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', '/console/plan-tarifaire');

        self::assertSame(
            (string) TokenPricing::DOCUMENT_BASE,
            $crawler->filter('input[name="plan_tarifaire[documentBase]"]')->attr('value'),
        );
        self::assertSame(
            (string) TokenPricing::DOCUMENT_CARACTERES_PAR_PAGE,
            $crawler->filter('input[name="plan_tarifaire[documentCaracteresParPage]"]')->attr('value'),
        );

        // Le champ caché porte la carte FUSIONNÉE : tous les formats du code.
        $formats = json_decode($crawler->filter('input[name="plan_tarifaire[documentFormatsJson]"]')->attr('value'), true);
        self::assertSame([], array_diff_key(TokenPricing::DOCUMENT_FORMATS, $formats ?? []));
    }

    /** Le barème enregistré en console est celui qui facture. */
    public function testLeBaremeEnregistreEstCeluiQuiFacture(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', '/console/plan-tarifaire');

        $form = $this->formulaireComplet($crawler->filter('form')->form());
        $form['plan_tarifaire[documentBase]'] = '80';
        $form['plan_tarifaire[documentParPage]'] = '40';
        $form['plan_tarifaire[documentCaracteresParPage]'] = '2000';
        $form['plan_tarifaire[documentFormatsJson]'] = json_encode(['pdf' => 2.5]);
        $this->client->submit($form);

        self::assertResponseRedirects('/console/plan-tarifaire');

        $parametres = static::getContainer()->get(ParametresTokenService::class);
        $parametres->refresh();

        self::assertSame(80, $parametres->documentBase());
        self::assertSame(40, $parametres->documentParPage());
        self::assertSame(2000, $parametres->documentCaracteresParPage());

        // 100 caractères → 1 page → (80 + 40) × 1,0 = 120 en texte brut.
        self::assertSame(
            120,
            static::getContainer()->get(DocumentTarificateur::class)->chiffrer(str_repeat('a', 100), 'txt')->cout,
        );
    }

    /**
     * LE PIÈGE, éprouvé bout en bout. La base ne stocke que { "pdf": 2.5 } — c'est
     * le SERVICE qui refusionne. Ce test verrouille précisément ce partage de
     * responsabilité : sans lui, on pourrait « simplifier » la fusion et faire
     * retomber Word au multiplicateur neutre, en silence.
     */
    public function testPersonnaliserUnFormatNEffacePasLesAutres(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', '/console/plan-tarifaire');

        $form = $this->formulaireComplet($crawler->filter('form')->form());
        $form['plan_tarifaire[documentFormatsJson]'] = json_encode(['pdf' => 2.5]);
        $this->client->submit($form);
        self::assertResponseRedirects('/console/plan-tarifaire');

        $parametres = static::getContainer()->get(ParametresTokenService::class);
        $parametres->refresh();

        self::assertSame(2.5, $parametres->documentMultiplicateur('pdf'));
        self::assertSame(1.5, $parametres->documentMultiplicateur('docx'));
        self::assertSame(1.0, $parametres->documentMultiplicateur('txt'));
    }

    /**
     * ATOMICITÉ : un JSON invalide n'enregistre RIEN, pas même les scalaires
     * valides soumis dans le même envoi.
     */
    public function testUnJsonInvalideNEnregistreRienDuTout(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', '/console/plan-tarifaire');

        $form = $this->formulaireComplet($crawler->filter('form')->form());
        $form['plan_tarifaire[documentBase]'] = '777';
        $form['plan_tarifaire[documentFormatsJson]'] = '{oops';
        $this->client->submit($form);

        // Pas de redirection : le formulaire est réaffiché avec son erreur. Symfony
        // sert ce réaffichage en 422 (et non 200) depuis la 6.2 — c'est le code qui
        // dit « reçu, mais refusé », exactement ce qu'on veut ici.
        self::assertResponseStatusCodeSame(422);

        // On interroge la BASE, et non le service : le formulaire a bien posé 777
        // sur l'entité en mémoire (c'est un champ mappé), mais rien n'a été flushé.
        // En production cet objet sale meurt avec la requête ; ici le conteneur est
        // partagé avec le test, donc seul le SQL dit la vérité.
        $enBase = $this->em()->getConnection()->fetchOne('SELECT document_base FROM plateforme_parametres LIMIT 1');
        self::assertNull(
            $enBase === false ? null : $enBase,
            'Aucun réglage ne doit être persisté quand un JSON voisin est invalide.',
        );
    }
}
