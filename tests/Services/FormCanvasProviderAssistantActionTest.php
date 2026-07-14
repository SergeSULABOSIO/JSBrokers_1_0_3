<?php

namespace App\Tests\Services;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\Canvas\FormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Injection centrale de l'action « Ajouter au chat avec l'assistant IA » dans
 * les canvas de formulaire (FormCanvasProvider, collecteur) : présente pour un
 * compte payant dont l'invité a le module IA, absente sinon (non payant, sans
 * module, sans utilisateur connecté) — gating cosmétique, fail-closed.
 */
class FormCanvasProviderAssistantActionTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-canvasia-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-canvasia-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CanvasIA SARL';
    private const ACTION_LABEL = "Ajouter au chat avec l'assistant IA";

    protected function setUp(): void
    {
        static::bootKernel();
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
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];
        $noms = [self::ENTREPRISE_NOM];

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
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)",
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING, 'noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom IN (:noms)",
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /**
     * @return array{entreprise: Entreprise, ownerUser: Utilisateur, guestUser: Utilisateur}
     */
    private function seed(bool $comptePayant = true, bool $guestAvecModuleIa = false): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit CanvasIA');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword('irrelevant');
        if ($comptePayant) {
            $ownerUser->setPaidTokens(1_000_000);
        }
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-CVIA');
        $entreprise->setAdresse('1 rue du Canvas');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-CVIA');
        $entreprise->setIdnat('IDNAT-CVIA');
        $entreprise->setNumimpot('IMP-CVIA');
        $entreprise->setUtilisateur($ownerUser);
        $em->persist($entreprise);
        $ownerUser->setConnectedTo($entreprise);

        $owner = new Invite();
        $owner->setNom('Propriétaire');
        $owner->setUtilisateur($ownerUser);
        $owner->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $guestUser = new Utilisateur();
        $guestUser->setEmail(self::GUEST_EMAIL);
        $guestUser->setNom('PHPUnit CanvasIA Invité');
        $guestUser->setVerified(true);
        $guestUser->setPassword('irrelevant');
        $guestUser->setConnectedTo($entreprise);
        $em->persist($guestUser);

        $guest = new Invite();
        $guest->setNom('Invité');
        $guest->setUtilisateur($guestUser);
        $guest->setEntreprise($entreprise);
        $guest->setProprietaire(false);
        $em->persist($guest);

        if ($guestAvecModuleIa) {
            $roleIa = new \App\Entity\RolesEnAdministration();
            $roleIa->setNom('Rôle module IA');
            $roleIa->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
            $roleIa->setEntreprise($entreprise);
            $guest->addRolesEnAdministration($roleIa);
            $em->persist($roleIa);
        }

        $em->flush();

        return ['entreprise' => $entreprise, 'ownerUser' => $ownerUser, 'guestUser' => $guestUser];
    }

    /** Simule un utilisateur connecté pour les services hors requête HTTP. */
    private function loginAs(Utilisateur $user): void
    {
        static::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function actionLabels(int $idEntreprise): array
    {
        $canvas = static::getContainer()->get(FormCanvasProvider::class)
            ->getCanvas(new Client(), $idEntreprise);

        return array_column($canvas['parametres']['attribute_actions'] ?? [], 'label');
    }

    public function testActionInjecteePourProprietaireComptePayant(): void
    {
        ['entreprise' => $e, 'ownerUser' => $ownerUser] = $this->seed();
        $this->loginAs($ownerUser);

        $canvas = static::getContainer()->get(FormCanvasProvider::class)
            ->getCanvas(new Client(), $e->getId());
        $actions = $canvas['parametres']['attribute_actions'] ?? [];
        $action = null;
        foreach ($actions as $candidate) {
            if (($candidate['label'] ?? null) === self::ACTION_LABEL) {
                $action = $candidate;
            }
        }

        $this->assertNotNull($action, "L'action assistant doit être injectée pour un compte payant avec module IA.");
        $this->assertSame('ui:assistant.add-to-chat', $action['event']);
        $this->assertTrue($action['multi'], "L'action doit accepter la multi-sélection.");
        $this->assertSame('assistant-ia', $action['icon']);
    }

    public function testActionAbsenteSansComptePayant(): void
    {
        ['entreprise' => $e, 'ownerUser' => $ownerUser] = $this->seed(comptePayant: false);
        $this->loginAs($ownerUser);

        $this->assertNotContains(self::ACTION_LABEL, $this->actionLabels($e->getId()));
    }

    public function testActionAbsentePourInviteSansModuleIa(): void
    {
        ['entreprise' => $e, 'guestUser' => $guestUser] = $this->seed();
        $this->loginAs($guestUser);

        $this->assertNotContains(self::ACTION_LABEL, $this->actionLabels($e->getId()));
    }

    public function testActionPresentePourInviteAvecModuleIa(): void
    {
        ['entreprise' => $e, 'guestUser' => $guestUser] = $this->seed(guestAvecModuleIa: true);
        $this->loginAs($guestUser);

        $this->assertContains(self::ACTION_LABEL, $this->actionLabels($e->getId()));
    }

    public function testActionAbsenteSansUtilisateurConnecte(): void
    {
        ['entreprise' => $e] = $this->seed();
        // Aucun token (CLI, worker) : fail-closed, pas d'action.
        $this->assertNotContains(self::ACTION_LABEL, $this->actionLabels($e->getId()));
    }
}
