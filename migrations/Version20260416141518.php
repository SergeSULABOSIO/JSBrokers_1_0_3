<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416141518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assureur CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE autorite_fiscale CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE avenant CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE chargement CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE chargement_pour_prime CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE classeur CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE compte_bancaire CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE condition_partage CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cotation CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE groupe CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7EA417747');
        $this->addSql('DROP INDEX IDX_C7E210D7EA417747 ON invite');
        $this->addSql('ALTER TABLE invite DROP invite_id, CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE modele_piece_sinistre CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE monnaie CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification_sinistre CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE partenaire CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE piece_sinistre CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE piste CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE revenu_pour_courtier CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risque CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_administration CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_finance CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_marketing CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_production CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_sinistre CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE taxe CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche CHANGE invite_id invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE type_revenu CHANGE invite_id invite_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE assureur CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE autorite_fiscale CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE avenant CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE bordereau CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE chargement CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE chargement_pour_prime CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE classeur CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE client CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE compte_bancaire CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE condition_partage CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE contact CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE cotation CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE document CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE feedback CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE groupe CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE invite ADD invite_id INT NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_C7E210D7EA417747 ON invite (invite_id)');
        $this->addSql('ALTER TABLE modele_piece_sinistre CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE monnaie CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE note CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE notification_sinistre CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE paiement CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE partenaire CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE piece_sinistre CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE piste CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE revenu_pour_courtier CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE risque CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_administration CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_finance CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_marketing CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_production CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_sinistre CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE tache CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE taxe CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE tranche CHANGE invite_id invite_id INT NOT NULL');
        $this->addSql('ALTER TABLE type_revenu CHANGE invite_id invite_id INT NOT NULL');
    }
}
