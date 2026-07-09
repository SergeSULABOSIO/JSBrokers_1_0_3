<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jeton d'accès public au SOA client (soa_acces_token).
 * NB : le diff auto incluait une dérive de schéma sans rapport (renommages
 * d'index CRM & co) volontairement écartée de cette migration.
 */
final class Version20260709104011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Crée la table soa_acces_token (accès public tokenisé au relevé de compte client)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE soa_acces_token (id INT AUTO_INCREMENT NOT NULL, client_id INT NOT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_accessed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', access_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_68138DCC19EB6921 (client_id), INDEX IDX_68138DCCA4AEAFEA (entreprise_id), INDEX IDX_68138DCCEA417747 (invite_id), UNIQUE INDEX uniq_soa_acces_token (token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE soa_acces_token ADD CONSTRAINT FK_68138DCC19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE soa_acces_token ADD CONSTRAINT FK_68138DCCA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE soa_acces_token ADD CONSTRAINT FK_68138DCCEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE soa_acces_token DROP FOREIGN KEY FK_68138DCC19EB6921');
        $this->addSql('ALTER TABLE soa_acces_token DROP FOREIGN KEY FK_68138DCCA4AEAFEA');
        $this->addSql('ALTER TABLE soa_acces_token DROP FOREIGN KEY FK_68138DCCEA417747');
        $this->addSql('DROP TABLE soa_acces_token');
    }
}
