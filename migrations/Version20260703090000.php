<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comptabilité du courtier (workspace) — Charges, Dépenses et Documents comptables.
 *
 * 1) Crée les tables `charge_courtier` (référentiel de charges OHADA classe 6 du
 *    cabinet) et `depense_courtier` (sorties de fonds réelles), toutes deux scopées
 *    entreprise (AuditableTrait), qui alimentent les documents comptables du courtier.
 * 2) Ajoute à `roles_en_finance` les trois périmètres d'accès correspondants
 *    (`access_charge`, `access_depense`, `access_document_comptable`). Colonnes de
 *    type Doctrine ARRAY (PHP sérialisé) : ajout en TROIS temps — nullable, puis
 *    remplissage `a:0:{}` (tableau vide sérialisé) des lignes existantes, puis
 *    passage NOT NULL — pour ne pas casser la désérialisation des rôles déjà en base.
 */
final class Version20260703090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Comptabilité courtier : tables charge_courtier & depense_courtier + périmètres d'accès Finances (charges, dépenses, documents comptables).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE charge_courtier (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, code VARCHAR(40) NOT NULL, libelle VARCHAR(255) NOT NULL, compte_ohada VARCHAR(10) NOT NULL, comportement VARCHAR(10) NOT NULL, periodicite VARCHAR(15) NOT NULL, montant_budgete_mensuel NUMERIC(12, 2) DEFAULT NULL, actif TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3D779FE5A4AEAFEA (entreprise_id), INDEX IDX_3D779FE5EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE depense_courtier (id INT AUTO_INCREMENT NOT NULL, charge_id INT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, date_depense DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', montant NUMERIC(12, 2) NOT NULL, taux_tva NUMERIC(5, 2) DEFAULT \'0.00\' NOT NULL, beneficiaire VARCHAR(255) DEFAULT NULL, reference VARCHAR(40) DEFAULT NULL, moyen_paiement VARCHAR(15) NOT NULL, statut VARCHAR(15) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4BFA9D5D55284914 (charge_id), INDEX IDX_4BFA9D5DA4AEAFEA (entreprise_id), INDEX IDX_4BFA9D5DEA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE charge_courtier ADD CONSTRAINT FK_3D779FE5A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE charge_courtier ADD CONSTRAINT FK_3D779FE5EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE depense_courtier ADD CONSTRAINT FK_4BFA9D5D55284914 FOREIGN KEY (charge_id) REFERENCES charge_courtier (id)');
        $this->addSql('ALTER TABLE depense_courtier ADD CONSTRAINT FK_4BFA9D5DA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE depense_courtier ADD CONSTRAINT FK_4BFA9D5DEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        // Périmètres d'accès : en 3 temps pour les rôles existants (type Doctrine ARRAY).
        $this->addSql('ALTER TABLE roles_en_finance ADD access_charge LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', ADD access_depense LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', ADD access_document_comptable LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql("UPDATE roles_en_finance SET access_charge = 'a:0:{}', access_depense = 'a:0:{}', access_document_comptable = 'a:0:{}'");
        $this->addSql('ALTER TABLE roles_en_finance CHANGE access_charge access_charge LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', CHANGE access_depense access_depense LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', CHANGE access_document_comptable access_document_comptable LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE depense_courtier DROP FOREIGN KEY FK_4BFA9D5D55284914');
        $this->addSql('ALTER TABLE depense_courtier DROP FOREIGN KEY FK_4BFA9D5DA4AEAFEA');
        $this->addSql('ALTER TABLE depense_courtier DROP FOREIGN KEY FK_4BFA9D5DEA417747');
        $this->addSql('ALTER TABLE charge_courtier DROP FOREIGN KEY FK_3D779FE5A4AEAFEA');
        $this->addSql('ALTER TABLE charge_courtier DROP FOREIGN KEY FK_3D779FE5EA417747');
        $this->addSql('DROP TABLE depense_courtier');
        $this->addSql('DROP TABLE charge_courtier');
        $this->addSql('ALTER TABLE roles_en_finance DROP access_charge, DROP access_depense, DROP access_document_comptable');
    }
}
