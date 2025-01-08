<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250108161239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C56173CA5452');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E73CA5452');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_6667584073CA5452');
        $this->addSql('CREATE TABLE facture (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', reference VARCHAR(255) NOT NULL, montant_du DOUBLE PRECISION NOT NULL, debiteur VARCHAR(255) NOT NULL, INDEX IDX_FE866410EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE facture ADD CONSTRAINT FK_FE866410EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE facture_commission DROP FOREIGN KEY FK_F67AEE5CEA417747');
        $this->addSql('DROP TABLE facture_commission');
        $this->addSql('DROP INDEX IDX_F7B4C56173CA5452 ON bordereau');
        $this->addSql('ALTER TABLE bordereau CHANGE facture_commission_id facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C5617F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id)');
        $this->addSql('CREATE INDEX IDX_F7B4C5617F2DEE08 ON bordereau (facture_id)');
        $this->addSql('DROP INDEX IDX_B1DC7A1E73CA5452 ON paiement');
        $this->addSql('ALTER TABLE paiement CHANGE facture_commission_id facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E7F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id)');
        $this->addSql('CREATE INDEX IDX_B1DC7A1E7F2DEE08 ON paiement (facture_id)');
        $this->addSql('DROP INDEX IDX_6667584073CA5452 ON tranche');
        $this->addSql('ALTER TABLE tranche CHANGE facture_commission_id facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_666758407F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id)');
        $this->addSql('CREATE INDEX IDX_666758407F2DEE08 ON tranche (facture_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C5617F2DEE08');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E7F2DEE08');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_666758407F2DEE08');
        $this->addSql('CREATE TABLE facture_commission (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', reference VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, montant_du DOUBLE PRECISION NOT NULL, debiteur VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_F67AEE5CEA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE facture_commission ADD CONSTRAINT FK_F67AEE5CEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE facture DROP FOREIGN KEY FK_FE866410EA417747');
        $this->addSql('DROP TABLE facture');
        $this->addSql('DROP INDEX IDX_F7B4C5617F2DEE08 ON bordereau');
        $this->addSql('ALTER TABLE bordereau CHANGE facture_id facture_commission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C56173CA5452 FOREIGN KEY (facture_commission_id) REFERENCES facture_commission (id)');
        $this->addSql('CREATE INDEX IDX_F7B4C56173CA5452 ON bordereau (facture_commission_id)');
        $this->addSql('DROP INDEX IDX_B1DC7A1E7F2DEE08 ON paiement');
        $this->addSql('ALTER TABLE paiement CHANGE facture_id facture_commission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E73CA5452 FOREIGN KEY (facture_commission_id) REFERENCES facture_commission (id)');
        $this->addSql('CREATE INDEX IDX_B1DC7A1E73CA5452 ON paiement (facture_commission_id)');
        $this->addSql('DROP INDEX IDX_666758407F2DEE08 ON tranche');
        $this->addSql('ALTER TABLE tranche CHANGE facture_id facture_commission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_6667584073CA5452 FOREIGN KEY (facture_commission_id) REFERENCES facture_commission (id)');
        $this->addSql('CREATE INDEX IDX_6667584073CA5452 ON tranche (facture_commission_id)');
    }
}
