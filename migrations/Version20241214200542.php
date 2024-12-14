<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214200542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facture_commission ADD invite_id INT DEFAULT NULL, ADD created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD reference VARCHAR(255) NOT NULL, ADD montant_du DOUBLE PRECISION NOT NULL, ADD debiteur VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE facture_commission ADD CONSTRAINT FK_F67AEE5CEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_F67AEE5CEA417747 ON facture_commission (invite_id)');
        $this->addSql('ALTER TABLE tranche ADD facture_commission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_6667584073CA5452 FOREIGN KEY (facture_commission_id) REFERENCES facture_commission (id)');
        $this->addSql('CREATE INDEX IDX_6667584073CA5452 ON tranche (facture_commission_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facture_commission DROP FOREIGN KEY FK_F67AEE5CEA417747');
        $this->addSql('DROP INDEX IDX_F67AEE5CEA417747 ON facture_commission');
        $this->addSql('ALTER TABLE facture_commission DROP invite_id, DROP created_at, DROP updated_at, DROP reference, DROP montant_du, DROP debiteur');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_6667584073CA5452');
        $this->addSql('DROP INDEX IDX_6667584073CA5452 ON tranche');
        $this->addSql('ALTER TABLE tranche DROP facture_commission_id');
    }
}
