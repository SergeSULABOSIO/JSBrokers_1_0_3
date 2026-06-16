<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616134046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Risque.description : varchar(255) -> LONGTEXT pour héberger les descriptions détaillées (2 paragraphes).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risque CHANGE description description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risque CHANGE description description VARCHAR(255) DEFAULT NULL');
    }
}
