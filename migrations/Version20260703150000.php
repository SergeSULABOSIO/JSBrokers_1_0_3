<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fournisseurs du courtier (workspace, module Finances).
 *
 * 1) Crée la table `fournisseur` (référentiel achats / services généraux du cabinet,
 *    scopée entreprise via AuditableTrait) avec dossier documentaire (contrats,
 *    agréments…) : FK nullable `fournisseur_id` sur `document`.
 * 2) Relie les dépenses aux fournisseurs enregistrés : FK nullable `fournisseur_id`
 *    sur `depense_courtier` (le champ libre `beneficiaire` reste disponible pour
 *    les bénéficiaires occasionnels).
 * 3) Ajoute à `roles_en_finance` le périmètre `access_fournisseur` (type Doctrine
 *    ARRAY — ajout en TROIS temps : nullable, remplissage `a:0:{}`, NOT NULL —
 *    pour ne pas casser la désérialisation des rôles existants).
 */
final class Version20260703150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Fournisseurs du courtier : table fournisseur + dossier documentaire + lien dépenses + périmètre d'accès Finances.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fournisseur (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, personne_contact VARCHAR(255) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, rccm VARCHAR(40) DEFAULT NULL, numimpot VARCHAR(40) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, actif TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_369ECA32A4AEAFEA (entreprise_id), INDEX IDX_369ECA32EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA32A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA32EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        $this->addSql('ALTER TABLE document ADD fournisseur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('CREATE INDEX IDX_D8698A76670C757F ON document (fournisseur_id)');

        $this->addSql('ALTER TABLE depense_courtier ADD fournisseur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE depense_courtier ADD CONSTRAINT FK_4BFA9D5D670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('CREATE INDEX IDX_4BFA9D5D670C757F ON depense_courtier (fournisseur_id)');

        // Périmètre d'accès : en 3 temps pour les rôles existants (type Doctrine ARRAY).
        $this->addSql('ALTER TABLE roles_en_finance ADD access_fournisseur LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql("UPDATE roles_en_finance SET access_fournisseur = 'a:0:{}'");
        $this->addSql('ALTER TABLE roles_en_finance CHANGE access_fournisseur access_fournisseur LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles_en_finance DROP access_fournisseur');
        $this->addSql('ALTER TABLE depense_courtier DROP FOREIGN KEY FK_4BFA9D5D670C757F');
        $this->addSql('DROP INDEX IDX_4BFA9D5D670C757F ON depense_courtier');
        $this->addSql('ALTER TABLE depense_courtier DROP fournisseur_id');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76670C757F');
        $this->addSql('DROP INDEX IDX_D8698A76670C757F ON document');
        $this->addSql('ALTER TABLE document DROP fournisseur_id');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA32A4AEAFEA');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA32EA417747');
        $this->addSql('DROP TABLE fournisseur');
    }
}
