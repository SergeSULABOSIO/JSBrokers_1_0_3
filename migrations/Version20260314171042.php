<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260314171042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD quantite DOUBLE PRECISION DEFAULT NULL, DROP nom');
        $this->addSql('ALTER TABLE roles_en_finance CHANGE access_type_revenu access_type_revenu LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql('ALTER TABLE roles_en_marketing CHANGE access_piste access_piste LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', CHANGE access_tache access_tache LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\', CHANGE access_feedback access_feedback LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD nom VARCHAR(255) NOT NULL, DROP quantite');
        $this->addSql('ALTER TABLE roles_en_finance CHANGE access_type_revenu access_type_revenu LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql('ALTER TABLE roles_en_marketing CHANGE access_piste access_piste LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', CHANGE access_tache access_tache LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\', CHANGE access_feedback access_feedback LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\'');
    }
}
