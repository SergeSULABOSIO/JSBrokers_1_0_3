<?php

namespace App\Tests\Ai;

use App\Ai\Export\MessageExporter;
use App\Ai\Export\MessageMailNotifier;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EnvoyerMessageParEmailTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Raccourci « envoie ce message à x@y.z » : l'outil ne lit aucune donnée métier
 * et n'envoie rien lui-même. Il DÉSIGNE le message à expédier et émet une
 * uiAction, parce que le format par défaut — l'image — n'existe que dans le
 * navigateur (un graphique Chart.js vit dans un <canvas>).
 *
 * Ces tests portent donc sur ce qui fait la valeur de l'outil : le bon message
 * ciblé, les adresses filtrées, et le format par défaut.
 */
class EnvoyerMessageParEmailToolTest extends TestCase
{
    private EnvoyerMessageParEmailTool $tool;

    protected function setUp(): void
    {
        $this->tool = new EnvoyerMessageParEmailTool(Validation::createValidator());
    }

    /**
     * Conversation en mémoire. `$idsAssistant` simule la persistance : un message
     * sans id n'a jamais été enregistré (cas du message utilisateur en cours).
     *
     * @param array<int, array{role: string, id: int|null}> $messages
     */
    private function scope(array $messages, ?AssistantMessage &$dernierUser = null): AiScope
    {
        $conversation = new AssistantConversation();
        foreach ($messages as $definition) {
            $message = (new AssistantMessage())->setRole($definition['role'])->setContenu('contenu');
            if ($definition['id'] !== null) {
                (new \ReflectionProperty(AssistantMessage::class, 'id'))->setValue($message, $definition['id']);
            }
            $conversation->addMessage($message);
            if ($definition['role'] === AssistantMessage::ROLE_USER) {
                $dernierUser = $message;
            }
        }

        return new AiScope(new Entreprise(), new Invite(), $conversation);
    }

    /** Fil courant : Ket a répondu (id 10), l'utilisateur écrit (pas encore d'id). */
    private function scopeStandard(?AssistantMessage &$dernierUser = null): AiScope
    {
        return $this->scope([
            ['role' => AssistantMessage::ROLE_USER, 'id' => 8],
            ['role' => AssistantMessage::ROLE_ASSISTANT, 'id' => 10],
            ['role' => AssistantMessage::ROLE_USER, 'id' => null],
        ], $dernierUser);
    }

    // ── Déclenchement (moteur simulé) ──────────────────────────────────────

    public function testMatchExtraitLesAdressesDeLaDemande(): void
    {
        $args = $this->tool->match(
            'Envoie aussi ce message à l\'adresse: infos@js-brokers.com',
            $this->scopeStandard()
        );

        self::assertSame(['infos@js-brokers.com'], $args['destinataires']);
    }

    public function testMatchAcceptePlusieursAdressesEtLesDedoublonne(): void
    {
        $args = $this->tool->match(
            'Transmets ce message à a@x.cd et b@y.cd, ainsi qu\'à a@x.cd',
            $this->scopeStandard()
        );

        self::assertSame(['a@x.cd', 'b@y.cd'], $args['destinataires']);
    }

    public function testMatchReconnaitUnFormatDemande(): void
    {
        $args = $this->tool->match('Envoie ce message en pdf à a@x.cd', $this->scopeStandard());

        self::assertSame(MessageExporter::FORMAT_PDF, $args['format']);
    }

    public function testSansAdresseLoutilNeSeDeclenchePas(): void
    {
        // Sans adresse, c'est le carnet de contacts qu'il faut — pas ce raccourci.
        self::assertNull($this->tool->match('Envoie ce message par e-mail', $this->scopeStandard()));
        self::assertNull($this->tool->match('Quel est mon chiffre d\'affaires ?', $this->scopeStandard()));
    }

    // ── Ciblage du message ─────────────────────────────────────────────────

    public function testCibleLaDerniereReponseDeKet(): void
    {
        $resultat = $this->tool->execute(['destinataires' => ['a@x.cd']], $this->scopeStandard());

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertSame(10, $resultat->uiAction['idMessage']);
        self::assertSame('assistant:message.envoyer-direct', $resultat->uiAction['type']);
    }

    public function testUneCitationExpliciteLemporteSurLaDerniereReponse(): void
    {
        // « Répondre » sur une bulle précise puis « envoie ce message » : c'est
        // CETTE bulle que l'utilisateur désigne, pas la dernière du fil.
        $scope = $this->scope([
            ['role' => AssistantMessage::ROLE_ASSISTANT, 'id' => 3],
            ['role' => AssistantMessage::ROLE_ASSISTANT, 'id' => 10],
            ['role' => AssistantMessage::ROLE_USER, 'id' => null],
        ], $courant);
        $cite = (new AssistantMessage())->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('cité');
        (new \ReflectionProperty(AssistantMessage::class, 'id'))->setValue($cite, 3);
        $courant->setRepondA($cite);

        $resultat = $this->tool->execute(['destinataires' => ['a@x.cd']], $scope);

        self::assertSame(3, $resultat->uiAction['idMessage']);
    }

    public function testFilSansReponseDeKet(): void
    {
        $scope = $this->scope([['role' => AssistantMessage::ROLE_USER, 'id' => null]]);

        $resultat = $this->tool->execute(['destinataires' => ['a@x.cd']], $scope);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
    }

    // ── Adresses et format ─────────────────────────────────────────────────

    public function testFormatParDefautEstLimage(): void
    {
        // C'est ce qui préserve la mise en forme ET les graphiques.
        $resultat = $this->tool->execute(['destinataires' => ['a@x.cd']], $this->scopeStandard());

        self::assertSame(MessageMailNotifier::FORMAT_IMAGE, $resultat->uiAction['format']);
    }

    public function testFormatExpliciteEstRespecte(): void
    {
        $resultat = $this->tool->execute(
            ['destinataires' => ['a@x.cd'], 'format' => MessageExporter::FORMAT_WORD],
            $this->scopeStandard()
        );

        self::assertSame(MessageExporter::FORMAT_WORD, $resultat->uiAction['format']);
    }

    public function testFormatInconnuRetombeSurLimage(): void
    {
        $resultat = $this->tool->execute(
            ['destinataires' => ['a@x.cd'], 'format' => 'zip'],
            $this->scopeStandard()
        );

        self::assertSame(MessageMailNotifier::FORMAT_IMAGE, $resultat->uiAction['format']);
    }

    public function testAdressesInvalidesSontEcarteesEtSignalees(): void
    {
        $resultat = $this->tool->execute(
            ['destinataires' => ['bon@x.cd', 'pas-un-email', 'a@']],
            $this->scopeStandard()
        );

        self::assertSame(['bon@x.cd'], $resultat->uiAction['destinataires']);
        self::assertSame(['pas-un-email', 'a@'], $resultat->data['rejetees']);
    }

    public function testAucuneAdresseValideEstSignaleeSansUiAction(): void
    {
        $resultat = $this->tool->execute(['destinataires' => ['pas-un-email']], $this->scopeStandard());

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertNull($resultat->uiAction);
    }

    public function testLeNombreDeDestinatairesEstBorne(): void
    {
        $trop = array_map(static fn (int $i): string => sprintf('d%d@x.cd', $i), range(1, 25));

        $resultat = $this->tool->execute(['destinataires' => $trop], $this->scopeStandard());

        self::assertCount(10, $resultat->uiAction['destinataires']);
    }

    public function testMotDAccompagnementEstTransmis(): void
    {
        $resultat = $this->tool->execute(
            ['destinataires' => ['a@x.cd'], 'message' => '  Bonjour, voici le point.  '],
            $this->scopeStandard()
        );

        self::assertSame('Bonjour, voici le point.', $resultat->uiAction['message']);
    }

    public function testLeSchemaExposeLesFormatsAuModele(): void
    {
        $schema = $this->tool->schema();

        self::assertSame(['destinataires'], $schema['required']);
        self::assertContains(MessageMailNotifier::FORMAT_IMAGE, $schema['properties']['format']['enum']);
        self::assertStringContainsString('IMAGE par défaut', $this->tool->description());
    }
}
