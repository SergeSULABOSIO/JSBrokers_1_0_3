<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rôles des invités — délégation de la gestion des invités.
 *
 * Ajoute le drapeau de CONTRÔLE D'ACCÈS `gestionnaire_invites` sur `invite` : seul le
 * propriétaire peut le positionner ; un invité « gestionnaire » peut administrer les
 * invités et leurs rôles, sans aucun privilège supplémentaire sur les données métier.
 * Colonne additive et nullable — aucun impact sur les invitations existantes.
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Invités : ajout du drapeau gestionnaire_invites (délégation de la gestion des invités et des rôles).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite ADD gestionnaire_invites TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite DROP gestionnaire_invites');
    }
}
