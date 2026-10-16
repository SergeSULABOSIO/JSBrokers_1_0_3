<?php

namespace App\Service\Note;

use App\Entity\Entreprise;
use App\Entity\Note;
use Doctrine\DBAL\Connection;

/**
 * LES PIÈCES COMPTABLES SE CONSERVENT DIX ANS (Acte uniforme OHADA relatif au droit
 * comptable ; décisions de Serge du 2026-10-15 et du 2026-10-16). Rien ne les purge — sauf
 * la suppression du cabinet tout entier, et seulement APRÈS la remise de son archive
 * comptable ({@see ArchiveComptableDuCabinet}) : le cabinet emporte ses pièces, la base
 * peut alors les effacer.
 *
 * ── CE QUI EST UNE PIÈCE ────────────────────────────────────────────────────────────
 * La MOINDRE pièce comptable suffit : une note émise (validée, ou de reprise), un
 * paiement (d'une note ou d'une indemnité), un paiement de prime, une dépense, un
 * reversement. NE le sont pas : un BROUILLON de note, qui n'a aucune valeur comptable, et
 * le CAPITAL déclaré sur la fiche du cabinet, qui n'est pas une pièce justificative à lui
 * seul — il figure dans l'archive (journal) quand une autre pièce l'exige.
 *
 * L'archive doit être À JOUR : remise APRÈS la dernière écriture que porte son journal.
 */
final class ConservationDesPieces
{
    /** Les notes à conserver : validées, figées, ou de reprise (même pas encore figées). */
    private const NOTES = '(validated = 1 OR figee_le IS NOT NULL OR origine = ' . Note::ORIGINE_REPRISE . ')';

    /** Chaque nature de pièce : son libellé au singulier et au pluriel, et sa requête. */
    private const NATURES = [
        'notes' => ['note émise', 'notes émises', 'SELECT COUNT(*) FROM note WHERE entreprise_id = ? AND ' . self::NOTES],
        'paiements' => ['paiement', 'paiements', 'SELECT COUNT(*) FROM paiement WHERE entreprise_id = ?'],
        'paiementsDePrime' => ['paiement de prime', 'paiements de prime', 'SELECT COUNT(*) FROM paiement_prime WHERE entreprise_id = ?'],
        'depenses' => ['dépense', 'dépenses', 'SELECT COUNT(*) FROM depense_courtier WHERE entreprise_id = ?'],
        'reversements' => ['reversement', 'reversements', 'SELECT COUNT(*) FROM reversement_retro_agent WHERE entreprise_id = ?'],
    ];

    public function __construct(private readonly Connection $connexion)
    {
    }

    /**
     * Le décompte des pièces à conserver, par nature (les natures à zéro sont omises).
     *
     * @return array<string, int>
     */
    public function piecesAConserver(Entreprise $entreprise): array
    {
        $pieces = [];
        foreach (self::NATURES as $nature => [, , $sql]) {
            $nombre = (int) $this->connexion->fetchOne($sql, [(int) $entreprise->getId()]);
            if ($nombre > 0) {
                $pieces[$nature] = $nombre;
            }
        }

        return $pieces;
    }

    /** « 2 notes émises, 1 dépense » — le décompte dit en clair. */
    public function decompteEnClair(array $pieces): string
    {
        $morceaux = [];
        foreach ($pieces as $nature => $nombre) {
            [$un, $plusieurs] = self::NATURES[$nature];
            $morceaux[] = $nombre . ' ' . ($nombre > 1 ? $plusieurs : $un);
        }

        return implode(', ', $morceaux);
    }

    /**
     * La date de la dernière écriture que porte l'archive — pièces ET justificatifs joints :
     * une facture jointe à une dépense après la remise n'est pas dans l'archive remise.
     */
    public function derniereEcriture(Entreprise $entreprise): ?\DateTimeImmutable
    {
        $id = (int) $entreprise->getId();
        $date = $this->connexion->fetchOne(
            'SELECT MAX(d) FROM (
                SELECT GREATEST(COALESCE(emise_le, created_at), COALESCE(figee_le, created_at), COALESCE(updated_at, created_at)) AS d
                    FROM note WHERE entreprise_id = ? AND ' . self::NOTES . '
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM paiement WHERE entreprise_id = ?
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM paiement_prime WHERE entreprise_id = ?
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM depense_courtier WHERE entreprise_id = ?
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM reversement_retro_agent WHERE entreprise_id = ?
                UNION ALL SELECT GREATEST(created_at, COALESCE(updated_at, created_at)) FROM document WHERE entreprise_id = ?
                    AND (note_id IS NOT NULL OR paiement_id IS NOT NULL OR paiement_prime_id IS NOT NULL
                         OR depense_courtier_id IS NOT NULL OR reversement_retro_agent_id IS NOT NULL)
             ) dates',
            [$id, $id, $id, $id, $id, $id],
        );

        return $date ? new \DateTimeImmutable((string) $date) : null;
    }

    /** Pourquoi ce cabinet ne peut pas encore être supprimé, ou null s'il le peut. */
    public function refusDeSuppression(Entreprise $entreprise): ?string
    {
        $pieces = $this->piecesAConserver($entreprise);
        if ($pieces === []) {
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
            'Ce cabinet porte des pièces comptables (%s), à conserver dix ans (OHADA). '
            . 'Téléchargez d’abord son archive comptable — elle réunit toutes ses pièces, leurs justificatifs et le journal —, puis supprimez-le.%s',
            $this->decompteEnClair($pieces),
            $remise !== null ? ' L’archive remise le ' . $remise->format('d/m/Y à H:i') . ' n’est plus à jour : une écriture a été enregistrée depuis, ou pendant sa construction.' : '',
        );
    }
}
