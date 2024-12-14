<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214200906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau ADD facture_commission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C56173CA5452 FOREIGN KEY (facture_commission_id) REFERENCES facture_commission (id)');
        $this->addSql('CREATE INDEX IDX_F7B4C56173CA5452 ON bordereau (facture_commission_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C56173CA5452');
        $this->addSql('DROP INDEX IDX_F7B4C56173CA5452 ON bordereau');
        $this->addSql('ALTER TABLE bordereau DROP facture_commission_id');
    }
}
