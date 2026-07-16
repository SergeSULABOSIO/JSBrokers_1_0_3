<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Signalement du paiement des primes d'assurance (marché où l'assureur encaisse) :
 * table paiement_prime (trace déclarative par tranche — jamais la trésorerie du
 * courtier) + rattachement des preuves documentaires (document.paiement_prime_id).
 *
 * NB : la diff auto embarquait des renommages d'index sans rapport (dérive CRM,
 * coupon…) — volontairement écartés, cette migration est ciblée.
 */
final class Version20260716113525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PaiementPrime : trace des paiements de primes par tranche + preuves documentaires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE paiement_prime (id INT AUTO_INCREMENT NOT NULL, tranche_id INT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, paid_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', montant DOUBLE PRECISION NOT NULL, reference VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_FCD3EA48B76F6B31 (tranche_id), INDEX IDX_FCD3EA48A4AEAFEA (entreprise_id), INDEX IDX_FCD3EA48EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE paiement_prime ADD CONSTRAINT FK_FCD3EA48B76F6B31 FOREIGN KEY (tranche_id) REFERENCES tranche (id)');
        $this->addSql('ALTER TABLE paiement_prime ADD CONSTRAINT FK_FCD3EA48A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE paiement_prime ADD CONSTRAINT FK_FCD3EA48EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE document ADD paiement_prime_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A38381BC FOREIGN KEY (paiement_prime_id) REFERENCES paiement_prime (id)');
        $this->addSql('CREATE INDEX IDX_D8698A76A38381BC ON document (paiement_prime_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A38381BC');
        $this->addSql('DROP INDEX IDX_D8698A76A38381BC ON document');
        $this->addSql('ALTER TABLE document DROP paiement_prime_id');
        $this->addSql('ALTER TABLE paiement_prime DROP FOREIGN KEY FK_FCD3EA48B76F6B31');
        $this->addSql('ALTER TABLE paiement_prime DROP FOREIGN KEY FK_FCD3EA48A4AEAFEA');
        $this->addSql('ALTER TABLE paiement_prime DROP FOREIGN KEY FK_FCD3EA48EA417747');
        $this->addSql('DROP TABLE paiement_prime');
    }
}
