<?php

namespace App\Tests\Ai;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ÉTENDUE du plan est fixée par l'utilisateur, et c'est le SERVEUR qui en tire
 * les conséquences : le client n'envoie que des clés d'étapes, l'endpoint
 * d'exécution filtre le plan qu'il a lui-même stocké, re-chiffre, et n'écrit que
 * ce qui a été retenu. Une seule validation, quelle que soit l'étendue choisie.
 */
class PlanEtendueEndpointTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetEtendue';
    private const OWNER = 'phpunit-ketetendue-owner@test.local';
    private const PASSWORD = 'Test1234!';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        foreach (['assistant_conversation', 'piste', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em()->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur} */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword($hasher->hashPassword($owner, self::PASSWORD));
        $owner->setPaidTokens(1_000_000); // l'assistant est réservé aux comptes payants.
        $em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $em->persist($inv);
        $em->flush();

        $this->client->loginUser($owner);

        return [$ent, $inv, $owner];
    }

    /**
     * Message assistant portant un plan à DEUX étapes chaînées : le client
     * (socle, étiqueté « client ») puis son opportunité, qui y renvoie.
     */
    private function seedMessageAvecPlan(Entreprise $ent, Invite $inv): AssistantMessage
    {
        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv)->setTitre('Plan');
        $this->em()->persist($conversation);

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan.')
            ->setMeta(['mutationPlan' => [
                'plan' => [
                    [
                        'op' => 'create', 'entite' => 'Client', 'ref' => 'client', 'etape' => 'Le client',
                        'fields' => ['nom' => 'ACME Étendue', 'exonere' => false],
                    ],
                    [
                        'op' => 'create', 'entite' => 'Piste', 'etape' => 'L’opportunité',
                        'fields' => [
                            'nom' => 'Affaire 2026', 'client' => '@client', 'typeAvenant' => 1,
                            'exercice' => 2026, 'descriptionDuRisque' => 'RC générale',
                        ],
                    ],
                ],
                'budget'           => ['coutEstime' => 0],
                'requiresPassword' => false,
            ]]);
        $conversation->addMessage($message);
        $this->em()->flush();

        return $message;
    }

    private function executer(Entreprise $ent, AssistantMessage $message, array $payload): array
    {
        $this->client->request(
            'POST',
            sprintf(
                '/admin/assistant-ia/api/mutation/%d/%d/%d/execute',
                $ent->getId(),
                $message->getConversation()->getId(),
                $message->getId(),
            ),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload),
        );

        return json_decode($this->client->getResponse()->getContent(), true) ?: [];
    }

    /** Étendue complète (aucune sélection transmise) : tout le plan est exécuté. */
    public function testSansSelectionLePlanEntierEstExecute(): void
    {
        [$ent, $inv] = $this->seed();
        $message = $this->seedMessageAvecPlan($ent, $inv);

        $data = $this->executer($ent, $message, []);

        $this->assertTrue($data['success'] ?? false, 'Le plan complet s’exécute en une validation.');
        $this->em()->clear();
        $client = $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'ACME Étendue']);
        $piste = $this->em()->getRepository(Piste::class)->findOneBy(['nom' => 'Affaire 2026']);
        $this->assertNotNull($client);
        $this->assertNotNull($piste, 'L’étape chaînée est exécutée dans la même validation.');
        $this->assertSame($client->getId(), $piste->getClient()?->getId());
    }

    /** L'utilisateur décoche l'étape facultative : seule l'étape socle est écrite. */
    public function testEtapeDecocheeNestPasExecutee(): void
    {
        [$ent, $inv] = $this->seed();
        $message = $this->seedMessageAvecPlan($ent, $inv);

        $data = $this->executer($ent, $message, ['etapes' => ['le-client']]);

        $this->assertTrue($data['success'] ?? false);
        $this->assertCount(1, $data['journal'], 'Une seule opération journalisée.');

        $this->em()->clear();
        $this->assertNotNull($this->em()->getRepository(Client::class)->findOneBy(['nom' => 'ACME Étendue']));
        $this->assertNull(
            $this->em()->getRepository(Piste::class)->findOneBy(['nom' => 'Affaire 2026']),
            'L’étape décochée n’a rien écrit.',
        );
    }

    /** Une clé d'étape inconnue ne fait rien exécuter d'inattendu : seul le socle subsiste. */
    public function testCleDEtapeInconnueNElargitPasLEtendue(): void
    {
        [$ent, $inv] = $this->seed();
        $message = $this->seedMessageAvecPlan($ent, $inv);

        $data = $this->executer($ent, $message, ['etapes' => ['etape-fantaisiste']]);

        $this->assertTrue($data['success'] ?? false);
        $this->em()->clear();
        $this->assertNotNull(
            $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'ACME Étendue']),
            'L’étape socle reste toujours exécutée.',
        );
        $this->assertNull($this->em()->getRepository(Piste::class)->findOneBy(['nom' => 'Affaire 2026']));
    }

    /** Anti-rejeu : le plan exécuté (quelle que soit l'étendue) ne se rejoue pas. */
    public function testPlanExecuteNeSeRejouePas(): void
    {
        [$ent, $inv] = $this->seed();
        $message = $this->seedMessageAvecPlan($ent, $inv);

        $this->executer($ent, $message, ['etapes' => ['le-client']]);
        $this->executer($ent, $message, []);

        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->em()->clear();
        $this->assertNull(
            $this->em()->getRepository(Piste::class)->findOneBy(['nom' => 'Affaire 2026']),
            'Le plan déjà exécuté ne se rejoue pas, même avec une étendue plus large.',
        );
    }
}
