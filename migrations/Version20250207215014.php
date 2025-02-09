<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250207215014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE taxe ADD article_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE97294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('CREATE INDEX IDX_56322FE97294869C ON taxe (article_id)');
        $this->addSql('ALTER TABLE type_revenu ADD article_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7D7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('CREATE INDEX IDX_5E74AB7D7294869C ON type_revenu (article_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE97294869C');
        $this->addSql('DROP INDEX IDX_56322FE97294869C ON taxe');
        $this->addSql('ALTER TABLE taxe DROP article_id');
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7D7294869C');
        $this->addSql('DROP INDEX IDX_5E74AB7D7294869C ON type_revenu');
        $this->addSql('ALTER TABLE type_revenu DROP article_id');
    }
}
