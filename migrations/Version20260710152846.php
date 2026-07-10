<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistant IA du workspace : tables assistant_parametres (nom du personnage,
 * une ligne par entreprise), assistant_conversation (fils par invité) et
 * assistant_message (échanges user/assistant). Migration strictement additive —
 * les diffs hors périmètre (dérive d'index CRM préexistante) ont été retirés.
 */
final class Version20260710152846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA : assistant_parametres, assistant_conversation, assistant_message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assistant_conversation (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT NOT NULL, invite_id INT NOT NULL, titre VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_5BF3529BA4AEAFEA (entreprise_id), INDEX IDX_5BF3529BEA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE assistant_message (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, role VARCHAR(12) NOT NULL, contenu LONGTEXT NOT NULL, meta JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8A36E1EF9AC0396 (conversation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE assistant_parametres (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT NOT NULL, nom VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_5236FA7A4AEAFEA (entreprise_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE assistant_conversation ADD CONSTRAINT FK_5BF3529BA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_conversation ADD CONSTRAINT FK_5BF3529BEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_parametres ADD CONSTRAINT FK_5236FA7A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_conversation DROP FOREIGN KEY FK_5BF3529BA4AEAFEA');
        $this->addSql('ALTER TABLE assistant_conversation DROP FOREIGN KEY FK_5BF3529BEA417747');
        $this->addSql('ALTER TABLE assistant_message DROP FOREIGN KEY FK_8A36E1EF9AC0396');
        $this->addSql('ALTER TABLE assistant_parametres DROP FOREIGN KEY FK_5236FA7A4AEAFEA');
        $this->addSql('DROP TABLE assistant_conversation');
        $this->addSql('DROP TABLE assistant_message');
        $this->addSql('DROP TABLE assistant_parametres');
    }
}
