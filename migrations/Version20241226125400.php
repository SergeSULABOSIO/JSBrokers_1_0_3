<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241226125400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075EA417747');
        $this->addSql('DROP INDEX IDX_93872075EA417747 ON tache');
        $this->addSql('ALTER TABLE tache DROP invite_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_93872075EA417747 ON tache (invite_id)');
    }
}
