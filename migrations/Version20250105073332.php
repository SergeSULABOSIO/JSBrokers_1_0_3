<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250105073332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE condition_partage (id INT AUTO_INCREMENT NOT NULL, partenaire_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, formule INT NOT NULL, seuil DOUBLE PRECISION NOT NULL, taux DOUBLE PRECISION DEFAULT NULL, INDEX IDX_CF012D1F98DE13AC (partenaire_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE condition_partage ADD CONSTRAINT FK_CF012D1F98DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE condition_partage DROP FOREIGN KEY FK_CF012D1F98DE13AC');
        $this->addSql('DROP TABLE condition_partage');
    }
}
