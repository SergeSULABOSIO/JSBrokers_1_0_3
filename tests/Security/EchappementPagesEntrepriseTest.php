<?php

namespace App\Tests\Security;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LES PAGES DE CRÉATION ET D'ÉDITION D'UN CABINET N'EXÉCUTENT PAS UN NOM SAISI.
 *
 * Leur phrase d'accueil et le toast des paramètres sont des traductions BALISÉES, rendues
 * en `|raw` — et `trans` n'échappe pas ses paramètres : le nom de l'utilisateur et celui
 * du cabinet y entraient tels quels.
 */
class EchappementPagesEntrepriseTest extends WebTestCase
{
    private const EMAIL = 'phpunit-echappement-cabinet@test.local';
    private const CHARGE = '<img src=x onerror=alert(1)>';
    private const NOM_CABINET = 'Cabinet <img src=x onerror=alert(1)>';

    private KernelBrowser $client;
    private int $entrepriseId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Proprio ' . self::CHARGE)->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Test1234!'));
        $em->persist($user);

        $entreprise = (new Entreprise())->setNom(self::NOM_CABINET)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setUtilisateur($user)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);
        $em->flush();

        $this->entrepriseId = $entreprise->getId();
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
            'DELETE i FROM invite i LEFT JOIN utilisateur u ON i.utilisateur_id = u.id WHERE u.email = :e',
            ['e' => self::EMAIL],
        );
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::NOM_CABINET]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::EMAIL]);
    }

    private function connecter(): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]));
    }

    public function testLaPageDEditionEchappeLesNoms(): void
    {
        $this->connecter();
        $this->client->request('GET', '/admin/entreprise/' . $this->entrepriseId);
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::CHARGE, $html, 'Un nom saisi sort en HTML sur la page d\'édition.');
        self::assertStringContainsString('Proprio &lt;img src=x', $html);
        self::assertStringContainsString('Cabinet &lt;img src=x', $html);
    }

    public function testLaPageDeCreationEchappeLeNomDeLUtilisateur(): void
    {
        $this->connecter();
        $this->client->request('GET', '/admin/entreprise/create');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::CHARGE, $html, 'Le nom saisi sort en HTML sur la page de création.');
        self::assertStringContainsString('Proprio &lt;img src=x', $html);
    }
}
