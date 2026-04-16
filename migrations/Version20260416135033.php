<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416135033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entreprise DROP FOREIGN KEY FK_D19FA60EA417747');
        $this->addSql('ALTER TABLE entreprise DROP FOREIGN KEY FK_D19FA60A4AEAFEA');
        $this->addSql('DROP INDEX IDX_D19FA60A4AEAFEA ON entreprise');
        $this->addSql('DROP INDEX IDX_D19FA60EA417747 ON entreprise');
        $this->addSql('ALTER TABLE entreprise ADD utilisateur_id INT NOT NULL, DROP entreprise_id, DROP invite_id, CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE entreprise ADD CONSTRAINT FK_D19FA60FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('CREATE INDEX IDX_D19FA60FB88E14F ON entreprise (utilisateur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entreprise DROP FOREIGN KEY FK_D19FA60FB88E14F');
        $this->addSql('DROP INDEX IDX_D19FA60FB88E14F ON entreprise');
        $this->addSql('ALTER TABLE entreprise ADD invite_id INT NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE utilisateur_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE entreprise ADD CONSTRAINT FK_D19FA60EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE entreprise ADD CONSTRAINT FK_D19FA60A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_D19FA60A4AEAFEA ON entreprise (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_D19FA60EA417747 ON entreprise (invite_id)');
    }
}
