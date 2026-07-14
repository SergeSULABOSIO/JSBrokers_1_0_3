<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistant IA : objets attachés au contexte d'une conversation
 * (assistant_conversation_contexte). FK en ON DELETE CASCADE, comme
 * assistant_message, pour rester compatible avec la suppression DQL
 * d'une conversation.
 */
final class Version20260714090932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Assistant IA : table assistant_conversation_contexte (objets attachés au contexte d'une conversation).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assistant_conversation_contexte (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id INT NOT NULL, label VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_1A59CEAC9AC0396 (conversation_id), UNIQUE INDEX uniq_assistant_ctx_objet (conversation_id, entity_type, entity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE assistant_conversation_contexte ADD CONSTRAINT FK_1A59CEAC9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_conversation_contexte DROP FOREIGN KEY FK_1A59CEAC9AC0396');
        $this->addSql('DROP TABLE assistant_conversation_contexte');
    }
}
