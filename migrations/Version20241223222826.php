<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241223222826 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant ADD cotation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_2FE5CE55D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('CREATE INDEX IDX_2FE5CE55D14FAF0 ON avenant (cotation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_2FE5CE55D14FAF0');
        $this->addSql('DROP INDEX IDX_2FE5CE55D14FAF0 ON avenant');
        $this->addSql('ALTER TABLE avenant DROP cotation_id');
    }
}
