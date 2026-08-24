<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LES RÉTROS AGENTS ONT LEUR PROPRE DROIT.
 *
 * La rubrique empruntait jusqu'ici le droit « Avenants » (`roles_en_production.access_avenant`) :
 * le cabinet ne pouvait donc ni l'ouvrir ni la fermer sans toucher aux contrats, et le réglage
 * n'apparaissait NULLE PART dans le gestionnaire des rôles. Or ce que cette rubrique montre —
 * combien chaque collaborateur a touché — n'a pas la sensibilité d'un contrat.
 *
 * ⚠ LA REPRISE EST LE CŒUR DE CETTE MIGRATION, pas la colonne.
 *
 * Le défaut du champ est `a:0:{}` (tableau vide), c'est-à-dire AUCUN accès : la politique de
 * l'application est fail-closed, et c'est la bonne. Mais déployer cela tel quel retirerait, en
 * silence, un accès dont des collaborateurs disposent aujourd'hui — une régression que personne
 * n'a demandée et que personne ne verrait avant de recevoir la plainte.
 *
 * On recopie donc le droit « Avenants » de chaque invité : au lendemain du déploiement, tout le
 * monde voit exactement ce qu'il voyait la veille. Le cabinet ajuste ensuite, en connaissance de
 * cause, depuis un écran qui affiche enfin ce réglage.
 *
 * La jointure passe par `invite` parce que les deux tables de rôles s'y rattachent
 * indépendamment ; un invité sans rôle en production garde le tableau vide, ce qui est
 * exactement ce qu'il avait.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rétros agents : droit dédié dans RolesEnFinance, avec reprise du droit Avenants existant.';
    }

    public function up(Schema $schema): void
    {
        // La colonne est NOT NULL comme ses quatorze voisines.
        $this->addSql("ALTER TABLE roles_en_finance ADD access_reversement_retro_agent LONGTEXT NOT NULL COMMENT '(DC2Type:array)'");

        // ⚠ MySQL remplit les lignes existantes avec la CHAÎNE VIDE, que le type Doctrine
        // ARRAY ne sait pas désérialiser : toute lecture de rôle lèverait. On pose donc
        // d'abord le tableau sérialisé vide PARTOUT — c'est aussi le repli fail-closed des
        // invités que la reprise ci-dessous ne touchera pas.
        $this->addSql("UPDATE roles_en_finance SET access_reversement_retro_agent = 'a:0:{}'");

        // REPRISE : le droit « Avenants » de l'invité, s'il en a un. `access_avenant` est déjà
        // au format sérialisé attendu — on le recopie tel quel, sans le réinterpréter.
        $this->addSql(<<<'SQL'
            UPDATE roles_en_finance f
            JOIN invite i ON f.invite_id = i.id
            JOIN roles_en_production p ON p.invite_id = i.id
            SET f.access_reversement_retro_agent = p.access_avenant
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Redescendre reperd le réglage fin : la rubrique retombe sous le droit « Avenants »,
        // qui n'a jamais été supprimé. Aucune donnée métier n'est en jeu.
        $this->addSql('ALTER TABLE roles_en_finance DROP access_reversement_retro_agent');
    }
}
