<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convention UNIQUE « pourcentage » : les 5 derniers champs de taux encore
 * stockés en FRACTION passent en POINTS (16 = 16 %), comme la tranche et les
 * taxes. Calculs via les accesseurs getFraction() des entités.
 *
 *  - risque.pourcentage_commission_specifique_ht
 *  - partenaire.part
 *  - condition_partage.taux
 *  - revenu_pour_courtier.taux_exceptionel
 *  - type_revenu.pourcentage
 *
 * Données actuelles 100 % fractionnaires (toutes ≤ 1, vérifié) → ×100 propre.
 * Garde `<= 1` par sécurité/idempotence. Pas de DDL (colonnes `double`/`float`).
 * Irréversible (les taux légitimes ≤ 1 % seraient indiscernables au retour).
 */
final class Version20260725110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taux (Risque/Partenaire/ConditionPartage/RevenuPourCourtier/TypeRevenu) : fraction → points (×100)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE risque SET pourcentage_commission_specifique_ht = pourcentage_commission_specifique_ht * 100 WHERE pourcentage_commission_specifique_ht IS NOT NULL AND pourcentage_commission_specifique_ht <= 1');
        $this->addSql('UPDATE partenaire SET part = part * 100 WHERE part IS NOT NULL AND part <= 1');
        $this->addSql('UPDATE condition_partage SET taux = taux * 100 WHERE taux IS NOT NULL AND taux <= 1');
        $this->addSql('UPDATE revenu_pour_courtier SET taux_exceptionel = taux_exceptionel * 100 WHERE taux_exceptionel IS NOT NULL AND taux_exceptionel <= 1');
        $this->addSql('UPDATE type_revenu SET pourcentage = pourcentage * 100 WHERE pourcentage IS NOT NULL AND pourcentage <= 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Normalisation à sens unique des taux (fraction → points).'
        );
    }
}
