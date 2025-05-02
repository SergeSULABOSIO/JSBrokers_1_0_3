<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250502150310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_administration ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_administration ADD CONSTRAINT FK_E4BAB4BAEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_E4BAB4BAEA417747 ON roles_en_administration (invite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_administration DROP FOREIGN KEY FK_E4BAB4BAEA417747');
        $this->addSql('DROP INDEX IDX_E4BAB4BAEA417747 ON roles_en_administration');
        $this->addSql('ALTER TABLE roles_en_administration DROP invite_id');
    }
}
