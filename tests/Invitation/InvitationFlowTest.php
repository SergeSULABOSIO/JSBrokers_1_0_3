<?php

namespace App\Tests\Invitation;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\InvitationLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels du parcours d'invitation refactorisé.
 *
 * Couverture :
 *  - InvitationLinker : une invitation « en attente » (sans compte) est rattachée au
 *    compte dès qu'il existe (email vidé, utilisateur lié) ;
 *  - liste d'entreprises : un invité (qui ne possède aucune entreprise) voit bien
 *    l'entreprise à laquelle il est invité (EntrepriseRepository corrigé) ;
 *  - inscription : créer un compte avec l'email invité rattache l'invitation en attente,
 *    de sorte que l'entreprise apparaît dès la première connexion.
 *
 * Le test crée ses propres données et les supprime entièrement ensuite.
 */
class InvitationFlowTest extends WebTestCase
{
    private const OWNER_EMAIL   = 'phpunit-inv-owner@test.local';
    private const GUEST_EMAIL   = 'phpunit-inv-guest@test.local';
    private const PENDING_EMAIL = 'phpunit-inv-pending@test.local';
    private const PASSWORD      = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Invitation SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
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

    private function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function makeUser(string $email, bool $verified = true): Utilisateur
    {
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit');
        $user->setVerified($verified);
        $user->setPassword($this->hasher()->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function makeEntreprise(Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        // Invité propriétaire (chaque utilisateur en possède un en production).
        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($owner);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $this->em()->persist($ownerInvite);

        return $entreprise;
    }

    private function user(string $email): ?Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /**
     * Valeur BRUTE de la colonne invite.email. On ne peut pas se fier à Invite::getEmail()
     * qui retombe volontairement sur l'email du compte rattaché quand la colonne est NULL.
     */
    private function rawInviteEmail(int $id): ?string
    {
        return $this->em()->getConnection()->fetchOne(
            'SELECT email FROM invite WHERE id = :id',
            ['id' => $id]
        ) ?: null;
    }

    private function cleanUp(): void
    {
        $conn   = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::PENDING_EMAIL];

        // invite → entreprise → utilisateur (ordre des clés étrangères). On couvre aussi
        // les invitations en attente (email stocké, utilisateur_id NULL).
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom = :nom OR i.email IN (:emails)",
            ['emails' => $emails, 'nom' => self::ENTREPRISE_NOM],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom = :nom",
            ['nom' => self::ENTREPRISE_NOM]
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    public function testLinkerAttachesPendingInvitation(): void
    {
        $owner      = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = $this->makeEntreprise($owner);

        // Invitation EN ATTENTE : pas de compte, identifiée par l'email.
        $pending = new Invite();
        $pending->setNom('Futur collaborateur');
        $pending->setEntreprise($entreprise);
        $pending->setProprietaire(false);
        $pending->setEmail(self::PENDING_EMAIL);
        $pending->setUtilisateur(null);
        $this->em()->persist($pending);

        // La personne crée (plus tard) son compte avec le même email.
        $newcomer = $this->makeUser(self::PENDING_EMAIL);
        $this->em()->flush();
        $pendingId = $pending->getId();

        $linked = static::getContainer()->get(InvitationLinker::class)->linkPendingInvitations($newcomer);

        $this->assertSame(1, $linked, "Une invitation en attente aurait dû être rattachée.");

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Invite::class)->find($pendingId);
        $this->assertNotNull($reloaded->getUtilisateur(), "L'invitation devrait désormais être liée à un compte.");
        $this->assertSame(self::PENDING_EMAIL, $reloaded->getUtilisateur()->getEmail());
        $this->assertNull($this->rawInviteEmail($pendingId), "La colonne email doit être vidée après rattachement.");
        $this->assertFalse($reloaded->isEnAttente(), "L'invitation ne doit plus être en attente.");
    }

    public function testGuestSeesInvitedEntrepriseInList(): void
    {
        $owner      = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = $this->makeEntreprise($owner);

        // Invité ACTIF : ne possède aucune entreprise, mais est rattaché à celle-ci.
        $guest = $this->makeUser(self::GUEST_EMAIL);
        $guestInvite = new Invite();
        $guestInvite->setNom('Collaborateur invité');
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $guestInvite->setUtilisateur($guest);
        $this->em()->persist($guestInvite);
        $this->em()->flush();

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request('GET', '/admin/entreprise');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            self::ENTREPRISE_NOM,
            (string) $this->client->getResponse()->getContent(),
            "L'invité doit voir l'entreprise à laquelle il est invité dans sa liste."
        );
    }

    public function testRegistrationLinksPendingInvitationEndToEnd(): void
    {
        $owner      = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = $this->makeEntreprise($owner);

        $pending = new Invite();
        $pending->setNom('Futur collaborateur');
        $pending->setEntreprise($entreprise);
        $pending->setProprietaire(false);
        $pending->setEmail(self::PENDING_EMAIL);
        $pending->setUtilisateur(null);
        $this->em()->persist($pending);
        $this->em()->flush();
        $pendingId = $pending->getId();

        // La personne invitée crée son compte via le formulaire d'inscription.
        $crawler = $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer un compte')->form([
            'registration_form[nom]'           => 'Nouveau Collaborateur',
            'registration_form[email]'         => self::PENDING_EMAIL,
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]'    => true,
        ]);
        $this->client->submit($form);

        // Inscription réussie → redirection vers la connexion (pas de login auto).
        $this->assertResponseRedirects('/login');

        // L'invitation en attente est désormais rattachée au nouveau compte.
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Invite::class)->find($pendingId);
        $this->assertNotNull($reloaded->getUtilisateur(), "L'inscription doit rattacher l'invitation en attente.");
        $this->assertSame(self::PENDING_EMAIL, $reloaded->getUtilisateur()->getEmail());
        $this->assertNull($this->rawInviteEmail($pendingId));
    }
}
