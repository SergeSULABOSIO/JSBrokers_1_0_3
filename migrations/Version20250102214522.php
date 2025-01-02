<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250102214522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache ADD offre_indemnisation_sinistre_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207572DDD90D FOREIGN KEY (offre_indemnisation_sinistre_id) REFERENCES offre_indemnisation_sinistre (id)');
        $this->addSql('CREATE INDEX IDX_9387207572DDD90D ON tache (offre_indemnisation_sinistre_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_9387207572DDD90D');
        $this->addSql('DROP INDEX IDX_9387207572DDD90D ON tache');
        $this->addSql('ALTER TABLE tache DROP offre_indemnisation_sinistre_id');
    }
}
