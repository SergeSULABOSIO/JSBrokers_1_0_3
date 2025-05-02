<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250502085936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_production ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_production ADD CONSTRAINT FK_D0384CFEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_D0384CFEA417747 ON roles_en_production (invite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_production DROP FOREIGN KEY FK_D0384CFEA417747');
        $this->addSql('DROP INDEX IDX_D0384CFEA417747 ON roles_en_production');
        $this->addSql('ALTER TABLE roles_en_production DROP invite_id');
    }
}
