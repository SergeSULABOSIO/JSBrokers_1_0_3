<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chat IA : action « Répondre » sur une bulle (façon WhatsApp). Un message peut
 * désormais CITER un message antérieur de la même conversation.
 *
 * Relation auto-référencée plutôt qu'instantané JSON : le contenu d'un message
 * n'étant jamais édité, il n'y a rien à figer, et l'id est de toute façon
 * nécessaire pour faire défiler le fil jusqu'à la bulle citée.
 *
 * ON DELETE SET NULL, et non CASCADE : supprimer un message cité ne doit jamais
 * emporter les réponses qu'il a suscitées. Cette action cohabite avec le
 * ON DELETE CASCADE de conversation_id, dont dépend la suppression d'une
 * conversation en un seul DELETE (AssistantIaController::deleteConversation) —
 * cohabitation vérifiée par AssistantIaCitationTest.
 */
final class Version20260730093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat IA : un message peut citer un message antérieur de la même conversation (« Répondre »).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_message ADD repond_a_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8A36E1EFEB5A4A82 ON assistant_message (repond_a_id)');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EFEB5A4A82 FOREIGN KEY (repond_a_id) REFERENCES assistant_message (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_message DROP FOREIGN KEY FK_8A36E1EFEB5A4A82');
        $this->addSql('DROP INDEX IDX_8A36E1EFEB5A4A82 ON assistant_message');
        $this->addSql('ALTER TABLE assistant_message DROP repond_a_id');
    }
}
