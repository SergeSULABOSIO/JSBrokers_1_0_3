<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiscalité — photo TVA collectée/déductible sur le reversement.
 *
 * Permet l'écriture comptable détaillée du reversement de TVA (D 443 collectée /
 * C 445 déductible / C trésorerie net, + 4441 État TVA due pour un éventuel
 * solde partiel). Colonnes additives, défaut 0 (les reversements existants
 * conservent l'écriture simple D 443 / C trésorerie).
 */
final class Version20260630170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiscalité : tva_collectee / tva_deductible sur reglement_taxe (écriture TVA détaillée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE reglement_taxe
            ADD tva_collectee NUMERIC(14, 2) DEFAULT '0' NOT NULL,
            ADD tva_deductible NUMERIC(14, 2) DEFAULT '0' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reglement_taxe DROP tva_collectee, DROP tva_deductible');
    }
}
