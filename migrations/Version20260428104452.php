<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428104452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau ADD reference VARCHAR(255) NOT NULL, ADD periode_debut DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD periode_fin DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD montant_taxe DOUBLE PRECISION DEFAULT NULL, ADD statut INT NOT NULL, CHANGE montant_ttc montant_commission_ht DOUBLE PRECISION NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F7B4C561AEA34913 ON bordereau (reference)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_F7B4C561AEA34913 ON bordereau');
        $this->addSql('ALTER TABLE bordereau DROP reference, DROP periode_debut, DROP periode_fin, DROP paid_at, DROP montant_taxe, DROP statut, CHANGE montant_commission_ht montant_ttc DOUBLE PRECISION NOT NULL');
    }
}
