<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250105132300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE condition_partage ADD critere_risque INT NOT NULL');
        $this->addSql('ALTER TABLE risque ADD condition_partage_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risque ADD CONSTRAINT FK_20230D2426164BCB FOREIGN KEY (condition_partage_id) REFERENCES condition_partage (id)');
        $this->addSql('CREATE INDEX IDX_20230D2426164BCB ON risque (condition_partage_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE condition_partage DROP critere_risque');
        $this->addSql('ALTER TABLE risque DROP FOREIGN KEY FK_20230D2426164BCB');
        $this->addSql('DROP INDEX IDX_20230D2426164BCB ON risque');
        $this->addSql('ALTER TABLE risque DROP condition_partage_id');
    }
}
