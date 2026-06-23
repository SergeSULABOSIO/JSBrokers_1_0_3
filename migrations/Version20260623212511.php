<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623212511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table taxe_vente (fiscalité JS Brokers : taxes dues sur les ventes).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE taxe_vente (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, libelle VARCHAR(255) NOT NULL, autorite_nom VARCHAR(255) NOT NULL, autorite_abreviation VARCHAR(20) NOT NULL, taux NUMERIC(5, 2) NOT NULL, actif TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_427B566777153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE taxe_vente');
    }
}
