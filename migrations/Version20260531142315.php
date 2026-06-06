<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260531142315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache ADD executor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720758ABD09BB FOREIGN KEY (executor_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_938720758ABD09BB ON tache (executor_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720758ABD09BB');
        $this->addSql('DROP INDEX IDX_938720758ABD09BB ON tache');
        $this->addSql('ALTER TABLE tache DROP executor_id');
    }
}
