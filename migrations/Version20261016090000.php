<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA REMISE DE L'ARCHIVE COMPTABLE D'UN CABINET (conservation dix ans, règle OHADA ;
 * décision de Serge du 2026-10-15). Schéma seulement : les colonnes naissent vides.
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cabinet : date et empreinte de la dernière archive comptable remise.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD archive_comptable_remise_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD archive_comptable_empreinte VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP archive_comptable_remise_le, DROP archive_comptable_empreinte');
    }
}
