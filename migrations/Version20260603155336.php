<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603155336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant ADD piste_de_renouvellement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_2FE5CE55281C6C1 FOREIGN KEY (piste_de_renouvellement_id) REFERENCES piste (id)');
        $this->addSql('CREATE INDEX IDX_2FE5CE55281C6C1 ON avenant (piste_de_renouvellement_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_2FE5CE55281C6C1');
        $this->addSql('DROP INDEX IDX_2FE5CE55281C6C1 ON avenant');
        $this->addSql('ALTER TABLE avenant DROP piste_de_renouvellement_id');
    }
}
