<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241225165524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invite_entreprise DROP FOREIGN KEY FK_B3B918BA4AEAFEA');
        $this->addSql('ALTER TABLE invite_entreprise DROP FOREIGN KEY FK_B3B918BEA417747');
        $this->addSql('DROP TABLE invite_entreprise');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invite_entreprise (invite_id INT NOT NULL, entreprise_id INT NOT NULL, INDEX IDX_B3B918BEA417747 (invite_id), INDEX IDX_B3B918BA4AEAFEA (entreprise_id), PRIMARY KEY(invite_id, entreprise_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE invite_entreprise ADD CONSTRAINT FK_B3B918BA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invite_entreprise ADD CONSTRAINT FK_B3B918BEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id) ON DELETE CASCADE');
    }
}
