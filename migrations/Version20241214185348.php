<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214185348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE avenant (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, type INT NOT NULL, starting_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ending_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_2FE5CE5EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chargement_pour_prime (id INT AUTO_INCREMENT NOT NULL, type_id INT DEFAULT NULL, montant_flat_exceptionel DOUBLE PRECISION DEFAULT NULL, taux_exceptionel DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8065D3CAC54C8C93 (type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, classeur_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D8698A76EA417747 (invite_id), INDEX IDX_D8698A76EC10E96A (classeur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE revenu_pour_courtier (id INT AUTO_INCREMENT NOT NULL, type_id INT DEFAULT NULL, montant_flat_exceptionel DOUBLE PRECISION DEFAULT NULL, taux_exceptionel DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8CAA04C1C54C8C93 (type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tranche (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, montant_flat DOUBLE PRECISION DEFAULT NULL, pourcentage DOUBLE PRECISION DEFAULT NULL, payable_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_66675840EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_2FE5CE5EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE chargement_pour_prime ADD CONSTRAINT FK_8065D3CAC54C8C93 FOREIGN KEY (type_id) REFERENCES chargement (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76EC10E96A FOREIGN KEY (classeur_id) REFERENCES classeur (id)');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C1C54C8C93 FOREIGN KEY (type_id) REFERENCES revenu (id)');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_66675840EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE piste ADD type_avenant INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_2FE5CE5EA417747');
        $this->addSql('ALTER TABLE chargement_pour_prime DROP FOREIGN KEY FK_8065D3CAC54C8C93');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76EA417747');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76EC10E96A');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C1C54C8C93');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_66675840EA417747');
        $this->addSql('DROP TABLE avenant');
        $this->addSql('DROP TABLE chargement_pour_prime');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE revenu_pour_courtier');
        $this->addSql('DROP TABLE tranche');
        $this->addSql('ALTER TABLE piste DROP type_avenant');
    }
}
