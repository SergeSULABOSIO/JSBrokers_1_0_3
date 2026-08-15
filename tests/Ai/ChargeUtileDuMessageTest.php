<?php

namespace App\Tests\Ai;

use App\Ai\Document\DocumentEnAttente;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Presentation\ChargeUtileDuMessage;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use PHPUnit\Framework\TestCase;

/**
 * LE CONTRAT AVEC LE NAVIGATEUR, reconstruit depuis les entités.
 *
 * Ce que ces tests protègent tient en une phrase : la meta d'un message est
 * écrite à travers un array_filter, qui écarte les valeurs vides. `refus: false`
 * et `actions: []` n'y figurent donc PAS. Si la charge utile se contentait de
 * relire la meta, ces deux clés disparaîtraient du JSON dès que la réponse n'est
 * ni un refus ni porteuse d'action — c'est-à-dire dans le cas le plus courant —
 * et le navigateur recevrait un contrat de forme variable.
 *
 * C'est le genre de régression qu'on ne voit pas : rien ne casse bruyamment, un
 * `data.assistant.refus` devient simplement `undefined`.
 */
class ChargeUtileDuMessageTest extends TestCase
{
    private ChargeUtileDuMessage $chargeUtile;

    protected function setUp(): void
    {
        $this->chargeUtile = new ChargeUtileDuMessage();
    }

    /** Les identifiants sont générés par la base : ici on les pose à la main. */
    private function avecId(AssistantMessage $message, int $id): AssistantMessage
    {
        $propriete = new \ReflectionProperty(AssistantMessage::class, 'id');
        $propriete->setAccessible(true);
        $propriete->setValue($message, $id);

        return $message;
    }

    private function conversation(?string $titre = 'Un titre'): AssistantConversation
    {
        return (new AssistantConversation())->setTitre($titre);
    }

    public function testLesClesDuContratSontToujoursLesMemes(): void
    {
        $conversation = $this->conversation();
        $question = $this->avecId(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('Bonjour.'),
            1
        );
        $reponse = $this->avecId(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('Bonjour !'),
            2
        );
        $conversation->addMessage($question)->addMessage($reponse);

        $charge = $this->chargeUtile->pour($question, $reponse);

        self::assertSame(['user', 'assistant', 'conversationTitre'], array_keys($charge));
        self::assertSame(
            ['id', 'contenu', 'contexteObjets', 'fichiersJoints', 'citation', 'createdAt'],
            array_keys($charge['user'])
        );
        self::assertSame(
            ['id', 'contenu', 'refus', 'actions', 'createdAt', 'activite'],
            array_keys($charge['assistant'])
        );
        self::assertSame('Un titre', $charge['conversationTitre']);
    }

    /**
     * LE CŒUR DU FICHIER. Une réponse ordinaire n'a ni refus ni action : sa meta
     * est vide, et pourtant les deux clés doivent être là, typées.
     */
    public function testUneMetaVideRendQuandMemeRefusFauxEtActionsVides(): void
    {
        $conversation = $this->conversation();
        $question = $this->avecId((new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('?'), 1);
        // array_filter tel que l'écrit le traitement : tout ce qui est vide saute.
        $meta = array_filter(['engine' => 'simulated', 'refus' => false ?: null, 'actions' => [] ?: null]);
        $reponse = $this->avecId(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('!')->setMeta($meta),
            2
        );
        $conversation->addMessage($question)->addMessage($reponse);

        self::assertArrayNotHasKey('refus', $meta, 'Prémisse : array_filter a bien écarté false.');
        self::assertArrayNotHasKey('actions', $meta, 'Prémisse : array_filter a bien écarté [].');

        $charge = $this->chargeUtile->pour($question, $reponse);

        self::assertFalse($charge['assistant']['refus'], "La clé existe et vaut false, jamais null ni absente.");
        self::assertSame([], $charge['assistant']['actions']);
        self::assertNull($charge['assistant']['activite']);
    }

    public function testUnRefusEstRestitueTelQuel(): void
    {
        $conversation = $this->conversation();
        $question = $this->avecId((new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('?'), 1);
        $reponse = $this->avecId(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu('Hors de mon périmètre.')
                ->setMeta(['refus' => true]),
            2
        );
        $conversation->addMessage($question)->addMessage($reponse);

        self::assertTrue($this->chargeUtile->pour($question, $reponse)['assistant']['refus']);
    }

    /**
     * Une action de revue reçoit l'identifiant du message : c'est de lui que le
     * navigateur dérive l'URL d'exécution. Et la spec, volumineuse, ne part pas.
     */
    public function testLActionDeRevuePorteLIdentifiantDuMessageEtPasLaSpec(): void
    {
        $conversation = $this->conversation();
        $question = $this->avecId((new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('?'), 1);
        $reponse = $this->avecId(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu('Voici le plan.')
                ->setMeta(['actions' => [
                    ['type' => PlanEnAttente::ACTION_REVUE, 'spec' => ['volumineux'], 'pied' => 'x'],
                    ['type' => DocumentEnAttente::ACTION_REVUE, 'spec' => ['volumineux']],
                    ['type' => 'open-dialog', 'entite' => 'Client'],
                ]]),
            77
        );
        $conversation->addMessage($question)->addMessage($reponse);

        $actions = $this->chargeUtile->pour($question, $reponse)['assistant']['actions'];

        self::assertSame(77, $actions[0]['idMessage']);
        self::assertSame(77, $actions[1]['idMessage']);
        self::assertArrayNotHasKey('idMessage', $actions[2], "Seules les barres de décision en ont besoin.");
        foreach ($actions as $action) {
            self::assertArrayNotHasKey('spec', $action, "La spec reste au serveur.");
            self::assertArrayNotHasKey('pied', $action);
        }
    }

    /**
     * LE LIBELLÉ D'UNE CONVERSATION NON RENOMMÉE. Il valait autrefois les
     * quatre-vingts premiers caractères du premier message — une phrase entière
     * dans un onglet, figée pour toujours sur le hasard de la première question.
     */
    public function testUneConversationSansTitreSAppelleParSonIdentifiant(): void
    {
        $conversation = $this->conversation(null);
        $propriete = new \ReflectionProperty(AssistantConversation::class, 'id');
        $propriete->setAccessible(true);
        $propriete->setValue($conversation, 135);

        self::assertSame('CONV#135', $conversation->libelle());

        $question = $this->avecId((new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('?'), 1);
        $conversation->addMessage($question);
        self::assertSame('CONV#135', $this->chargeUtile->pour($question, null)['conversationTitre']);
    }

    /** Dès que l'utilisateur a choisi un nom, c'est le sien qui prime. */
    public function testUnTitreChoisiPrimeSurLeLibelleDerive(): void
    {
        $conversation = $this->conversation('Dossier SFA 2026');
        $question = $this->avecId((new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('?'), 1);
        $conversation->addMessage($question);

        self::assertSame('Dossier SFA 2026', $conversation->libelle());
        self::assertSame('Dossier SFA 2026', $this->chargeUtile->pour($question, null)['conversationTitre']);
    }

    /** La citation est un contrat testable, même si le front l'affiche sans attendre. */
    public function testLaCitationEstRestituee(): void
    {
        $conversation = $this->conversation();
        $cite = $this->avecId(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('Une réponse antérieure.'),
            5
        );
        $question = $this->avecId(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('À ce sujet…')->setRepondA($cite),
            6
        );
        $conversation->addMessage($cite)->addMessage($question);

        $charge = $this->chargeUtile->pour($question, null);

        self::assertSame(5, $charge['user']['citation']['id']);
        self::assertSame(AssistantMessage::ROLE_ASSISTANT, $charge['user']['citation']['role']);
        self::assertNull($charge['assistant'], "Tant que la réponse n'existe pas, la clé est nulle — pas absente.");
    }
}
