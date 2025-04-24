<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250424135418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_finance DROP access_compte_bancaire, DROP access_taxe, DROP access_type_revenu, DROP access_tranche, DROP access_type_chargement, DROP access_note, DROP access_paiement, DROP access_bordereau, DROP access_revenu_courtier');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_finance ADD access_compte_bancaire INT NOT NULL, ADD access_taxe INT NOT NULL, ADD access_type_revenu INT NOT NULL, ADD access_tranche INT NOT NULL, ADD access_type_chargement INT NOT NULL, ADD access_note INT NOT NULL, ADD access_paiement INT NOT NULL, ADD access_bordereau INT NOT NULL, ADD access_revenu_courtier INT NOT NULL');
    }
}
