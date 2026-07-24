<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * VERROU ANTI-EMPILEMENT : tant qu'un plan d'écriture attend la décision de
 * l'utilisateur, Ket ne peut pas en préparer un second — sinon l'utilisateur se
 * retrouverait avec plusieurs barres « Valider et exécuter » à trancher l'une
 * après l'autre, ce qu'on veut précisément lui épargner.
 *
 * Le verrou est DUR (il vit dans l'outil, pas dans le prompt) et s'appuie sur
 * l'état de la CONVERSATION, porté jusqu'aux outils par AiScope. Son unique
 * échappatoire — remplacerPlanEnAttente — annule d'abord le plan en attente :
 * il n'y a jamais deux plans à valider.
 */
class PlanEnAttenteVerrouTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetVerrou';
    private const OWNER = 'phpunit-ketverrou-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PreparerOperationsTool $preparer;
    private PlanEnAttente $planEnAttente;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->preparer = static::getContainer()->get(PreparerOperationsTool::class);
        $this->planEnAttente = static::getContainer()->get(PlanEnAttente::class);
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        foreach (['assistant_conversation', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:AssistantConversation} */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv)->setTitre('Fil');
        $this->em->persist($conversation);
        $this->em->flush();
        $this->client->loginUser($owner);

        return [$ent, $inv, $conversation];
    }

    /** Message assistant portant un plan, dans l'état demandé. */
    private function seedPlan(AssistantConversation $conversation, ?string $decision = null): AssistantMessage
    {
        $meta = ['mutationPlan' => [
            'plan'   => [['op' => 'create', 'entite' => 'Client', 'fields' => ['nom' => 'Déjà proposé', 'exonere' => false]]],
            'budget' => ['coutEstime' => 30],
        ]];
        if ($decision !== null) {
            $meta[$decision] = true;
        }

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan.')
            ->setMeta($meta);
        $conversation->addMessage($message);
        $this->em->flush();

        return $message;
    }

    private function preparer(Entreprise $ent, Invite $inv, AssistantConversation $conversation, array $args = []): array
    {
        return $this->preparer->execute($args + ['operations' => [
            ['op' => 'create', 'entite' => 'Client', 'champs' => ['nom' => 'Nouveau plan', 'exonere' => false]],
        ]], new AiScope($ent, $inv, $conversation))->data;
    }

    // ───────────────────────────── Le verrou ─────────────────────────────

    public function testSansPlanEnAttenteLaPreparationPasse(): void
    {
        [$ent, $inv, $conversation] = $this->seed();

        $data = $this->preparer($ent, $inv, $conversation);

        $this->assertTrue($data['pret'], 'Aucun plan en attente : la préparation se déroule normalement.');
    }

    public function testUnPlanEnAttenteInterditDenPreparerUnSecond(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $this->seedPlan($conversation);

        $data = $this->preparer($ent, $inv, $conversation);

        $this->assertFalse($data['pret'], 'Le second plan est REFUSÉ tant que le premier n’est pas tranché.');
        $this->assertTrue($data['planEnAttente']);
        $this->assertArrayNotHasKey('plan', $data, 'Aucun tableau de plan n’est présenté.');
        $this->assertStringContainsString('1 opération, 30 tokens', $data['resumePlanEnAttente']);
    }

    public function testPlanValideOuAnnuleNeVerrouillePlus(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $this->seedPlan($conversation, 'mutationPlanExecuted');

        $this->assertTrue(
            $this->preparer($ent, $inv, $conversation)['pret'],
            'Un plan déjà exécuté ne bloque plus la suite du travail.',
        );

        $this->seedPlan($conversation, 'mutationPlanCancelled');
        $this->assertTrue(
            $this->preparer($ent, $inv, $conversation)['pret'],
            'Un plan annulé ne bloque pas non plus.',
        );
    }

    /** L'échappatoire : l'utilisateur veut CHANGER le plan — l'ancien est annulé, pas empilé. */
    public function testRemplacerLePlanEnAttenteLannuleEtPresenteLeNouveau(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $ancien = $this->seedPlan($conversation);

        $data = $this->preparer($ent, $inv, $conversation, ['remplacerPlanEnAttente' => true]);

        $this->assertTrue($data['pret'], 'Le nouveau plan est présenté.');
        $this->em->refresh($ancien);
        $this->assertTrue(
            PlanEnAttente::estAnnule($ancien->getMeta()),
            'L’ancien plan a été ANNULÉ : il n’y a jamais deux plans à valider.',
        );
        $this->assertNull(
            $this->planEnAttente->messageEnAttente($conversation),
            'Plus aucun plan de la conversation n’attend de décision au moment où le nouveau est présenté.',
        );
    }

    /** Hors conversation (exécution différée, test), le verrou est simplement inopérant. */
    public function testScopeSansConversationNeVerrouillePas(): void
    {
        [$ent, $inv, $conversation] = $this->seed();
        $this->seedPlan($conversation);

        $data = $this->preparer->execute(['operations' => [
            ['op' => 'create', 'entite' => 'Client', 'champs' => ['nom' => 'Hors fil', 'exonere' => false]],
        ]], new AiScope($ent, $inv));

        $this->assertTrue($data->data['pret']);
    }

    // ─────────────────── Source unique de l'état d'un plan ───────────────────

    public function testEtatDUnPlanEstLuAuMemeEndroitPartout(): void
    {
        $sansPlan = [];
        $enAttente = ['mutationPlan' => ['plan' => []]];
        $execute = ['mutationPlan' => ['plan' => []], 'mutationPlanExecuted' => true];
        $annule = ['mutationPlan' => ['plan' => []], 'mutationPlanCancelled' => true];

        $this->assertFalse(PlanEnAttente::porteUnPlan($sansPlan));
        $this->assertFalse(PlanEnAttente::estEnAttente($sansPlan));

        $this->assertTrue(PlanEnAttente::estEnAttente($enAttente));
        $this->assertFalse(PlanEnAttente::estExecute($enAttente));
        $this->assertFalse(PlanEnAttente::estAnnule($enAttente));

        $this->assertTrue(PlanEnAttente::estExecute($execute));
        $this->assertFalse(PlanEnAttente::estEnAttente($execute));

        $this->assertTrue(PlanEnAttente::estAnnule($annule));
        $this->assertFalse(PlanEnAttente::estEnAttente($annule));
    }

    /**
     * Filet du MÊME tour : le verrou de conversation ne voit que les tours
     * précédents. Si le moteur présente deux plans dans une seule réponse, seul
     * le premier survit — le second n'aurait aucun plan stocké derrière lui.
     */
    public function testUneReponseNePorteQuUneSeuleBarreDeDecision(): void
    {
        $actions = PlanEnAttente::limiterAUnSeulPlan([
            ['type' => 'app:workspace.open-dialog', 'entite' => 'Client'],
            ['type' => PlanEnAttente::ACTION_REVUE, 'plan' => ['premier']],
            ['type' => PlanEnAttente::ACTION_REVUE, 'plan' => ['second']],
            ['type' => 'app:workspace.data-changed'],
        ]);

        $revues = array_values(array_filter(
            $actions,
            static fn (array $a) => ($a['type'] ?? null) === PlanEnAttente::ACTION_REVUE,
        ));
        $this->assertCount(1, $revues, 'Une seule barre de décision par message.');
        $this->assertSame(['premier'], $revues[0]['plan']);
        $this->assertCount(3, $actions, 'Les autres directives UI passent inchangées.');
    }

    /** Le dernier plan en attente du fil est celui que l'utilisateur voit. */
    public function testSeulLePlanNonTrancheEstRetenu(): void
    {
        [, , $conversation] = $this->seed();
        $this->seedPlan($conversation, 'mutationPlanExecuted');
        $attendu = $this->seedPlan($conversation);

        $this->assertSame(
            $attendu->getId(),
            $this->planEnAttente->messageEnAttente($conversation)?->getId(),
        );
    }
}
