<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250207213144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note ADD nature_revenu INT DEFAULT NULL');
        $this->addSql('ALTER TABLE taxe ADD note_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE926ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('CREATE INDEX IDX_56322FE926ED0855 ON taxe (note_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note DROP nature_revenu');
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE926ED0855');
        $this->addSql('DROP INDEX IDX_56322FE926ED0855 ON taxe');
        $this->addSql('ALTER TABLE taxe DROP note_id');
    }
}
