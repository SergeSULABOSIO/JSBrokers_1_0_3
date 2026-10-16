<?php

namespace App\Service\Note;

use App\Entity\Entreprise;
use App\Entity\Note;
use Doctrine\DBAL\Connection;

/**
 * LES PIÈCES COMPTABLES SE CONSERVENT DIX ANS (Acte uniforme OHADA relatif au droit
 * comptable ; décision de Serge du 2026-10-15). Rien ne les purge — sauf la suppression
 * du cabinet tout entier, et seulement APRÈS la remise de son archive comptable
 * ({@see ArchiveComptableDuCabinet}) : le propriétaire emporte ses pièces, la base peut
 * alors les effacer.
 *
 * L'archive doit être À JOUR : remise APRÈS la dernière écriture que porte son journal —
 * une note émise ou figée, un règlement, une dépense, un reversement. Une pièce enregistrée
 * le lendemain de la remise n'y serait pas.
 */
final class ConservationDesPieces
{
    /** Les notes à conserver : validées, figées, ou de reprise (même pas encore figées). */
    private const NOTES = '(validated = 1 OR figee_le IS NOT NULL OR origine = ' . Note::ORIGINE_REPRISE . ')';

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function notesAConserver(Entreprise $entreprise): int
    {
        return (int) $this->connexion->fetchOne(
            'SELECT COUNT(*) FROM note WHERE entreprise_id = ? AND ' . self::NOTES,
            [(int) $entreprise->getId()],
        );
    }

    /** La date de la dernière écriture que porte le journal de l'archive. */
    public function derniereEcriture(Entreprise $entreprise): ?\DateTimeImmutable
    {
        $id = (int) $entreprise->getId();
        $date = $this->connexion->fetchOne(
            'SELECT MAX(d) FROM (
                SELECT GREATEST(COALESCE(emise_le, created_at), COALESCE(figee_le, created_at), COALESCE(updated_at, created_at)) AS d
                    FROM note WHERE entreprise_id = ? AND ' . self::NOTES . '
                UNION ALL SELECT GREATEST(p.created_at, COALESCE(p.updated_at, p.created_at))
                    FROM paiement p JOIN note n ON n.id = p.note_id WHERE n.entreprise_id = ?
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM depense_courtier WHERE entreprise_id = ?
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM reversement_retro_agent WHERE entreprise_id = ?
             ) dates',
            [$id, $id, $id, $id],
        );

        return $date ? new \DateTimeImmutable((string) $date) : null;
    }

    /** Pourquoi ce cabinet ne peut pas encore être supprimé, ou null s'il le peut. */
    public function refusDeSuppression(Entreprise $entreprise): ?string
    {
        $notes = $this->notesAConserver($entreprise);
        if ($notes === 0) {
            return null;
        }
        $remise = $entreprise->getArchiveComptableRemiseLe();
        $derniere = $this->derniereEcriture($entreprise);
        // STRICTEMENT après : la remise est datée du DÉBUT de sa construction, si bien
        // qu'une pièce enregistrée pendant celle-ci la rend « pas à jour ».
        if ($remise !== null && ($derniere === null || $remise > $derniere)) {
            return null;
        }

        return sprintf(
            'Ce cabinet porte %d note%s émise%s : des pièces comptables à conserver dix ans (OHADA). '
            . 'Téléchargez d’abord son archive comptable — elle réunit les notes, leurs lignes, leurs paiements et le journal —, puis supprimez-le.%s',
            $notes,
            $notes > 1 ? 's' : '',
            $notes > 1 ? 's' : '',
            $remise !== null ? ' L’archive remise le ' . $remise->format('d/m/Y à H:i') . ' n’est plus à jour : une écriture a été enregistrée depuis, ou pendant sa construction.' : '',
        );
    }
}
