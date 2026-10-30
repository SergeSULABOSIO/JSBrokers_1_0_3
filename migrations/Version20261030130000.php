<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN SEUL JEU DE PARAMÈTRES DE CONGÉ PAR CABINET (Serge, 2026-10-30) : la base le garantit
 * désormais, en plus de l'écran et de Ket (DecisionDeCreation::UNIQUE_PAR_CABINET).
 * Production vérifiée sans doublon avant cette migration (requête de Serge, résultat vide).
 * L'index unique couvre la clé étrangère : l'ancien index simple devient inutile.
 */
final class Version20261030130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paramètres de congé : un seul jeu par cabinet (index unique sur entreprise_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_parametres_conge_entreprise ON parametres_conge (entreprise_id)');
        $this->addSql('DROP INDEX IDX_F65C3147A4AEAFEA ON parametres_conge');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_F65C3147A4AEAFEA ON parametres_conge (entreprise_id)');
        $this->addSql('DROP INDEX uniq_parametres_conge_entreprise ON parametres_conge');
    }
}
