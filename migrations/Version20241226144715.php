<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241226144715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD notification_sinistre_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCF4F2559E FOREIGN KEY (notification_sinistre_id) REFERENCES notification_sinistre (id)');
        $this->addSql('CREATE INDEX IDX_D73D1ECCF4F2559E ON offre_indemnisation_sinistre (notification_sinistre_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCF4F2559E');
        $this->addSql('DROP INDEX IDX_D73D1ECCF4F2559E ON offre_indemnisation_sinistre');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP notification_sinistre_id');
    }
}
