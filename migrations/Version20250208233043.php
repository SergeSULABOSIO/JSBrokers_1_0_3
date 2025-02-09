<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250208233043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E6636D06393');
        $this->addSql('DROP INDEX IDX_23A0E6636D06393 ON article');
        $this->addSql('ALTER TABLE article CHANGE taxes_id revenu_facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E6629BA9F14 FOREIGN KEY (revenu_facture_id) REFERENCES revenu_pour_courtier (id)');
        $this->addSql('CREATE INDEX IDX_23A0E6629BA9F14 ON article (revenu_facture_id)');
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE926ED0855');
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE97294869C');
        $this->addSql('DROP INDEX IDX_56322FE926ED0855 ON taxe');
        $this->addSql('DROP INDEX IDX_56322FE97294869C ON taxe');
        $this->addSql('ALTER TABLE taxe DROP note_id, DROP article_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E6629BA9F14');
        $this->addSql('DROP INDEX IDX_23A0E6629BA9F14 ON article');
        $this->addSql('ALTER TABLE article CHANGE revenu_facture_id taxes_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E6636D06393 FOREIGN KEY (taxes_id) REFERENCES taxe (id)');
        $this->addSql('CREATE INDEX IDX_23A0E6636D06393 ON article (taxes_id)');
        $this->addSql('ALTER TABLE taxe ADD note_id INT DEFAULT NULL, ADD article_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE926ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE97294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('CREATE INDEX IDX_56322FE926ED0855 ON taxe (note_id)');
        $this->addSql('CREATE INDEX IDX_56322FE97294869C ON taxe (article_id)');
    }
}
