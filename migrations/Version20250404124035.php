<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250404124035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piste ADD avenant_de_base_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E25077DF6F6FEC FOREIGN KEY (avenant_de_base_id) REFERENCES avenant (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_59E25077DF6F6FEC ON piste (avenant_de_base_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E25077DF6F6FEC');
        $this->addSql('DROP INDEX UNIQ_59E25077DF6F6FEC ON piste');
        $this->addSql('ALTER TABLE piste DROP avenant_de_base_id');
    }
}
