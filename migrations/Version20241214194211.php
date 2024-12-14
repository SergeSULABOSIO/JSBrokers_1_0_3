<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214194211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotation ADD piste_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cotation ADD CONSTRAINT FK_996DA944C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id)');
        $this->addSql('CREATE INDEX IDX_996DA944C34065BC ON cotation (piste_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cotation DROP FOREIGN KEY FK_996DA944C34065BC');
        $this->addSql('DROP INDEX IDX_996DA944C34065BC ON cotation');
        $this->addSql('ALTER TABLE cotation DROP piste_id');
    }
}
