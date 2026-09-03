<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA RUBRIQUE « IMPORTATION / EXPORTATION » A SON PROPRE DROIT.
 *
 * Elle expose une capacité que ne porte aucune rubrique existante : sortir du cabinet, en
 * un seul fichier, l'intégralité de ce qu'un collaborateur a le droit de lire — données
 * personnelles de prospects et d'assurés comprises. Le droit se règle donc à part, comme
 * les autres accès du module Administration, et non par emprunt à une rubrique voisine.
 *
 * ⚠ AUCUNE REPRISE, ET C'EST DÉLIBÉRÉ.
 *
 * La migration des rétros agents (Version20260824120000) recopiait un droit existant, pour
 * ne retirer à personne, en silence, un accès dont il disposait la veille. Ici le cas est
 * l'inverse exact : la rubrique n'existait pas, donc personne n'y avait accès. Toute reprise
 * ACCORDERAIT un droit que nul n'a demandé — et le seul candidat plausible (« Documents »)
 * n'a aucun rapport de sensibilité avec l'extraction du portefeuille complet. Le droit naît
 * donc fermé partout ; le propriétaire du cabinet garde son accès inconditionnel et ouvre
 * la rubrique à qui de droit depuis le gestionnaire de rôles.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rubrique Importation / Exportation : droit dédié accessEchange dans RolesEnAdministration.';
    }

    public function up(Schema $schema): void
    {
        // La colonne est NOT NULL comme ses six voisines.
        $this->addSql("ALTER TABLE roles_en_administration ADD access_echange LONGTEXT NOT NULL COMMENT '(DC2Type:array)'");

        // ⚠ MySQL remplit les lignes existantes avec la CHAÎNE VIDE, que le type Doctrine
        // ARRAY ne sait pas désérialiser : toute lecture de rôle lèverait. On pose donc le
        // tableau sérialisé vide PARTOUT — qui est aussi, ici, le repli fail-closed voulu.
        $this->addSql("UPDATE roles_en_administration SET access_echange = 'a:0:{}'");
    }

    public function down(Schema $schema): void
    {
        // La rubrique disparaît avec sa colonne : sans entrée dans WorkspaceAccessResolver::MAP,
        // elle retomberait sur le fail-open de can(). Aucune donnée métier n'est en jeu.
        $this->addSql('ALTER TABLE roles_en_administration DROP access_echange');
    }
}
