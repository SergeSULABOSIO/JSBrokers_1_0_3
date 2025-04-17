<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250417124425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_sinistre ADD assureur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C280F7E20A FOREIGN KEY (assureur_id) REFERENCES assureur (id)');
        $this->addSql('CREATE INDEX IDX_A0BC42C280F7E20A ON notification_sinistre (assureur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C280F7E20A');
        $this->addSql('DROP INDEX IDX_A0BC42C280F7E20A ON notification_sinistre');
        $this->addSql('ALTER TABLE notification_sinistre DROP assureur_id');
    }
}
