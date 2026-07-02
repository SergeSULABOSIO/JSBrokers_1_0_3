<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'entête contextuel des formulaires de rôle (bloc .form-intro :
 * pastille de l'entité + description de ce que l'utilisateur édite et de son importance),
 * rendu au-dessus du cadre du formulaire dans le volet droit de la modale.
 * Mécanisme opt-in via le paramètre `form_intro` du FormCanvasProvider : les autres
 * dialogues (ex. Client) ne doivent PAS afficher ce bloc.
 */
class RoleFormIntroTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-intro-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-intro-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit FormIntro SARL';

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

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        foreach ([
            'roles_en_finance', 'roles_en_marketing', 'roles_en_production',
            'roles_en_sinistre', 'roles_en_administration',
        ] as $table) {
            $conn->executeStatement(
                "DELETE r FROM {$table} r
                 JOIN invite i ON r.invite_id = i.id
                 JOIN entreprise e ON i.entreprise_id = e.id
                 WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
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
     * Prépare : un propriétaire, une entreprise, un invité cible du rôle.
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
        $guestInvite->setNom('Collaborateur cible');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $em->persist($guestInvite);

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    /**
     * Chaque formulaire de rôle affiche l'entête contextuel : pastille + titre du module
     * + description de l'importance des droits.
     */
    public function testRoleFormsDisplayContextIntro(): void
    {
        ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // [libellé attendu dans la description, nombre de cartes de droits du module]
        $modules = [
            'rolesenfinance'        => ['module Finance', 10],
            'rolesenmarketing'      => ['module Marketing', 3],
            'rolesenproduction'     => ['module Production', 8],
            'rolesenadministration' => ['module Administration', 3],
            'rolesensinistre'       => ['module Sinistre', 3],
        ];

        foreach ($modules as $root => [$expectedModule, $expectedCards]) {
            $this->client->request('GET', sprintf(
                '/admin/%s/api/get-form?parent_id=%d&parent_field_name=invite&idEntreprise=%d&idInvite=%d',
                $root,
                $guest->getId(),
                $e->getId(),
                $owner->getId()
            ));

            $this->assertResponseIsSuccessful(sprintf('Le formulaire « %s » doit se rendre sans erreur.', $root));
            $html = (string) $this->client->getResponse()->getContent();

            $this->assertStringContainsString('class="form-intro"', $html, sprintf('Le formulaire « %s » doit afficher l\'entête contextuel.', $root));
            $this->assertStringContainsString('form-intro-icon', $html, sprintf('L\'entête de « %s » doit contenir la pastille de l\'entité.', $root));
            $this->assertStringContainsString('<svg', $html, sprintf('La pastille de « %s » doit rendre une icône SVG côté serveur.', $root));
            $this->assertStringContainsString($expectedModule, $html, sprintf('La description de « %s » doit nommer le module concerné.', $root));
            // L'apostrophe de « n'accordez » est échappée en HTML : on teste le fragment qui la suit.
            $this->assertStringContainsString('accordez que le nécessaire', $html, sprintf('La description de « %s » doit rappeler la prudence d\'attribution.', $root));
            // Chaque carte de droits porte sa mini-pastille (field_icons du canvas) :
            // exactement une icône par groupe de cases à cocher du module.
            $this->assertSame(
                $expectedCards,
                substr_count($html, 'dlg-field-card-icon'),
                sprintf('Chaque carte de droits de « %s » doit porter sa propre icône d\'entité.', $root)
            );
            // Les puces de contexte rappellent les informations pré-remplies dont les
            // champs sont masqués : ici le collaborateur cible du rôle (parent_id).
            $this->assertStringContainsString('form-intro-facts', $html, sprintf('« %s » doit rappeler les informations pré-remplies masquées.', $root));
            $this->assertStringContainsString('Collaborateur concerné', $html, sprintf('« %s » doit libeller la puce du collaborateur cible.', $root));
            $this->assertStringContainsString('Collaborateur cible', $html, sprintf('« %s » doit afficher le nom du collaborateur pour qui le rôle est édité.', $root));
        }
    }

    /**
     * ÉDITION : le contexte parent est rappelé dans les puces même quand le champ
     * parent n'est pas dans le layout (dérivé des associations ManyToOne de l'entité
     * par renderFormCanvas), et sans doublon avec les faits des champs masqués.
     */
    public function testEditFormRecallsParentContext(): void
    {
        ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e] = $this->seed();

        $role = new \App\Entity\RolesEnProduction();
        $role->setNom('Rôle test contexte');
        $role->setAccessClient([\App\Entity\Invite::ACCESS_LECTURE]);
        $role->setEntreprise($e);
        $guest->addRolesEnProduction($role);
        $this->em()->persist($role);
        $this->em()->flush();

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', sprintf(
            '/admin/rolesenproduction/api/get-form/%d?idEntreprise=%d&idInvite=%d',
            $role->getId(),
            $e->getId(),
            $owner->getId()
        ));

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('form-intro-facts', $html, 'En édition, les puces de contexte doivent être présentes.');
        $this->assertStringContainsString('Collaborateur cible', $html, 'Le collaborateur parent du rôle doit être rappelé en édition.');
        $this->assertSame(
            1,
            substr_count($html, 'Collaborateur concerné'),
            'Le fait « collaborateur » ne doit apparaître qu\'une fois (dédoublonnage champ masqué / parent auto).'
        );
    }

    /**
     * Généralisation : les dialogues des autres entités (ex. Client, Assureur) portent
     * eux aussi l'entête contextuel désormais fourni par leur FormCanvasProvider.
     */
    public function testOtherEntityFormsHaveIntroToo(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        foreach (['client', 'assureur'] as $root) {
            $this->client->request('GET', sprintf(
                '/admin/%s/api/get-form?idEntreprise=%d&idInvite=%d',
                $root,
                $e->getId(),
                $owner->getId()
            ));

            $this->assertResponseIsSuccessful(sprintf('Le formulaire « %s » doit se rendre sans erreur.', $root));
            $html = (string) $this->client->getResponse()->getContent();
            $this->assertStringContainsString('class="form-intro"', $html, sprintf('Le dialogue « %s » doit afficher l\'entête contextuel généralisé.', $root));
            $this->assertStringContainsString('form-intro-icon', $html, sprintf('L\'entête de « %s » doit contenir la pastille de l\'entité.', $root));
        }
    }
}
