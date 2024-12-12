<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241212151007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE risque (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT DEFAULT NULL, code VARCHAR(6) NOT NULL, description VARCHAR(255) DEFAULT NULL, pourcentage_commission_specifique_ht DOUBLE PRECISION DEFAULT NULL, branche INT NOT NULL, nom_complet VARCHAR(255) NOT NULL, imposable TINYINT(1) NOT NULL, INDEX IDX_20230D24A4AEAFEA (entreprise_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE risque ADD CONSTRAINT FK_20230D24A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risque DROP FOREIGN KEY FK_20230D24A4AEAFEA');
        $this->addSql('DROP TABLE risque');
    }
}
