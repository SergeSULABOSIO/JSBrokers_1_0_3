<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LES MONTANTS FIGÉS D'UNE NOTE (règle OHADA, décision de Serge du 2026-10-15).
 *
 * SCHÉMA SEULEMENT : les colonnes naissent vides. Une note se fige à sa validation ; les
 * notes déjà validées (et les notes de reprise) se figent par la commande
 * `app:notes:figer` — à blanc d'abord, pour voir l'écart entre le montant recalculé et
 * les paiements, puis `--confirmer`. Une migration ne peut pas les figer : le calcul des
 * montants vit en PHP, dans les services de l'application.
 */
final class Version20261015180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes : montants figés (note et lignes), date de figeage, origine (Joseara ou reprise).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD montant_ht_fige DOUBLE PRECISION DEFAULT NULL, ADD montant_ttc_fige DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE note ADD figee_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD montant_ht_fige DOUBLE PRECISION DEFAULT NULL, ADD montant_ttc_fige DOUBLE PRECISION DEFAULT NULL, ADD libelle_taxe_fige VARCHAR(50) DEFAULT NULL, ADD taux_taxe_fige DOUBLE PRECISION DEFAULT NULL, ADD origine INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP montant_ht_fige, DROP montant_ttc_fige');
        $this->addSql('ALTER TABLE note DROP figee_le, DROP montant_ht_fige, DROP montant_ttc_fige, DROP libelle_taxe_fige, DROP taux_taxe_fige, DROP origine');
    }
}
