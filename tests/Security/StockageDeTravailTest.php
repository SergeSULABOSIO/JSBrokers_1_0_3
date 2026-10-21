<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE STOCKAGE DU NAVIGATEUR N'APPARTIENT QU'À SON UTILISATEUR — côté serveur (chantier 15).
 *
 * Le serveur ne touche pas au stockage du navigateur : il dit à la page QUI est connecté
 * (identifiant numérique seul) et QUAND on vient de se déconnecter. assets/stockage-de-travail.js
 * efface en conséquence (règles éprouvées par tests/js/stockage-de-travail.test.mjs).
 */
class StockageDeTravailTest extends WebTestCase
{
    private const EMAIL = 'phpunit-stockage@test.local';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->supprimerUtilisateur();

        $user = new Utilisateur();
        $user->setEmail(self::EMAIL);
        $user->setNom('PHPUnit Stockage');
        $user->setVerified(true);
        $user->setPassword('inutilise');
        $this->em()->persist($user);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        $this->supprimerUtilisateur();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function utilisateur(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    private function supprimerUtilisateur(): void
    {
        if ($user = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL])) {
            $this->em()->remove($user);
            $this->em()->flush();
        }
    }

    /** La balise <html> de la dernière réponse. */
    private function baliseHtml(): string
    {
        self::assertSame(1, preg_match('/<html\b[^>]*>/s', (string) $this->client->getResponse()->getContent(), $m), 'Balise <html> introuvable.');

        return $m[0];
    }

    public function testUnePageConnecteePorteLIdentifiantNumeriqueEtRienDAutre(): void
    {
        $user = $this->utilisateur();
        $this->client->loginUser($user);
        $this->client->request('GET', '/onboarding');
        self::assertResponseIsSuccessful();

        $html = $this->baliseHtml();
        self::assertStringContainsString(\sprintf('data-jsb-utilisateur="%d"', $user->getId()), $html);
        self::assertStringNotContainsString(self::EMAIL, $html);
        self::assertStringNotContainsString('PHPUnit Stockage', $html);
        self::assertStringNotContainsString('data-jsb-deconnexion', $html);
    }

    /**
     * AUCUN CHAMP MOT DE PASSE VIVANT SUR UNE PAGE CONNECTÉE (chantier 16, signalé le 2026-10-21).
     *
     * La boîte de confirmation, incluse sur toutes les pages, portait un champ mot de passe
     * caché : le gestionnaire de mots de passe d'Edge/Chrome le remplissait à CHAQUE chargement,
     * et versait l'identifiant (l'e-mail) dans le dernier champ texte qui le précède — la
     * recherche des Tâches du tableau de bord. `autocomplete="off"` n'y peut rien. Le champ vit
     * désormais dans un <template>, cloné seulement quand une confirmation l'exige.
     */
    public function testAucunChampMotDePasseVivantSurUnePageConnectee(): void
    {
        $this->client->loginUser($this->utilisateur());
        $this->client->request('GET', '/onboarding');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $horsGabarits = (string) preg_replace('#<template\b.*?</template>#s', '', $html);
        self::assertDoesNotMatchRegularExpression('/<input\b[^>]*type="password"/i', $horsGabarits);
        // Le champ existe toujours, prêt à être cloné.
        self::assertMatchesRegularExpression('#<template\b[^>]*>(?:(?!</template>).)*type="password"#s', $html);
    }

    public function testLaDeconnexionMeneAUneConnexionQuiDemandeLeVidage(): void
    {
        $this->client->loginUser($this->utilisateur());
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/login?deconnexion=1');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = $this->baliseHtml();
        self::assertStringContainsString('data-jsb-deconnexion="1"', $html);
        self::assertStringNotContainsString('data-jsb-utilisateur', $html);
    }

    public function testUneConnexionOrdinaireNeVideRien(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-jsb-deconnexion', $this->baliseHtml());
    }

    /** La purge a lieu AVANT que Stimulus ne restaure le moindre onglet. */
    public function testLaPurgeEstChargeeAvantStimulus(): void
    {
        $app = (string) file_get_contents(__DIR__ . '/../../assets/app.js');
        $purge = strpos($app, "import './stockage-de-travail.js';");
        $stimulus = strpos($app, "import './bootstrap.js';");
        self::assertNotFalse($purge, 'assets/app.js doit importer stockage-de-travail.js.');
        self::assertLessThan($stimulus, $purge);
    }
}
