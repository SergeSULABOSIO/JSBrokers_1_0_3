<?php

namespace App\Tests\Console;

use App\Entity\Crm\CrmTicket;
use App\Entity\Crm\CrmTicketFeedback;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tickets de support : création automatique depuis le formulaire de contact de la
 * vitrine (en plus des e-mails) et fil de feedbacks (notes internes) sur un ticket
 * non clos, avec traçabilité auteur/date et formulaire au pattern Console.
 */
class SupportTicketFeedbackTest extends WebTestCase
{
    private const ADMIN = 'phpunit-support-admin@test.local';
    private const CONTACT_EMAIL = 'phpunit-visiteur-contact@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $admin = (new Utilisateur())->setEmail(self::ADMIN)->setNom('Agent Support')->setVerified(true)->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $em->persist($admin);
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
        // Les feedbacks partent en cascade avec leurs tickets ; on retire d'abord
        // les tickets de test (anonymes du contact + ceux rattachés à l'agent).
        $conn->executeStatement("DELETE FROM crm_ticket WHERE contact_email = :e", ['e' => self::CONTACT_EMAIL]);
        $conn->executeStatement(
            "DELETE FROM crm_ticket WHERE client_id IN (SELECT id FROM utilisateur WHERE email = :e)
             OR agent_id IN (SELECT id FROM utilisateur WHERE email = :e)",
            ['e' => self::ADMIN],
        );
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::ADMIN]);
        $conn->executeStatement('DELETE FROM plateforme_parametres');
    }

    private function admin(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::ADMIN]);
    }

    private function ticketRepo(): CrmTicketRepository
    {
        return static::getContainer()->get(CrmTicketRepository::class);
    }

    /** Crée un ticket de test (sans client : cas d'un message de contact). */
    private function nouveauTicket(string $statut = CrmTicket::STATUT_OUVERT): CrmTicket
    {
        $ticket = (new CrmTicket())
            ->setSujet('Sujet de test')
            ->setDescription('Message de test')
            ->setCanal(CrmTicket::CANAL_CONTACT)
            ->setContactNom('Visiteur Test')
            ->setContactEmail(self::CONTACT_EMAIL)
            ->setStatut($statut);
        $this->em()->persist($ticket);
        $this->em()->flush();

        return $ticket;
    }

    public function testContactMessageCreatesTicketAndStillSendsEmails(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form.contact-form')->form();
        $form['demande_contact[name]'] = 'Visiteur Test';
        $form['demande_contact[email]'] = self::CONTACT_EMAIL;
        $form['demande_contact[objet]'] = 'Question';
        $form['demande_contact[message]'] = 'Bonjour, j\'ai une question sur vos services.';
        $this->client->submit($form);
        $this->assertResponseRedirects();

        // Non-régression : les deux e-mails (équipe + accusé visiteur) sont toujours envoyés.
        $this->assertQueuedEmailCount(2);

        // Et surtout : un ticket est créé dans la console, exploitable par les agents.
        $ticket = $this->em()->getRepository(CrmTicket::class)->findOneBy(['contactEmail' => self::CONTACT_EMAIL]);
        $this->assertNotNull($ticket, 'Un ticket doit être créé à partir du message de contact.');
        $this->assertSame(CrmTicket::CANAL_CONTACT, $ticket->getCanal());
        $this->assertNull($ticket->getClient(), 'Le visiteur de la vitrine est anonyme : pas de client.');
        $this->assertSame('Visiteur Test', $ticket->getContactNom());
        $this->assertSame('Question', $ticket->getSujet());
        $this->assertNotNull($ticket->getSlaDueAt(), 'Le SLA doit être calculé à la création.');
    }

    public function testAddFeedbackOnOpenTicketTracksAuthorAndDate(): void
    {
        $ticket = $this->nouveauTicket();
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/console/crm/tickets/' . $ticket->getId() . '/feedbacks/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Ajouter le feedback')->form();
        $form['crm_ticket_feedback[contenu]'] = 'J\'ai contacté le client, en attente de retour.';
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/crm/tickets/' . $ticket->getId() . '#feedbacks');

        $this->em()->clear();
        $feedbacks = $this->em()->getRepository(CrmTicketFeedback::class)->findBy(['ticket' => $ticket->getId()]);
        $this->assertCount(1, $feedbacks);
        $this->assertSame('J\'ai contacté le client, en attente de retour.', $feedbacks[0]->getContenu());
        $this->assertSame(self::ADMIN, $feedbacks[0]->getAuteur()->getEmail(), 'L\'auteur doit être le collaborateur connecté.');
        $this->assertNotNull($feedbacks[0]->getCreatedAt(), 'La date du feedback doit être horodatée.');

        // Le feedback est visible sur la fiche du ticket.
        $crawler = $this->client->request('GET', '/console/crm/tickets/' . $ticket->getId());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('J\'ai contacté le client', $crawler->html());
    }

    public function testFeedbackBlockedOnClosedTicket(): void
    {
        $ticket = $this->nouveauTicket(CrmTicket::STATUT_CLOS);
        $this->client->loginUser($this->admin());

        // La fiche d'un ticket clos n'affiche pas le bouton « Ajouter un feedback ».
        $crawler = $this->client->request('GET', '/console/crm/tickets/' . $ticket->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            0,
            $crawler->filter('a[href$="/feedbacks/new"]')->count(),
            'Un ticket clos ne doit pas proposer l\'ajout de feedback.',
        );

        // L'accès direct au formulaire est refusé (redirection vers la fiche).
        $this->client->request('GET', '/console/crm/tickets/' . $ticket->getId() . '/feedbacks/new');
        $this->assertResponseRedirects('/console/crm/tickets/' . $ticket->getId() . '#feedbacks');

        $this->em()->clear();
        $this->assertCount(
            0,
            $this->em()->getRepository(CrmTicketFeedback::class)->findBy(['ticket' => $ticket->getId()]),
            'Aucun feedback ne doit être créé sur un ticket clos.',
        );
    }

    public function testFeedbackEditFollowsConsolePatternAndKeepsAuthor(): void
    {
        $ticket = $this->nouveauTicket();
        $admin = $this->admin();
        $feedback = (new CrmTicketFeedback())->setTicket($ticket)->setAuteur($admin)->setContenu('Avant');
        $this->em()->persist($feedback);
        $this->em()->flush();
        $id = $feedback->getId();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/console/crm/tickets/feedbacks/' . $id . '/edit');
        $this->assertResponseIsSuccessful();

        // Pattern Console (comme Coupon) : carte + fieldset à légende + barre sticky.
        $this->assertGreaterThanOrEqual(
            1,
            $crawler->filter('.cs-form-card .cs-fieldset .cs-legend')->count(),
            'Le formulaire d\'édition du feedback doit suivre le pattern Console.',
        );
        $this->assertSame(
            1,
            $crawler->filter('.cs-form-actions button[type="submit"]')->count(),
            'Le formulaire doit avoir la barre d\'actions sticky du shell.',
        );

        $form = $crawler->filter('form')->form();
        $form['crm_ticket_feedback[contenu]'] = 'Après';
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/crm/tickets/' . $ticket->getId() . '#feedbacks');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(CrmTicketFeedback::class)->find($id);
        $this->assertSame('Après', $reloaded->getContenu());
        $this->assertSame(self::ADMIN, $reloaded->getAuteur()->getEmail(), 'L\'auteur d\'origine doit être conservé à l\'édition.');
    }
}
