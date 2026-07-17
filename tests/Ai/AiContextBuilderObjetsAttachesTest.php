<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Entity\AssistantConversation;
use App\Entity\AssistantConversationContexte;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AiContextBuilder : les fiches des objets ATTACHÉS à la conversation entrent
 * dans le contexte système (build) et dans le prompt des moteurs réels
 * (toSystemPrompt), avec re-validation fail-closed au moment de l'envoi —
 * objet supprimé ou hors périmètre exclu silencieusement, prompt strictement
 * inchangé sans objet (non-régression).
 */
class AiContextBuilderObjetsAttachesTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-iactxbuilder-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iactxbuilder-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit IACTX Builder SARL';

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

    private function builder(): AiContextBuilder
    {
        return static::getContainer()->get(AiContextBuilder::class);
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
        foreach (['assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        foreach (['assistant_conversation', 'assistant_parametres', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
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
     * Entreprise + propriétaire (accès complet) + invité SANS rôle (fail-closed).
     *
     * @return array{entreprise: Entreprise, owner: Invite, guest: Invite}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit Builder');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-CTXB');
        $entreprise->setAdresse('1 rue du Builder');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-CTXB');
        $entreprise->setIdnat('IDNAT-CTXB');
        $entreprise->setNumimpot('IMP-CTXB');
        $entreprise->setUtilisateur($ownerUser);
        $em->persist($entreprise);

        $owner = new Invite();
        $owner->setNom('Propriétaire');
        $owner->setUtilisateur($ownerUser);
        $owner->setEntreprise($entreprise);
        $owner->setProprietaire(true);
        $em->persist($owner);

        $guestUser = new Utilisateur();
        $guestUser->setEmail(self::GUEST_EMAIL);
        $guestUser->setNom('PHPUnit Builder Invité');
        $guestUser->setVerified(true);
        $guestUser->setPassword('irrelevant');
        $em->persist($guestUser);

        $guest = new Invite();
        $guest->setNom('Invité sans rôle');
        $guest->setUtilisateur($guestUser);
        $guest->setEntreprise($entreprise);
        $guest->setProprietaire(false);
        $em->persist($guest);

        $em->flush();

        return ['entreprise' => $entreprise, 'owner' => $owner, 'guest' => $guest];
    }

    private function makeClient(Entreprise $entreprise, string $nom): Client
    {
        $client = new Client();
        $client->setNom($nom);
        $client->setExonere(false);
        $client->setEntreprise($entreprise);
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    private function makeConversationAvecContexte(Entreprise $entreprise, Invite $invite, string $type, int $entityId, string $label): AssistantConversation
    {
        $conversation = (new AssistantConversation())
            ->setEntreprise($entreprise)
            ->setInvite($invite);
        $conversation->addContexte((new AssistantConversationContexte())
            ->setEntityType($type)
            ->setEntityId($entityId)
            ->setLabel($label));
        $this->em()->persist($conversation);
        $this->em()->flush();

        return $conversation;
    }

    public function testFichePresenteDansContexteEtPrompt(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->seed();
        $client = $this->makeClient($e, 'Builder Client Alpha');
        $conversation = $this->makeConversationAvecContexte($e, $owner, 'Client', $client->getId(), 'Builder Client Alpha');

        $request = $this->builder()->build($e, $owner, $conversation);

        $objets = $request->systemContext['objetsAttaches'];
        $this->assertCount(1, $objets);
        $this->assertSame('Client', $objets[0]['type']);
        $this->assertSame($client->getId(), $objets[0]['id']);
        $this->assertSame('Builder Client Alpha', $objets[0]['nom']);
        $this->assertSame('Builder Client Alpha', $objets[0]['fiche']['nom'], 'La fiche list:read doit être embarquée.');

        $prompt = $this->builder()->toSystemPrompt($request);
        $this->assertStringContainsString('ATTACHÉ', $prompt);
        $this->assertStringContainsString('Builder Client Alpha', $prompt);

        // Sémantique de recentrage OBLIGATOIRE : les objets attachés sont les sujets
        // principaux de la conversation, et la liste ACTUELLE prévaut sur l'historique
        // (un objet remplacé/retiré ne doit plus guider les réponses).
        $this->assertStringContainsString('SUJETS PRINCIPAUX', $prompt);
        $this->assertStringContainsString('recentre ton raisonnement', $prompt);
        $this->assertStringContainsString('PRÉVAUT sur l\'historique', $prompt);
    }

    public function testHistoriqueAnnoteAvecLInstantaneDuMessage(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->seed();
        $client = $this->makeClient($e, 'Builder Client Beta');
        $conversation = $this->makeConversationAvecContexte($e, $owner, 'Client', $client->getId(), 'Builder Client Beta');

        // Un message utilisateur PORTEUR d'un instantané (envoyé quand un AUTRE
        // objet était en contexte) et un message sans instantané.
        $conversation->addMessage((new \App\Entity\AssistantMessage())
            ->setRole(\App\Entity\AssistantMessage::ROLE_USER)
            ->setContenu('Quel est son solde ?')
            ->setContexteObjets([['type' => 'Tranche', 'id' => 71, 'nom' => 'Tranche unique']]));
        $conversation->addMessage((new \App\Entity\AssistantMessage())
            ->setRole(\App\Entity\AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Le solde est de 0,00 USD.'));
        $this->em()->persist($conversation);
        $this->em()->flush();

        $request = $this->builder()->build($e, $owner, $conversation);

        // Le message utilisateur transporte son cliché en tête de contenu — le
        // moteur sait sur QUOI portait ce message, même si le contexte a changé.
        $this->assertStringContainsString(
            "[Objets en contexte à l'envoi de ce message : Tranche #71 — Tranche unique]",
            $request->messages[0]['content'],
        );
        $this->assertStringContainsString('Quel est son solde ?', $request->messages[0]['content']);
        // La réponse de l'assistant, elle, reste intacte (pas d'annotation).
        $this->assertSame('Le solde est de 0,00 USD.', $request->messages[1]['content']);
    }

    public function testObjetSupprimeExcluSilencieusement(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->seed();
        // Le contexte pointe un id qui n'existe pas (objet supprimé entre-temps).
        $conversation = $this->makeConversationAvecContexte($e, $owner, 'Client', 99999999, 'Client Fantôme');

        $request = $this->builder()->build($e, $owner, $conversation);
        $this->assertSame([], $request->systemContext['objetsAttaches']);

        $prompt = $this->builder()->toSystemPrompt($request);
        $this->assertStringNotContainsString('ATTACHÉ', $prompt);
        $this->assertStringNotContainsString('Client Fantôme', $prompt);
    }

    public function testObjetHorsPerimetreExclu(): void
    {
        ['entreprise' => $e, 'guest' => $guest] = $this->seed();
        $client = $this->makeClient($e, 'Builder Client Restreint');
        // L'invité n'a AUCUN rôle : même attaché (p. ex. rôle retiré depuis),
        // l'objet est re-validé et exclu au moment de l'envoi (fail-closed).
        $conversation = $this->makeConversationAvecContexte($e, $guest, 'Client', $client->getId(), 'Builder Client Restreint');

        $request = $this->builder()->build($e, $guest, $conversation);
        $this->assertSame([], $request->systemContext['objetsAttaches']);
        $this->assertStringNotContainsString('Builder Client Restreint', $this->builder()->toSystemPrompt($request));
    }

    public function testPromptInchangeSansContexte(): void
    {
        ['entreprise' => $e, 'owner' => $owner] = $this->seed();
        $conversation = (new AssistantConversation())
            ->setEntreprise($e)
            ->setInvite($owner);
        $this->em()->persist($conversation);
        $this->em()->flush();

        $request = $this->builder()->build($e, $owner, $conversation);
        $this->assertSame([], $request->systemContext['objetsAttaches']);

        $prompt = $this->builder()->toSystemPrompt($request);
        $this->assertStringNotContainsString('ATTACHÉ', $prompt, 'Sans objet, le prompt reste strictement identique.');
        $this->assertStringContainsString('périmètre', $prompt);
    }
}
