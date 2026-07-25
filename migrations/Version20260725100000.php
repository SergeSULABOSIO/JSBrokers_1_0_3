<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tranche.pourcentage : passage de la convention FRACTION (1.0 = 100 %) à la
 * convention POURCENTAGE en POINTS (100 = 100 % ; 33,33 = 33,33 %).
 *
 * Le stockage devient celui qu'attendent déjà l'écran, l'import bordereau et
 * l'éditeur de facture ; les calculs dérivent la fraction via Tranche::getFraction().
 *
 * Données : seules les lignes encore en fraction (valeur ≤ 1) sont multipliées
 * par 100 (1.0 → 100 ; 0,3333 → 33,33). Les lignes déjà en points (import
 * bordereau, valeur > 1 comme 100) sont laissées telles quelles. Pas de DDL :
 * la colonne reste un `double`. Irréversible : l'état initial était MIXTE
 * (fraction + points), il ne peut pas être reconstruit exactement.
 */
final class Version20260725100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tranche.pourcentage : fraction (1.0) → points (100) ; les valeurs ≤ 1 sont ×100';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE tranche SET pourcentage = pourcentage * 100 WHERE pourcentage IS NOT NULL AND pourcentage <= 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Normalisation à sens unique : l\'état initial (fraction + points mélangés) ne peut pas être reconstruit.'
        );
    }
}
