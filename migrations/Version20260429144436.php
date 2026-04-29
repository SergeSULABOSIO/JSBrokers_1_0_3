<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429144436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `operation` (id INT AUTO_INCREMENT NOT NULL, bordereau_id INT NOT NULL, reference_police VARCHAR(255) NOT NULL, numero_avenant VARCHAR(255) NOT NULL, montant_ht DOUBLE PRECISION NOT NULL, montant_taxe DOUBLE PRECISION DEFAULT NULL, INDEX IDX_1981A66D55D5304E (bordereau_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE `operation` ADD CONSTRAINT FK_1981A66D55D5304E FOREIGN KEY (bordereau_id) REFERENCES bordereau (id)');
        $this->addSql('ALTER TABLE bordereau DROP created_at, DROP paid_at, CHANGE invite_id invite_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `operation` DROP FOREIGN KEY FK_1981A66D55D5304E');
        $this->addSql('DROP TABLE `operation`');
        $this->addSql('ALTER TABLE bordereau ADD created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE invite_id invite_id INT DEFAULT NULL');
    }
}
