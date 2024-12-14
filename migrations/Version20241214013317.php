<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241214013317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piste ADD risque_id INT DEFAULT NULL, ADD created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E250774ECC2413 FOREIGN KEY (risque_id) REFERENCES risque (id)');
        $this->addSql('CREATE INDEX IDX_59E250774ECC2413 ON piste (risque_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E250774ECC2413');
        $this->addSql('DROP INDEX IDX_59E250774ECC2413 ON piste');
        $this->addSql('ALTER TABLE piste DROP risque_id, DROP created_at, DROP updated_at');
    }
}
