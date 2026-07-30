<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mode sombre du chat de l'assistant IA : préférence de thème persistée par
 * utilisateur (comme `locale`), donc suivie sur tous ses appareils.
 *
 * NULL = aucun choix explicite → le front suit la préférence système du poste
 * (`prefers-color-scheme`). Les comptes existants restent donc sur ce défaut,
 * sans bascule visuelle imposée.
 */
final class Version20260729160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat IA : préférence de thème (clair / sombre) par utilisateur, NULL = suivre le système.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD theme_assistant VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP theme_assistant');
    }
}
