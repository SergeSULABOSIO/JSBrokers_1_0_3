<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistant IA — droit d'accès au module par collaborateur.
 *
 * Ajoute à `roles_en_administration` le périmètre `access_assistant_ia`
 * (pseudo-entité « AssistantIa » de WorkspaceAccessResolver::MAP) : la rubrique
 * Assistant du workspace n'est visible/utilisable par un invité que si son rôle
 * lui accorde au moins la Lecture (le propriétaire garde l'accès total).
 *
 * Colonne de type Doctrine ARRAY (PHP sérialisé) : ajout en TROIS temps —
 * nullable, remplissage `a:0:{}` (tableau vide sérialisé) des rôles existants,
 * puis passage NOT NULL — pour ne pas casser la désérialisation (même pattern
 * que Version20260703090000 pour access_document_comptable).
 */
final class Version20260713100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rôles Administration : périmètre d'accès au module Assistant IA (access_assistant_ia).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles_en_administration ADD access_assistant_ia LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql("UPDATE roles_en_administration SET access_assistant_ia = 'a:0:{}'");
        $this->addSql('ALTER TABLE roles_en_administration CHANGE access_assistant_ia access_assistant_ia LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles_en_administration DROP access_assistant_ia');
    }
}
