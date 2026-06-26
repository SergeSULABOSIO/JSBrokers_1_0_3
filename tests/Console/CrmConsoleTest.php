<?php

namespace App\Tests\Console;

use App\Crm\CrmHealthScoreService;
use App\Crm\CrmPipelineService;
use App\Entity\Crm\CrmProfil;
use App\Entity\Entreprise;
use App\Entity\TokenConsumption;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Module CRM (Console) : contrôle d'accès, rendu des pages, synchronisation
 * automatique du profil client (pipeline + score de santé) et actions
 * commerciales. Couvre aussi la logique pure des services (dérivation d'étape,
 * seuils de couleur du score). Aucune régression : tables crm_* additives.
 */
class CrmConsoleTest extends WebTestCase
{
    private const ADMIN  = 'phpunit-crm-admin@test.local';
    private const CLIENT = 'phpunit-crm-client@test.local';
    private const PLAIN  = 'phpunit-crm-plain@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $admin = (new Utilisateur())->setEmail(self::ADMIN)->setNom('Agent CRM')->setVerified(true)->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $em->persist($admin);

        $plain = (new Utilisateur())->setEmail(self::PLAIN)->setNom('Utilisateur Lambda')->setVerified(true);
        $plain->setPassword($hasher->hashPassword($plain, self::PASSWORD));
        $em->persist($plain);

        // Client payant : 1 entreprise, 1 achat, consommation récente, connecté récemment.
        $cli = (new Utilisateur())->setEmail(self::CLIENT)->setNom('Client Test')->setVerified(true);
        $cli->setPassword($hasher->hashPassword($cli, self::PASSWORD));
        $cli->setPaidTokens(5000);
        $cli->registerLogin(new \DateTimeImmutable());
        $em->persist($cli);

        $ent = (new Entreprise())
            ->setNom('Courtage Test')->setLicence('LIC-1')->setAdresse('1 rue Test')
            ->setTelephone('0000')->setRccm('RCCM-1')->setIdnat('IDN-1')->setNumimpot('IMP-1')
            ->setUtilisateur($cli);
        $em->persist($ent);

        $achat = (new TokenPurchase())->setUtilisateur($cli)->setPack('intermediaire')
            ->setTokens(10000)->setMontantUsd(9.0)->setReference('REF-CRM-1');
        $em->persist($achat);

        $conso = (new TokenConsumption())
            ->setEntreprise($ent)->setProprietaire($cli)->setActeur($cli)
            ->setEntiteNom('Cotation')->setSens(TokenConsumption::SENS_ENTREE)
            ->setNombre(1)->setPoidsUnitaire(50)->setPoidsTotal(50);
        $em->persist($conso);

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
        $emails = "(SELECT id FROM utilisateur WHERE email IN ('" . self::ADMIN . "','" . self::CLIENT . "','" . self::PLAIN . "'))";
        // L'entreprise référence l'utilisateur sans ON DELETE : on la retire d'abord
        // (la consommation liée est supprimée en cascade). Le reste part avec l'utilisateur.
        $conn->executeStatement("DELETE FROM entreprise WHERE utilisateur_id IN $emails");
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::CLIENT, self::PLAIN]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testRegularUserForbidden(): void
    {
        $this->client->loginUser($this->user(self::PLAIN));
        $this->client->request('GET', '/console/crm');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminReachesCrmPages(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        foreach ([
            '/console/crm',
            '/console/crm/clients',
            '/console/crm/pipeline',
            '/console/crm/entreprises',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('La page %s doit répondre 200 pour un agent.', $url));
        }
    }

    public function testClientFicheCreatesProfilAndDerivesStage(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $client = $this->user(self::CLIENT);

        $this->client->request('GET', '/console/crm/clients/' . $client->getId());
        $this->assertResponseIsSuccessful();

        // Le profil a été créé et synchronisé automatiquement à l'affichage.
        $this->em()->clear();
        $profil = $this->em()->getRepository(CrmProfil::class)->find($this->user(self::CLIENT));
        $this->assertNotNull($profil, 'Le profil CRM doit être créé automatiquement.');
        // 1 achat + activité récente → « Client actif ».
        $this->assertSame(CrmPipelineService::STAGE_ACTIF, $profil->getEtapePipeline());
        $this->assertGreaterThan(0, $profil->getScoreSante());
        $this->assertContains($profil->getScoreCouleur(), ['vert', 'jaune', 'orange', 'rouge']);
    }

    public function testForceStageViaPost(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $client = $this->user(self::CLIENT);

        $crawler = $this->client->request('GET', '/console/crm/clients/' . $client->getId());
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/etape"]')->form();
        $form['etape'] = CrmPipelineService::STAGE_QUALIFICATION;
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $profil = $this->em()->getRepository(CrmProfil::class)->find($this->user(self::CLIENT));
        $this->assertSame(CrmPipelineService::STAGE_QUALIFICATION, $profil->getEtapePipeline());
        $this->assertTrue($profil->isEtapeManuelleForcee(), 'Une étape relationnelle forcée doit être marquée comme telle.');
    }

    public function testPipelineDerivationLogic(): void
    {
        /** @var CrmPipelineService $pipe */
        $pipe = static::getContainer()->get(CrmPipelineService::class);
        $now = new \DateTimeImmutable();

        $base = [
            'nbEntreprises' => 0, 'nbInvites' => 0, 'loginCount' => 0, 'lastActivityAt' => null,
            'nbPurchases' => 0, 'totalConsumption' => 0, 'score' => 0, 'daysSinceCreation' => 1,
        ];

        $this->assertSame(CrmPipelineService::STAGE_PROSPECT, $pipe->deriveAuto($base));
        $this->assertSame(CrmPipelineService::STAGE_CONTACT, $pipe->deriveAuto(['loginCount' => 2] + $base));
        $this->assertSame(CrmPipelineService::STAGE_ESSAI, $pipe->deriveAuto(['totalConsumption' => 30, 'loginCount' => 1] + $base));
        $this->assertSame(
            CrmPipelineService::STAGE_ACTIF,
            $pipe->deriveAuto(['nbPurchases' => 1, 'lastActivityAt' => $now] + $base),
        );
        $this->assertSame(
            CrmPipelineService::STAGE_FIDELE,
            $pipe->deriveAuto(['nbPurchases' => 3, 'lastActivityAt' => $now, 'score' => 50] + $base),
        );
        // Inactivité prolongée d'un compte engagé → churn.
        $this->assertSame(
            CrmPipelineService::STAGE_CHURN,
            $pipe->deriveAuto(['nbPurchases' => 1, 'loginCount' => 5, 'lastActivityAt' => $now->modify('-60 days')] + $base),
        );
    }

    public function testHealthScoreColorThresholds(): void
    {
        /** @var CrmHealthScoreService $health */
        $health = static::getContainer()->get(CrmHealthScoreService::class);

        $this->assertSame('vert', $health->color(80));
        $this->assertSame('jaune', $health->color(60));
        $this->assertSame('orange', $health->color(30));
        $this->assertSame('rouge', $health->color(10));

        // Un client inactif sans rien consommé doit être en mauvaise santé.
        $faible = $health->compute([
            'lastActivityAt' => null, 'consumption30' => 0, 'paidTokens' => 0,
            'nbEntreprises' => 0, 'nbInvites' => 0, 'distinctEntites' => 0,
            'nbPurchases' => 0, 'lastPurchaseAt' => null, 'openTickets' => 0,
        ]);
        $this->assertLessThan(25, $faible['score']);
        $this->assertSame('rouge', $faible['couleur']);
    }
}
