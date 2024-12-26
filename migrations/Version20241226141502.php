<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241226141502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_2FE5CE5EA417747');
        $this->addSql('DROP INDEX IDX_2FE5CE5EA417747 ON avenant');
        $this->addSql('ALTER TABLE avenant DROP invite_id');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_66675840EA417747');
        $this->addSql('DROP INDEX IDX_66675840EA417747 ON tranche');
        $this->addSql('ALTER TABLE tranche DROP invite_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_2FE5CE5EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_2FE5CE5EA417747 ON avenant (invite_id)');
        $this->addSql('ALTER TABLE tranche ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_66675840EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_66675840EA417747 ON tranche (invite_id)');
    }
}
