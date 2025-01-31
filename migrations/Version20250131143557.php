<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250131143557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE condition_partage ADD piste_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE condition_partage ADD CONSTRAINT FK_CF012D1FC34065BC FOREIGN KEY (piste_id) REFERENCES piste (id)');
        $this->addSql('CREATE INDEX IDX_CF012D1FC34065BC ON condition_partage (piste_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE condition_partage DROP FOREIGN KEY FK_CF012D1FC34065BC');
        $this->addSql('DROP INDEX IDX_CF012D1FC34065BC ON condition_partage');
        $this->addSql('ALTER TABLE condition_partage DROP piste_id');
    }
}
