<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250501214056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE roles_en_marketing (id INT AUTO_INCREMENT NOT NULL, invite_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, access_piste LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', access_tache LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', access_feedback LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', INDEX IDX_C12A8B85EA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE roles_en_marketing ADD CONSTRAINT FK_C12A8B85EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_marketing DROP FOREIGN KEY FK_C12A8B85EA417747');
        $this->addSql('DROP TABLE roles_en_marketing');
    }
}
