<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241020121452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE monnaie ADD entreprise_id INT DEFAULT NULL, ADD locale TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE monnaie ADD CONSTRAINT FK_B3A6E2E6A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_B3A6E2E6A4AEAFEA ON monnaie (entreprise_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE monnaie DROP FOREIGN KEY FK_B3A6E2E6A4AEAFEA');
        $this->addSql('DROP INDEX IDX_B3A6E2E6A4AEAFEA ON monnaie');
        $this->addSql('ALTER TABLE monnaie DROP entreprise_id, DROP locale');
    }
}
