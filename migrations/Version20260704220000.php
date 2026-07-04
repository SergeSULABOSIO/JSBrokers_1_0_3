<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Droit d'accès dédié « Portefeuilles » dans les rôles Production
 * (RolesEnProduction::accessPortefeuille). Les rôles existants sont rétro-remplis
 * avec un tableau vide (aucun droit), cohérent avec la politique fail-closed.
 */
final class Version20260704220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rôles Production : ajout du droit accessPortefeuille (paramétrage des accès invités aux portefeuilles)';
    }

    public function up(Schema $schema): void
    {
        // Ajout nullable pour pouvoir rétro-remplir les lignes existantes…
        $this->addSql("ALTER TABLE roles_en_production ADD access_portefeuille LONGTEXT DEFAULT NULL COMMENT '(DC2Type:array)'");
        // …tableau vide sérialisé (aucun droit) pour les rôles déjà en base…
        $this->addSql("UPDATE roles_en_production SET access_portefeuille = 'a:0:{}' WHERE access_portefeuille IS NULL");
        // …puis on impose NOT NULL, à l'image des autres colonnes accessXxx.
        $this->addSql("ALTER TABLE roles_en_production CHANGE access_portefeuille access_portefeuille LONGTEXT NOT NULL COMMENT '(DC2Type:array)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles_en_production DROP access_portefeuille');
    }
}
