<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pièces jointes du chat de l'assistant IA : table des fichiers attachés à une
 * conversation (AssistantConversationFichier) + instantané des pièces jointes
 * sur les messages utilisateur (assistant_message.fichiers_joints).
 */
final class Version20260729105413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat IA : fichiers attachés à la conversation + instantané fichiers_joints sur les messages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assistant_conversation_fichier (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, nom_original VARCHAR(255) NOT NULL, nom_fichier_stocke VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(120) DEFAULT NULL, taille INT NOT NULL, texte_extrait LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F55B71369AC0396 (conversation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE assistant_conversation_fichier ADD CONSTRAINT FK_F55B71369AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_message ADD fichiers_joints JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_conversation_fichier DROP FOREIGN KEY FK_F55B71369AC0396');
        $this->addSql('DROP TABLE assistant_conversation_fichier');
        $this->addSql('ALTER TABLE assistant_message DROP fichiers_joints');
    }
}
