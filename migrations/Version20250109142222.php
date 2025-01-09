<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250109142222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C5617F2DEE08');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E7F2DEE08');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_666758407F2DEE08');
        $this->addSql('CREATE TABLE piste_partenaire (piste_id INT NOT NULL, partenaire_id INT NOT NULL, INDEX IDX_6110D3B3C34065BC (piste_id), INDEX IDX_6110D3B398DE13AC (partenaire_id), PRIMARY KEY(piste_id, partenaire_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE piste_partenaire ADD CONSTRAINT FK_6110D3B3C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piste_partenaire ADD CONSTRAINT FK_6110D3B398DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facture DROP FOREIGN KEY FK_FE866410EA417747');
        $this->addSql('DROP TABLE facture');
        $this->addSql('DROP INDEX IDX_F7B4C5617F2DEE08 ON bordereau');
        $this->addSql('ALTER TABLE bordereau DROP facture_id');
        $this->addSql('DROP INDEX IDX_B1DC7A1E7F2DEE08 ON paiement');
        $this->addSql('ALTER TABLE paiement DROP facture_id');
        $this->addSql('DROP INDEX IDX_666758407F2DEE08 ON tranche');
        $this->addSql('ALTER TABLE tranche DROP facture_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE facture (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', reference VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, montant_du DOUBLE PRECISION NOT NULL, debiteur VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_FE866410EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE facture ADD CONSTRAINT FK_FE866410EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE piste_partenaire DROP FOREIGN KEY FK_6110D3B3C34065BC');
        $this->addSql('ALTER TABLE piste_partenaire DROP FOREIGN KEY FK_6110D3B398DE13AC');
        $this->addSql('DROP TABLE piste_partenaire');
        $this->addSql('ALTER TABLE bordereau ADD facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C5617F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id)');
        $this->addSql('CREATE INDEX IDX_F7B4C5617F2DEE08 ON bordereau (facture_id)');
        $this->addSql('ALTER TABLE paiement ADD facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E7F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id)');
        $this->addSql('CREATE INDEX IDX_B1DC7A1E7F2DEE08 ON paiement (facture_id)');
        $this->addSql('ALTER TABLE tranche ADD facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_666758407F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id)');
        $this->addSql('CREATE INDEX IDX_666758407F2DEE08 ON tranche (facture_id)');
    }
}
