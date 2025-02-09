<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250208095157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article_type_revenu (article_id INT NOT NULL, type_revenu_id INT NOT NULL, INDEX IDX_C4F7C11A7294869C (article_id), INDEX IDX_C4F7C11A20F3EE6A (type_revenu_id), PRIMARY KEY(article_id, type_revenu_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article_type_revenu ADD CONSTRAINT FK_C4F7C11A7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_type_revenu ADD CONSTRAINT FK_C4F7C11A20F3EE6A FOREIGN KEY (type_revenu_id) REFERENCES type_revenu (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article_type_revenu DROP FOREIGN KEY FK_C4F7C11A7294869C');
        $this->addSql('ALTER TABLE article_type_revenu DROP FOREIGN KEY FK_C4F7C11A20F3EE6A');
        $this->addSql('DROP TABLE article_type_revenu');
    }
}
