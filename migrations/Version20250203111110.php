<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250203111110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE client_partenaire (client_id INT NOT NULL, partenaire_id INT NOT NULL, INDEX IDX_A2AB9E3219EB6921 (client_id), INDEX IDX_A2AB9E3298DE13AC (partenaire_id), PRIMARY KEY(client_id, partenaire_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE client_partenaire ADD CONSTRAINT FK_A2AB9E3219EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE client_partenaire ADD CONSTRAINT FK_A2AB9E3298DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client_partenaire DROP FOREIGN KEY FK_A2AB9E3219EB6921');
        $this->addSql('ALTER TABLE client_partenaire DROP FOREIGN KEY FK_A2AB9E3298DE13AC');
        $this->addSql('DROP TABLE client_partenaire');
    }
}
