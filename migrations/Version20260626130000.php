<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM : paramètres éditables (poids du score de santé, seuils de couleur,
 * paramètres d'automatisation) ajoutés au singleton plateforme_parametres.
 * Colonnes JSON nullable → repli sur les valeurs par défaut (zéro régression).
 */
final class Version20260626130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM : plateforme_parametres + crm_health_weights, crm_thresholds, crm_automation (JSON nullable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres ADD crm_health_weights JSON DEFAULT NULL, ADD crm_thresholds JSON DEFAULT NULL, ADD crm_automation JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres DROP crm_health_weights, DROP crm_thresholds, DROP crm_automation');
    }
}
