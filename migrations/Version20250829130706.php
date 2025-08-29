<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250829130706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document ADD nom_fichier_stocke VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE notification_sinistre CHANGE description_de_fait description_de_fait VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP nom_fichier_stocke');
        $this->addSql('ALTER TABLE notification_sinistre CHANGE description_de_fait description_de_fait VARCHAR(255) NOT NULL');
    }
}
