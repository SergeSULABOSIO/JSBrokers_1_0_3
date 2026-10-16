<?php

namespace App\Tests\Console;

use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
use App\Enum\Departement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * UNE DONNÉE SAISIE DEHORS NE S'EXÉCUTE PAS DANS LA CONSOLE.
 *
 * Le sujet d'un ticket vient d'un client, ou d'un visiteur ANONYME : un message de
 * contact de la vitrine ouvre un ticket dont le sujet est l'objet saisi. La page
 * « Nouveau feedback » le rendait en `description|raw` (console/form.html.twig) : un
 * sujet `<img src=x onerror=…>` s'exécutait dans le navigateur de l'agent Joseara qui
 * ouvrait le ticket — avec sa session de console.
 */
class EchappementConsoleTest extends WebTestCase
{
    private const ADMIN = 'phpunit-echappement-console@test.local';
    private const CONTACT_EMAIL = 'phpunit-echappement-visiteur@test.local';
    private const CHARGE = '<img src=x onerror="window.__xss=1">';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = (new Utilisateur())->setEmail(self::ADMIN)->setNom('Agent Console')->setVerified(true)->setRoles(['ROLE_ADMIN']);
        // Sans département, un agent n'atteint plus aucune rubrique (ConsoleAccessResolver).
        $admin->setDepartement(Departement::DIRECTION);
        $admin->setPassword($hasher->hashPassword($admin, 'Test1234!'));
        $this->em()->persist($admin);
        $this->em()->flush();
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
        $conn->executeStatement('DELETE FROM crm_ticket WHERE contact_email = :e', ['e' => self::CONTACT_EMAIL]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::ADMIN]);
    }

    public function testLeSujetDUnTicketNEstPasDuHtmlDansLaConsole(): void
    {
        $ticket = (new CrmTicket())
            ->setSujet(self::CHARGE)
            ->setDescription('Message')
            ->setCanal(CrmTicket::CANAL_CONTACT)
            ->setContactNom('Visiteur')
            ->setContactEmail(self::CONTACT_EMAIL)
            ->setStatut(CrmTicket::STATUT_OUVERT);
        $this->em()->persist($ticket);
        $this->em()->flush();

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::ADMIN]));
        $this->client->request('GET', '/console/crm/tickets/' . $ticket->getId() . '/feedbacks/new');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::CHARGE, $html, 'Le sujet saisi est rendu tel quel, en HTML.');
        self::assertStringContainsString('&lt;img src=x onerror=', $html, 'Le sujet doit rester lisible, échappé.');
    }
}
