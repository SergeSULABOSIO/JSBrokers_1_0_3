<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PIÈCE « PARTAGÉE AVEC LE CLIENT » (chantier 18, option (c) de Serge, 2026-10-22) : seule une
 * pièce cochée sort par le relevé de compte public. Toutes les pièces existantes naissent
 * NON partagées — rien ne sort sans décision explicite.
 */
final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Document : case « Partagée avec le client » (décochée pour toutes les pièces existantes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD partagee_avec_le_client TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP partagee_avec_le_client');
    }
}
