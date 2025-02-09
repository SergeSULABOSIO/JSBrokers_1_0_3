<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250207214318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE type_revenu ADD note_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7D26ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('CREATE INDEX IDX_5E74AB7D26ED0855 ON type_revenu (note_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7D26ED0855');
        $this->addSql('DROP INDEX IDX_5E74AB7D26ED0855 ON type_revenu');
        $this->addSql('ALTER TABLE type_revenu DROP note_id');
    }
}
