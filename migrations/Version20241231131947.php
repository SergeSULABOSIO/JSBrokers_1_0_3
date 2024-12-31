<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241231131947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCEF1A9D84');
        $this->addSql('DROP INDEX IDX_D73D1ECCEF1A9D84 ON offre_indemnisation_sinistre');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP notification_id, DROP created_at, DROP updated_at');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD notification_id INT DEFAULT NULL, ADD created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCEF1A9D84 FOREIGN KEY (notification_id) REFERENCES notification_sinistre (id)');
        $this->addSql('CREATE INDEX IDX_D73D1ECCEF1A9D84 ON offre_indemnisation_sinistre (notification_id)');
    }
}
