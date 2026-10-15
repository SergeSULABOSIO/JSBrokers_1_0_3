<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA DATE D'ÉMISSION D'UNE NOTE (règle OHADA, décision de Serge du 2026-10-15).
 *
 * Le numéro définitif et la date d'émission naissent désormais à la VALIDATION
 * (NumerotationDesNotesListener). Les notes déjà validées reçoivent pour date d'émission
 * leur date d'envoi, à défaut leur date de création — c'est celle qui datait déjà leur
 * créance au journal. Leur référence n'est pas touchée : un numéro déjà émis se conserve,
 * même hors du format de la série (mesure en production du 2026-10-15 : une seule note
 * validée, « N1790767662318 », du 30/09/2026, au format d'avant la numérotation).
 *
 * CHAQUE NOTE DATÉE EST LISTÉE. Rejouée, la migration ne change rien.
 */
final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes : date d’émission (posée à la validation) ; les notes déjà validées sont datées de leur envoi, à défaut de leur création.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->colonneExiste()) {
            $this->addSql('ALTER TABLE note ADD emise_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        $notes = $this->connection->fetchAllAssociative(
            'SELECT n.id, n.entreprise_id, n.reference, COALESCE(n.sent_at, n.created_at) AS emise_le
             FROM note n WHERE n.validated = 1' . ($this->colonneExiste() ? ' AND n.emise_le IS NULL' : '') . ' ORDER BY n.entreprise_id, n.id'
        );
        foreach ($notes as $note) {
            $this->write(sprintf('  Cabinet #%d, note #%d « %s » : émise le %s.', $note['entreprise_id'], $note['id'], $note['reference'], $note['emise_le']));
            $this->addSql('UPDATE note SET emise_le = ? WHERE id = ?', [$note['emise_le'], (int) $note['id']]);
        }

        // UN BROUILLON NE PORTE PAS DE NUMÉRO. Sous l'ancienne règle, le numéro était pris
        // à la création : un brouillon qui en porte un l'affiche comme définitif, et à sa
        // validation il en prendrait un autre. Il reçoit une référence provisoire ; son
        // ancien numéro est listé ici — c'est un trou de la série, documenté, non comblé.
        // (Mesure en production du 2026-10-15 : aucun.)
        $prises = [];
        $brouillons = $this->connection->fetchAllAssociative(
            "SELECT id, entreprise_id, reference FROM note WHERE validated = 0 AND reference REGEXP '^N[DC]-[0-9]{4}-[0-9]{5}$' ORDER BY entreprise_id, id"
        );
        foreach ($brouillons as $brouillon) {
            do {
                $provisoire = 'BROUILLON-' . strtoupper(bin2hex(random_bytes(4)));
            } while (isset($prises[$provisoire]) || $this->connection->fetchOne('SELECT 1 FROM note WHERE entreprise_id = ? AND reference = ?', [$brouillon['entreprise_id'], $provisoire]));
            $prises[$provisoire] = true;
            $this->write(sprintf('  Cabinet #%d, brouillon #%d : « %s » devient « %s » ; le numéro %s reste un trou de la série.', $brouillon['entreprise_id'], $brouillon['id'], $brouillon['reference'], $provisoire, $brouillon['reference']));
            $this->addSql('UPDATE note SET reference = ? WHERE id = ?', [$provisoire, (int) $brouillon['id']]);
        }
    }

    private function colonneExiste(): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'note' AND COLUMN_NAME = 'emise_le'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note DROP emise_le');
    }
}
