<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214013600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piste ADD invite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E25077EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_59E25077EA417747 ON piste (invite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E25077EA417747');
        $this->addSql('DROP INDEX IDX_59E25077EA417747 ON piste');
        $this->addSql('ALTER TABLE piste DROP invite_id');
    }
}
