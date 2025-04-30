<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250430141532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_finance ADD access_type_revenu LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', ADD access_tranche LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', ADD access_type_chargement LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', ADD access_note LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', ADD access_paiement LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', ADD access_bordereau LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', ADD access_revenu LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles_en_finance DROP access_type_revenu, DROP access_tranche, DROP access_type_chargement, DROP access_note, DROP access_paiement, DROP access_bordereau, DROP access_revenu');
    }
}
