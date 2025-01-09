<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250109130445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_8CAA04C1C54C8C93 ON revenu_pour_courtier');
        $this->addSql('ALTER TABLE revenu_pour_courtier CHANGE type_id type_revenu_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C120F3EE6A FOREIGN KEY (type_revenu_id) REFERENCES type_revenu (id)');
        $this->addSql('CREATE INDEX IDX_8CAA04C120F3EE6A ON revenu_pour_courtier (type_revenu_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C120F3EE6A');
        $this->addSql('DROP INDEX IDX_8CAA04C120F3EE6A ON revenu_pour_courtier');
        $this->addSql('ALTER TABLE revenu_pour_courtier CHANGE type_revenu_id type_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8CAA04C1C54C8C93 ON revenu_pour_courtier (type_id)');
    }
}
