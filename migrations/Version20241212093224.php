<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241212093224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE revenu ADD entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE revenu ADD CONSTRAINT FK_7DA3C045A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_7DA3C045A4AEAFEA ON revenu (entreprise_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE revenu DROP FOREIGN KEY FK_7DA3C045A4AEAFEA');
        $this->addSql('DROP INDEX IDX_7DA3C045A4AEAFEA ON revenu');
        $this->addSql('ALTER TABLE revenu DROP entreprise_id');
    }
}
