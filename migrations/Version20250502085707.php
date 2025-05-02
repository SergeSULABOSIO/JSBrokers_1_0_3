<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250502085707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE roles_en_production (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, access_groupe LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_client LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_assureur LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_contact LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_risque LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_avenant LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_partenaire LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', access_cotation LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE roles_en_production');
    }
}
