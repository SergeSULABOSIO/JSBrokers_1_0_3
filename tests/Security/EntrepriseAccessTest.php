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
 * Test de régression du contrôle d'accès sur la gestion d'une entreprise.
 *
 * Vérifie les deux failles corrigées :
 *  - IDOR : seul le propriétaire peut éditer / supprimer / générer le PDF ;
 *  - CSRF : la suppression exige un jeton valide.
 *
 * Le test crée propriétaire, attaquant et une entreprise, puis nettoie tout.
 */
class EntrepriseAccessTest extends WebTestCase
{
    private const OWNER_EMAIL    = 'phpunit-owner@test.local';
    private const ATTACKER_EMAIL = 'phpunit-attacker@test.local';
    private const PASSWORD        = 'Test1234!';

    private KernelBrowser $client;
    private int $entrepriseId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = $this->em();

        $users = [];
        foreach ([self::OWNER_EMAIL, self::ATTACKER_EMAIL] as $email) {
            $user = new Utilisateur();
            $user->setEmail($email);
            $user->setNom('PHPUnit');
            $user->setVerified(true);
            $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
            $em->persist($user);
            $users[$email] = $user;
        }

        $entreprise = new Entreprise();
        $entreprise->setNom('PHPUnit SARL');
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($users[self::OWNER_EMAIL]);
        $em->persist($entreprise);

        // Invité « propriétaire » : en production chaque utilisateur en possède un ;
        // la liste s'appuie dessus (invite.id) pour construire les liens.
        $invite = new Invite();
        $invite->setNom('Administrateur');
        $invite->setUtilisateur($users[self::OWNER_EMAIL]);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
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
        // Nettoyage SQL natif, dans l'ordre des contraintes de clés étrangères
        // (invite → entreprise → utilisateur). Fiable quel que soit l'état du
        // cycle de vie ORM / des collections inverses.
        $conn   = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::ATTACKER_EMAIL];

        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom = :nom",
            ['emails' => $emails, 'nom' => 'PHPUnit SARL'],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom = :nom",
            ['nom' => 'PHPUnit SARL']
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testCreatePageRenders(): void
    {
        // Régression : la page de création ne doit plus planter (EntrepriseType réaligné).
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', '/admin/entreprise/create');

        $this->assertResponseIsSuccessful();
    }

    public function testOwnerReachesEditPage(): void
    {
        // Régression : l'édition rend désormais sans 500, même sans workspace actif.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', '/admin/entreprise/' . $this->entrepriseId);

        $this->assertResponseIsSuccessful();
    }

    public function testAttackerCannotReachEditPage(): void
    {
        // IDOR : un autre utilisateur authentifié ne doit pas pouvoir éditer cette entreprise.
        $this->client->loginUser($this->user(self::ATTACKER_EMAIL));
        $this->client->request('GET', '/admin/entreprise/' . $this->entrepriseId);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteWithoutCsrfTokenIsRejected(): void
    {
        // CSRF : même le propriétaire est refusé sans jeton valide.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('POST', '/admin/entreprise/' . $this->entrepriseId, ['_method' => 'DELETE']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanDeleteViaRenderedForm(): void
    {
        // Parcours nominal : le formulaire rendu porte un jeton CSRF valide → suppression OK.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $crawler = $this->client->request('GET', '/admin/entreprise');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[data-controller="confirm-action"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/entreprise');
        $this->assertNull(
            $this->em()->getRepository(Entreprise::class)->find($this->entrepriseId),
            "L'entreprise aurait dû être supprimée."
        );
    }
}
