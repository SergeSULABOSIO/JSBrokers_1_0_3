<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250208233206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD taxe_facturee_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E666F0C8AD5 FOREIGN KEY (taxe_facturee_id) REFERENCES taxe (id)');
        $this->addSql('CREATE INDEX IDX_23A0E666F0C8AD5 ON article (taxe_facturee_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E666F0C8AD5');
        $this->addSql('DROP INDEX IDX_23A0E666F0C8AD5 ON article');
        $this->addSql('ALTER TABLE article DROP taxe_facturee_id');
    }
}
