<?php

namespace App\Tests\Workspace;

use App\Entity\ChargeCourtier;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels du CRUD workspace des Charges et Dépenses du courtier :
 *  - gating par rôle (RolesEnFinance::accessCharge / accessDepense, fail-closed) ;
 *  - flux complet propriétaire : création d'une charge puis d'une dépense rattachée
 *    (exerce validateWorkspaceAccess, le métrage tokens en écriture et le scoping) ;
 *  - persistance scopée entreprise. Chaque test crée ses données et les nettoie.
 */
class WorkspaceChargeDepenseCrudTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-chgdep-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-chgdep-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Charges SARL';
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

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        foreach ([
            'depense_courtier', 'charge_courtier',
            'roles_en_finance', 'roles_en_marketing', 'roles_en_production',
            'roles_en_sinistre', 'roles_en_administration',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
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
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(array $accessCharge = [], array $accessDepense = []): array
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

        if ($accessCharge !== [] || $accessDepense !== []) {
            $role = new RolesEnFinance();
            $role->setNom('Rôle charges/dépenses');
            $role->setAccessCharge($accessCharge);
            $role->setAccessDepense($accessDepense);
            $role->setEntreprise($entreprise);
            $guestInvite->addRolesEnFinance($role);
            $em->persist($role);
        }

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    public function testGatingChargesEtDepensesFailClosed(): void
    {
        // Invité : Lecture sur les Charges uniquement — les Dépenses restent fermées.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed([Invite::ACCESS_LECTURE], []);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', sprintf('/admin/chargecourtier/index/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent(), 'La Lecture sur les Charges doit ouvrir la rubrique.');

        $this->client->request('GET', sprintf('/admin/depensecourtier/index/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent(), 'Sans droit Dépenses, la rubrique doit être refusée (fail-closed).');

        // Lecture seule : le formulaire de création (niveau Écriture) est refusé.
        $this->client->request('GET', sprintf('/admin/chargecourtier/api/get-form?idEntreprise=%d&idInvite=%d', $e->getId(), $guest->getId()));
        $this->assertStringContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent(), "Sans droit d'Écriture, la création de charge doit être refusée.");
    }

    public function testProprietaireCreeChargePuisDepense(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // 1) Création d'une charge OHADA (classe 62).
        $this->client->request('POST', '/admin/chargecourtier/api/submit', [
            'idEntreprise' => $e->getId(),
            'idInvite'     => $owner->getId(),
            'code'         => 'loyer',
            'libelle'      => 'Loyer du bureau',
            'compteOhada'  => '62',
            'comportement' => 'fixe',
            'periodicite'  => 'mensuelle',
        ]);
        $this->assertResponseIsSuccessful('La création de la charge ne doit pas échouer.');
        $this->assertStringContainsString('Enregistr', (string) $this->client->getResponse()->getContent());

        $charge = $this->em()->getRepository(ChargeCourtier::class)->findOneBy(['code' => 'LOYER']);
        $this->assertNotNull($charge, 'La charge doit être persistée (code normalisé en majuscules).');
        $this->assertSame($e->getId(), $charge->getEntreprise()->getId(), 'La charge doit être scopée à l\'entreprise du workspace.');

        // 2) Création d'une dépense payée rattachée à cette charge.
        $this->client->request('POST', '/admin/depensecourtier/api/submit', [
            'idEntreprise'  => $e->getId(),
            'idInvite'      => $owner->getId(),
            'charge'        => $charge->getId(),
            'dateDepense'   => '2032-04-05',
            'montant'       => '120.00',
            'tauxTva'       => '20.00',
            'moyenPaiement' => 'banque',
            'statut'        => 'payee',
        ]);
        $this->assertResponseIsSuccessful('La création de la dépense ne doit pas échouer.');
        $this->assertStringContainsString('Enregistr', (string) $this->client->getResponse()->getContent());

        $depense = $this->em()->getRepository(DepenseCourtier::class)->findOneBy(['charge' => $charge]);
        $this->assertNotNull($depense, 'La dépense doit être persistée.');
        $this->assertSame($e->getId(), $depense->getEntreprise()->getId());
        $this->assertEqualsWithDelta(100.0, $depense->getMontantHtFloat(), 0.01, 'HT = TTC dégrevé de la TVA déductible.');
        $this->assertEqualsWithDelta(20.0, $depense->getTvaDeductibleFloat(), 0.01);
    }

    public function testMenuFiltreLesNouvellesRubriques(): void
    {
        // Invité avec Lecture Charges uniquement : « Charges » visible, « Dépenses » filtrée.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed([Invite::ACCESS_LECTURE], []);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('entity-name-param="ChargeCourtier"', $html, 'La rubrique Charges doit rester visible.');
        $this->assertStringNotContainsString('entity-name-param="DepenseCourtier"', $html, 'La rubrique Dépenses (hors périmètre) doit disparaître du menu.');
    }
}
