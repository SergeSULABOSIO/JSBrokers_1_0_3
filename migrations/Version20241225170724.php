<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241225170724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invite ADD entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_C7E210D7A4AEAFEA ON invite (entreprise_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7A4AEAFEA');
        $this->addSql('DROP INDEX IDX_C7E210D7A4AEAFEA ON invite');
        $this->addSql('ALTER TABLE invite DROP entreprise_id');
    }
}
