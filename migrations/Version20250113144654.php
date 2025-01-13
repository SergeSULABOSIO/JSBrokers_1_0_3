<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250113144654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note ADD autoritefiscale_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14D8FB132F FOREIGN KEY (autoritefiscale_id) REFERENCES autorite_fiscale (id)');
        $this->addSql('CREATE INDEX IDX_CFBDFA14D8FB132F ON note (autoritefiscale_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14D8FB132F');
        $this->addSql('DROP INDEX IDX_CFBDFA14D8FB132F ON note');
        $this->addSql('ALTER TABLE note DROP autoritefiscale_id');
    }
}
