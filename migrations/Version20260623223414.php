<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623223414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend utilisateur.created_at obligatoire (NOT NULL) ; renseigne les comptes hérités sans date.';
    }

    public function up(Schema $schema): void
    {
        // Renseigne les comptes hérités dont la date de création est absente, afin
        // que le passage en NOT NULL réussisse (date inconnue → date de migration).
        $this->addSql('UPDATE utilisateur SET created_at = NOW() WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE utilisateur CHANGE created_at created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
