<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exécution RÉELLE (contre la BDD de test) du cœur de mutation de Ket :
 * édition et suppression effectives via le FormType de l'entité, scoping
 * entreprise strict, et fail-closed sur un invité sans rôle. Couvre le chemin
 * déterministe hors-LLM. Le FormType des champs autocomplete scope sa requête
 * sur l'utilisateur connecté (getConnectedTo) : on se connecte donc comme le
 * ferait l'endpoint réel. Chaque test seed et nettoie ses données.
 */
class WorkspaceMutationServiceTest extends WebTestCase
{
    private const ENT_A = 'PHPUnit-KetMut-A';
    private const ENT_B = 'PHPUnit-KetMut-B';
    private const OWNER_A = 'phpunit-ketmut-owner-a@test.local';
    private const OWNER_B = 'phpunit-ketmut-owner-b@test.local';
    private const INTRUS = 'phpunit-ketmut-intrus@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        // 1) Rompre le lien utilisateur → entreprise active (FK connected_to_id)
        //    avant de pouvoir supprimer les entreprises.
        foreach ([self::OWNER_A, self::OWNER_B, self::INTRUS] as $email) {
            $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => $email]);
        }
        // 2) Ordre des FK : client → invite → entreprise → utilisateur.
        foreach ([self::ENT_A, self::ENT_B] as $nom) {
            $conn->executeStatement('DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => $nom]);
            $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => $nom]);
            $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $nom]);
        }
        foreach ([self::OWNER_A, self::OWNER_B, self::INTRUS] as $email) {
            $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => $email]);
        }
        $this->em->clear();
    }

    private function seedUser(string $email): Utilisateur
    {
        $u = (new Utilisateur())->setEmail($email)->setNom('PHPUnit')->setVerified(true);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function seedEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $e = (new Entreprise())
            ->setNom($nom)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($e);

        return $e;
    }

    private function seedOwnerInvite(Entreprise $e, Utilisateur $owner): Invite
    {
        $i = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($e)->setProprietaire(true);
        $this->em->persist($i);

        return $i;
    }

    private function seedClient(Entreprise $e, Invite $i, string $nom): Client
    {
        $c = (new Client())->setNom($nom)->setExonere(false)->setEntreprise($e)->setInvite($i);
        $this->em->persist($c);

        return $c;
    }

    public function testEditionModifieReellementLaFiche(): void
    {
        $owner = $this->seedUser(self::OWNER_A);
        $ent = $this->seedEntreprise(self::ENT_A, $owner);
        $inv = $this->seedOwnerInvite($ent, $owner);
        $client = $this->seedClient($ent, $inv, 'Client Édition');
        $owner->setConnectedTo($ent); // le FormType scope l'autocomplete sur l'entreprise active
        $this->em->flush();
        $id = $client->getId();

        // Contexte authentifié comme l'endpoint réel (getConnectedTo du FormType).
        $this->client->loginUser($owner);

        $op = new MutationOperation('edit', 'Client', $id, ['telephone' => '+243999888777']);
        $step = $this->service->executer($op, new AiScope($ent, $inv), $owner);

        $this->assertSame('edit', $step['op']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Client::class)->find($id);
        $this->assertSame('+243999888777', $reloaded->getTelephone(), 'Le téléphone a été réellement persisté.');
    }

    public function testCreationEnregistreReellementLaFiche(): void
    {
        $owner = $this->seedUser(self::OWNER_A);
        $ent = $this->seedEntreprise(self::ENT_A, $owner);
        $inv = $this->seedOwnerInvite($ent, $owner);
        $owner->setConnectedTo($ent);
        $this->em->flush();
        $this->client->loginUser($owner);

        $op = new MutationOperation('create', 'Client', null, [
            'nom' => 'Orange RDC', 'telephone' => '+243828727706',
            'email' => 'infos@orange.com', 'adresse' => 'Gombe, Kinshasa', 'exonere' => false,
        ]);
        $step = $this->service->executer($op, new AiScope($ent, $inv), $owner);
        $this->assertSame('create', $step['op']);

        $this->em->clear();
        $created = $this->em->getRepository(Client::class)->findOneBy(['nom' => 'Orange RDC']);
        $this->assertNotNull($created, 'Le client a été réellement créé.');
        $this->assertSame('+243828727706', $created->getTelephone());
        $this->assertFalse($created->isExonere(), 'Le booléen envoyé par le LLM est normalisé et persisté.');
    }

    public function testCreationRefuseUnChampObligatoireManquant(): void
    {
        // « exonere » (non-nullable, sans défaut) non fourni : refus PROPRE (422)
        // au lieu d'une erreur SQL 500 — Ket doit demander l'information.
        $owner = $this->seedUser(self::OWNER_A);
        $ent = $this->seedEntreprise(self::ENT_A, $owner);
        $inv = $this->seedOwnerInvite($ent, $owner);
        $owner->setConnectedTo($ent);
        $this->em->flush();
        $this->client->loginUser($owner);

        try {
            $this->service->executer(
                new MutationOperation('create', 'Client', null, ['nom' => 'Sans Exonere']),
                new AiScope($ent, $inv),
                $owner,
            );
            $this->fail('Une MutationException était attendue (champ obligatoire manquant).');
        } catch (MutationException $e) {
            $this->assertSame(MutationException::INVALIDE, $e->statut);
            $this->assertArrayHasKey('exonere', $e->erreursChamps);
        }
    }

    public function testSuppressionSupprimeReellementLaFiche(): void
    {
        $owner = $this->seedUser(self::OWNER_A);
        $ent = $this->seedEntreprise(self::ENT_A, $owner);
        $inv = $this->seedOwnerInvite($ent, $owner);
        $client = $this->seedClient($ent, $inv, 'Client Suppression');
        $this->em->flush();
        $id = $client->getId();

        $this->service->executer(new MutationOperation('delete', 'Client', $id), new AiScope($ent, $inv), $owner);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Client::class)->find($id), 'La fiche a été réellement supprimée.');
    }

    public function testCibleHorsEntrepriseRefusee(): void
    {
        $ownerA = $this->seedUser(self::OWNER_A);
        $entA = $this->seedEntreprise(self::ENT_A, $ownerA);
        $invA = $this->seedOwnerInvite($entA, $ownerA);

        $ownerB = $this->seedUser(self::OWNER_B);
        $entB = $this->seedEntreprise(self::ENT_B, $ownerB);
        $invB = $this->seedOwnerInvite($entB, $ownerB);
        $clientB = $this->seedClient($entB, $invB, 'Client de B');
        $this->em->flush();

        // Scope A tente d'éditer un client de B : introuvable dans son périmètre.
        $this->expectException(MutationException::class);
        $this->service->executer(
            new MutationOperation('edit', 'Client', $clientB->getId(), ['telephone' => '+243111']),
            new AiScope($entA, $invA),
            $ownerA,
        );
    }

    public function testFailClosedInviteSansRole(): void
    {
        $owner = $this->seedUser(self::OWNER_A);
        $ent = $this->seedEntreprise(self::ENT_A, $owner);
        $this->seedOwnerInvite($ent, $owner);
        $client = $this->seedClient($ent, $this->seedOwnerInvite($ent, $owner), 'Client X');

        $intrus = $this->seedUser(self::INTRUS);
        $inviteIntrus = (new Invite())->setNom('Intrus')->setUtilisateur($intrus)->setEntreprise($ent)->setProprietaire(false);
        $this->em->persist($inviteIntrus);
        $this->em->flush();

        $this->expectException(MutationException::class);
        $this->service->executer(
            new MutationOperation('edit', 'Client', $client->getId(), ['telephone' => '+243222']),
            new AiScope($ent, $inviteIntrus),
            $intrus,
        );
    }
}
