<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529074622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau ADD montant_payable_now DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau ADD montant_com_ht_payable_now DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau ADD montant_taxe_payable_now DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau DROP montant_payable_now');
        $this->addSql('ALTER TABLE bordereau DROP montant_com_ht_payable_now');
        $this->addSql('ALTER TABLE bordereau DROP montant_taxe_payable_now');
    }
}
