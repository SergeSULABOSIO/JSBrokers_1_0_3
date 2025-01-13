<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250113144315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE autorite_fiscale ADD note_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE autorite_fiscale ADD CONSTRAINT FK_FF48423026ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('CREATE INDEX IDX_FF48423026ED0855 ON autorite_fiscale (note_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE autorite_fiscale DROP FOREIGN KEY FK_FF48423026ED0855');
        $this->addSql('DROP INDEX IDX_FF48423026ED0855 ON autorite_fiscale');
        $this->addSql('ALTER TABLE autorite_fiscale DROP note_id');
    }
}
