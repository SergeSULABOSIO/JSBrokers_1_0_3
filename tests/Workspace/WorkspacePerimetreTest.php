<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de bout en bout : les rôles d'un invité conditionnent réellement
 * l'accès aux rubriques de l'espace de travail (blocage serveur au point de passage
 * générique de lecture). Marqueur de refus : la classe CSS `jsb-access-denied` du
 * panneau « Accès restreint ». Chaque test crée ses données et les nettoie ensuite.
 */
class WorkspacePerimetreTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-wsp-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-wsp-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Périmètre SARL';
    private const DENIED_MARKER = 'jsb-access-denied';

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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];

        // Détacher l'entreprise active des utilisateurs (FK utilisateur.connected_to_id)
        // AVANT de supprimer l'entreprise, sinon la contrainte bloque la suppression.
        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        // rôles → invités → entreprise → utilisateurs (ordre des clés étrangères).
        $conn->executeStatement(
            "DELETE r FROM roles_en_production r
             JOIN invite i ON r.invite_id = i.id
             JOIN entreprise e ON i.entreprise_id = e.id
             WHERE e.nom = :nom",
            ['nom' => self::ENTREPRISE_NOM]
        );
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom = :nom",
            ['emails' => $emails, 'nom' => self::ENTREPRISE_NOM],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /**
     * Prépare : un propriétaire, une entreprise, un invité restreint (Lecture sur les
     * Clients uniquement) rattaché à l'entreprise active.
     *
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = new Invite();
        $guestInvite->setNom('Collaborateur restreint');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $em->persist($guestInvite);

        // Rôle : Lecture sur les Clients uniquement. Aucun droit Finance (Taxe).
        // On renseigne les DEUX côtés de la relation (addRolesEnProduction) pour que la
        // collection en mémoire de l'invité contienne le rôle dans le même EntityManager
        // (le contrôleur relit la même instance managée via l'identity map).
        $role = new RolesEnProduction();
        $role->setNom('Rôle test');
        $role->setAccessClient([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($entreprise);
        $guestInvite->addRolesEnProduction($role);
        $em->persist($role);

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    public function testGuestReachesEntityInHisPerimetre(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', sprintf('/admin/client/index/%d/%d', $guest->getId(), $e->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            self::DENIED_MARKER,
            (string) $this->client->getResponse()->getContent(),
            "L'invité a la lecture sur les Clients : la rubrique ne doit pas être refusée."
        );
    }

    public function testGuestIsDeniedOutsideHisPerimetre(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // Aucun droit Finance → la rubrique Taxes doit afficher le panneau « Accès restreint ».
        $this->client->request('GET', sprintf('/admin/taxe/index/%d/%d', $guest->getId(), $e->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            self::DENIED_MARKER,
            (string) $this->client->getResponse()->getContent(),
            "Sans droit Finance, la rubrique Taxes doit être refusée (fail-closed)."
        );
    }

    public function testGuestIsDeniedInviteManagement(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // La gestion des invités est réservée au propriétaire / délégué.
        $this->client->request('GET', sprintf('/admin/invite/index/%d/%d', $guest->getId(), $e->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            self::DENIED_MARKER,
            (string) $this->client->getResponse()->getContent(),
            "Un invité ordinaire ne doit pas accéder à la gestion des invités."
        );
    }

    public function testOwnerCanOpenRoleCreationForm(): void
    {
        ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Ouverture du formulaire de création d'un rôle Finance pour l'invité cible.
        // Le champ « invite » figure dans le layout du provider ET était (à tort) injecté
        // une seconde fois → « Field invite has already been rendered ». Non-régression.
        $this->client->request('GET', sprintf(
            '/admin/rolesenfinance/api/get-form?parent_id=%d&parent_field_name=invite&idEntreprise=%d&idInvite=%d',
            $guest->getId(),
            $e->getId(),
            $owner->getId()
        ));

        $this->assertResponseIsSuccessful('Le propriétaire doit pouvoir ouvrir le formulaire de rôle (pas de 500).');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString(self::DENIED_MARKER, $html);
        $this->assertStringContainsString('accessMonnaie', $html, 'Le formulaire de rôle Finance doit être rendu.');
    }

    public function testGuestCannotOpenRoleCreationForm(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // Un invité ordinaire (non gestionnaire) ne peut pas ouvrir un formulaire de rôle.
        $this->client->request('GET', sprintf(
            '/admin/rolesenfinance/api/get-form?parent_id=%d&parent_field_name=invite&idEntreprise=%d&idInvite=%d',
            $guest->getId(),
            $e->getId(),
            $guest->getId()
        ));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            self::DENIED_MARKER,
            (string) $this->client->getResponse()->getContent(),
            "L'attribution de rôle est réservée au propriétaire / délégué."
        );
    }

    public function testOwnerReachesEverything(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        foreach (['taxe', 'client', 'invite'] as $root) {
            $this->client->request('GET', sprintf('/admin/%s/index/%d/%d', $root, $owner->getId(), $e->getId()));
            $this->assertResponseIsSuccessful();
            $this->assertStringNotContainsString(
                self::DENIED_MARKER,
                (string) $this->client->getResponse()->getContent(),
                sprintf('Le propriétaire doit accéder à « %s » (bypass total).', $root)
            );
        }
    }
}
