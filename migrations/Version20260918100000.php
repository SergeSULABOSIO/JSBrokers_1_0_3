<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mode Live : la tâche se souvient que la question a été DITE.
 *
 * ── POURQUOI UNE COLONNE, ET PAS UN DRAPEAU EN MÉMOIRE ──────────────────────
 * Une question posée à voix haute saute la phase de compréhension (cf.
 * AiRequest::modeLive) : mesurée sur les journaux du 2026-09-17, elle coûtait 8,2 s
 * médianes, jusqu'à 34,8 s, et échouait une fois sur deux en « Idle timeout ». À
 * l'oral, l'utilisateur se corrige lui-même à la phrase suivante ; cette attente-là ne
 * rachète rien.
 *
 * Or seul le NAVIGATEUR sait qu'une session Live est ouverte, et le traitement, lui,
 * peut avoir lieu dans un worker Messenger plusieurs secondes plus tard, dans un
 * processus qui n'a jamais vu la requête HTTP. Le drapeau doit donc voyager avec la
 * tâche, exactement comme le terminal (`terminal`) et l'instantané du contexte.
 *
 * ── PAR DÉFAUT FALSE ────────────────────────────────────────────────────────
 * Les tâches déjà en file au moment du déploiement n'en portent pas : elles se
 * traitent comme avant, avec leur phase de compréhension. Rien d'autre ne change —
 * mêmes outils, mêmes prompts, même boussole, même facturation.
 */
final class Version20260918100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mode Live : assistant_tache.live (question dite à voix haute, compréhension sautée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_tache ADD live TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_tache DROP live');
    }
}
