<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'historique transmis au moteur doit RÉVÉLER le sort réel d'un plan d'écriture :
 * un message assistant qui présentait un plan « cliquez sur Valider » mais dont la
 * meta indique qu'il a été EXÉCUTÉ (ou ANNULÉ) est annoté, sinon le moteur croit le
 * plan encore en attente et le re-prépare (ou nie à tort l'enregistrement).
 */
class AiContextBuilderMutationMarqueurTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ctxmut-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CtxMut SARL';

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{0:Entreprise,1:Invite} */
    private function seed(): array
    {
        $em = $this->em();
        $user = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword('x');
        $em->persist($user);
        $ent = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('L')->setAdresse('1 rue')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($ent);
        $inv = (new Invite())->setNom('Owner')->setUtilisateur($user)->setEntreprise($ent)->setProprietaire(true);
        $em->persist($inv);
        $em->flush();

        return [$ent, $inv];
    }

    private function build(Entreprise $ent, Invite $inv, AssistantConversation $conv): array
    {
        return static::getContainer()->get(AiContextBuilder::class)->build($ent, $inv, $conv)->messages;
    }

    private function conversationAvecPlan(Entreprise $ent, Invite $inv, array $meta): AssistantConversation
    {
        $conv = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $conv->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan préparé. Cliquez sur « Valider et exécuter ».')
            ->setMeta($meta));
        $this->em()->persist($conv);
        $this->em()->flush();

        return $conv;
    }

    public function testPlanExecuteEstAnnoteDansLHistorique(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, ['mutationPlan' => ['plan' => []], 'mutationPlanExecuted' => true]);

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringContainsString('VALIDÉ et EXÉCUTÉ', $messages[0]['content']);
    }

    public function testPlanAnnuleEstAnnoteDansLHistorique(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, ['mutationPlan' => ['plan' => []], 'mutationPlanCancelled' => true]);

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringContainsString('ANNULÉ', $messages[0]['content']);
    }

    public function testMessageAssistantOrdinaireNonAnnote(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, []); // pas de plan

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringNotContainsString('[SYSTÈME —', $messages[0]['content']);
    }
}
