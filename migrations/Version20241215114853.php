<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241215114853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE offre_indemnisation_sinistre (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, notification_id INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', franchise_appliquee DOUBLE PRECISION DEFAULT NULL, montant_payable DOUBLE PRECISION NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', beneficiaire VARCHAR(255) NOT NULL, reference_bancaire VARCHAR(255) DEFAULT NULL, INDEX IDX_D73D1ECCEA417747 (invite_id), INDEX IDX_D73D1ECCEF1A9D84 (notification_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCEF1A9D84 FOREIGN KEY (notification_id) REFERENCES notification_sinistre (id)');
        $this->addSql('ALTER TABLE document ADD offre_indemnisation_sinistre_id INT DEFAULT NULL, ADD paiement_id INT DEFAULT NULL, ADD cotation_id INT DEFAULT NULL, ADD avenant_id INT DEFAULT NULL, ADD tache_id INT DEFAULT NULL, ADD feedback_id INT DEFAULT NULL, ADD client_id INT DEFAULT NULL, ADD bordereau_id INT DEFAULT NULL, ADD compte_bancaire_id INT DEFAULT NULL, ADD entreprise_id INT DEFAULT NULL, ADD piste_id INT DEFAULT NULL, ADD partenaire_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7672DDD90D FOREIGN KEY (offre_indemnisation_sinistre_id) REFERENCES offre_indemnisation_sinistre (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A762A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A765D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7685631A3A FOREIGN KEY (avenant_id) REFERENCES avenant (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D2235D39 FOREIGN KEY (tache_id) REFERENCES tache (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D249A887 FOREIGN KEY (feedback_id) REFERENCES feedback (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7619EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7655D5304E FOREIGN KEY (bordereau_id) REFERENCES bordereau (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76AF1E371E FOREIGN KEY (compte_bancaire_id) REFERENCES compte_bancaire (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7698DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)');
        $this->addSql('CREATE INDEX IDX_D8698A7672DDD90D ON document (offre_indemnisation_sinistre_id)');
        $this->addSql('CREATE INDEX IDX_D8698A762A4C4478 ON document (paiement_id)');
        $this->addSql('CREATE INDEX IDX_D8698A765D14FAF0 ON document (cotation_id)');
        $this->addSql('CREATE INDEX IDX_D8698A7685631A3A ON document (avenant_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76D2235D39 ON document (tache_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76D249A887 ON document (feedback_id)');
        $this->addSql('CREATE INDEX IDX_D8698A7619EB6921 ON document (client_id)');
        $this->addSql('CREATE INDEX IDX_D8698A7655D5304E ON document (bordereau_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76AF1E371E ON document (compte_bancaire_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76A4AEAFEA ON document (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76C34065BC ON document (piste_id)');
        $this->addSql('CREATE INDEX IDX_D8698A7698DE13AC ON document (partenaire_id)');
        $this->addSql('ALTER TABLE invite ADD invite_id INT DEFAULT NULL, ADD nom VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_C7E210D7EA417747 ON invite (invite_id)');
        $this->addSql('ALTER TABLE paiement ADD offre_indemnisation_sinistre_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E72DDD90D FOREIGN KEY (offre_indemnisation_sinistre_id) REFERENCES offre_indemnisation_sinistre (id)');
        $this->addSql('CREATE INDEX IDX_B1DC7A1E72DDD90D ON paiement (offre_indemnisation_sinistre_id)');
        $this->addSql('ALTER TABLE tache ADD closed TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7672DDD90D');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E72DDD90D');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCEA417747');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCEF1A9D84');
        $this->addSql('DROP TABLE offre_indemnisation_sinistre');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A762A4C4478');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A765D14FAF0');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7685631A3A');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D2235D39');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D249A887');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7619EB6921');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7655D5304E');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76AF1E371E');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A4AEAFEA');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76C34065BC');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7698DE13AC');
        $this->addSql('DROP INDEX IDX_D8698A7672DDD90D ON document');
        $this->addSql('DROP INDEX IDX_D8698A762A4C4478 ON document');
        $this->addSql('DROP INDEX IDX_D8698A765D14FAF0 ON document');
        $this->addSql('DROP INDEX IDX_D8698A7685631A3A ON document');
        $this->addSql('DROP INDEX IDX_D8698A76D2235D39 ON document');
        $this->addSql('DROP INDEX IDX_D8698A76D249A887 ON document');
        $this->addSql('DROP INDEX IDX_D8698A7619EB6921 ON document');
        $this->addSql('DROP INDEX IDX_D8698A7655D5304E ON document');
        $this->addSql('DROP INDEX IDX_D8698A76AF1E371E ON document');
        $this->addSql('DROP INDEX IDX_D8698A76A4AEAFEA ON document');
        $this->addSql('DROP INDEX IDX_D8698A76C34065BC ON document');
        $this->addSql('DROP INDEX IDX_D8698A7698DE13AC ON document');
        $this->addSql('ALTER TABLE document DROP offre_indemnisation_sinistre_id, DROP paiement_id, DROP cotation_id, DROP avenant_id, DROP tache_id, DROP feedback_id, DROP client_id, DROP bordereau_id, DROP compte_bancaire_id, DROP entreprise_id, DROP piste_id, DROP partenaire_id');
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7EA417747');
        $this->addSql('DROP INDEX IDX_C7E210D7EA417747 ON invite');
        $this->addSql('ALTER TABLE invite DROP invite_id, DROP nom');
        $this->addSql('DROP INDEX IDX_B1DC7A1E72DDD90D ON paiement');
        $this->addSql('ALTER TABLE paiement DROP offre_indemnisation_sinistre_id');
        $this->addSql('ALTER TABLE tache DROP closed');
    }
}
