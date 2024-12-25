<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241225225434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7FB88E14F');
        $this->addSql('DROP INDEX IDX_C7E210D7FB88E14F ON invite');
        $this->addSql('ALTER TABLE invite DROP utilisateur_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invite ADD utilisateur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('CREATE INDEX IDX_C7E210D7FB88E14F ON invite (utilisateur_id)');
    }
}
