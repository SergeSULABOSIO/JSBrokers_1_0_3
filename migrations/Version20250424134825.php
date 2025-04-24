<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250424134825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE roles_en_finance (id INT AUTO_INCREMENT NOT NULL, accesss_monnaie INT NOT NULL, access_compte_bancaire INT NOT NULL, access_taxe INT NOT NULL, access_type_revenu INT NOT NULL, access_tranche INT NOT NULL, access_type_chargement INT NOT NULL, access_note INT NOT NULL, access_paiement INT NOT NULL, access_bordereau INT NOT NULL, access_revenu_courtier INT NOT NULL, nom VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE roles_en_finance');
    }
}
