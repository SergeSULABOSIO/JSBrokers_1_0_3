<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260206205348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE modele_piece_sinistre CHANGE entreprise_id entreprise_id INT NOT NULL, CHANGE description description VARCHAR(255) DEFAULT NULL, CHANGE obligatoire obligatoire TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE modele_piece_sinistre CHANGE entreprise_id entreprise_id INT DEFAULT NULL, CHANGE description description VARCHAR(255) NOT NULL, CHANGE obligatoire obligatoire TINYINT(1) DEFAULT NULL');
    }
}
