<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250125160656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE type_revenu ADD type_chargement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7DC8165237 FOREIGN KEY (type_chargement_id) REFERENCES chargement (id)');
        $this->addSql('CREATE INDEX IDX_5E74AB7DC8165237 ON type_revenu (type_chargement_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7DC8165237');
        $this->addSql('DROP INDEX IDX_5E74AB7DC8165237 ON type_revenu');
        $this->addSql('ALTER TABLE type_revenu DROP type_chargement_id');
    }
}
