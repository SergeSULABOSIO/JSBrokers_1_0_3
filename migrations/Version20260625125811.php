<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625125811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console : tables charge (types de charges OHADA classe 6) et depense (sorties de fonds rattachées à une charge).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE charge (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, libelle VARCHAR(255) NOT NULL, compte_ohada VARCHAR(10) NOT NULL, destination VARCHAR(20) NOT NULL, comportement VARCHAR(10) NOT NULL, periodicite VARCHAR(15) NOT NULL, montant_budgete_mensuel NUMERIC(12, 2) DEFAULT NULL, actif TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_556BA43477153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE depense (id INT AUTO_INCREMENT NOT NULL, charge_id INT NOT NULL, date_depense DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', montant NUMERIC(12, 2) NOT NULL, devise VARCHAR(3) NOT NULL, beneficiaire VARCHAR(255) DEFAULT NULL, reference VARCHAR(40) DEFAULT NULL, moyen_paiement VARCHAR(15) NOT NULL, statut VARCHAR(15) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3405975755284914 (charge_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_3405975755284914 FOREIGN KEY (charge_id) REFERENCES charge (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_3405975755284914');
        $this->addSql('DROP TABLE depense');
        $this->addSql('DROP TABLE charge');
    }
}
