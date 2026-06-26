<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM : horodatage de la dernière exécution des automatisations planifiées
 * (déclenchement « paresseux » throttlé, sans cron). Colonne nullable additive.
 */
final class Version20260626150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM : plateforme_parametres.crm_last_auto_run_at (heartbeat des automatisations planifiées).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE plateforme_parametres ADD crm_last_auto_run_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres DROP crm_last_auto_run_at');
    }
}
