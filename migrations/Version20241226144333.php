<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241226144333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCEA417747');
        $this->addSql('DROP INDEX IDX_D73D1ECCEA417747 ON offre_indemnisation_sinistre');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP invite_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_D73D1ECCEA417747 ON offre_indemnisation_sinistre (invite_id)');
    }
}
