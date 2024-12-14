<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214195734 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bordereau (id INT AUTO_INCREMENT NOT NULL, assureur_id INT DEFAULT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', montant_ttc DOUBLE PRECISION NOT NULL, INDEX IDX_F7B4C56180F7E20A (assureur_id), INDEX IDX_F7B4C561EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE facture_commission (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C56180F7E20A FOREIGN KEY (assureur_id) REFERENCES assureur (id)');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C561EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C56180F7E20A');
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C561EA417747');
        $this->addSql('DROP TABLE bordereau');
        $this->addSql('DROP TABLE facture_commission');
    }
}
