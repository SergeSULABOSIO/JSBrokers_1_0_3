<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241210142540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE taxe ADD entreprise_id INT DEFAULT NULL, ADD code VARCHAR(5) NOT NULL, ADD redevable INT NOT NULL, DROP nom, DROP payableparcourtier');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE9A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_56322FE9A4AEAFEA ON taxe (entreprise_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE9A4AEAFEA');
        $this->addSql('DROP INDEX IDX_56322FE9A4AEAFEA ON taxe');
        $this->addSql('ALTER TABLE taxe ADD nom VARCHAR(255) NOT NULL, ADD payableparcourtier TINYINT(1) NOT NULL, DROP entreprise_id, DROP code, DROP redevable');
    }
}
