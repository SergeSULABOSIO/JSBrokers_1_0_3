<?php

namespace App\Service\Note;

use App\Entity\Article;
use App\Entity\Note;

/**
 * UNE NOTE VALIDÉE NE SE MODIFIE PLUS ET NE SE SUPPRIME PAS (règle OHADA, décision de
 * Serge du 2026-10-15).
 *
 * Une pièce comptable émise est intangible : ni son en-tête, ni ses lignes, ni sa
 * validation ne changent. Toute correction passe par la note CONTRAIRE — un avoir pour
 * une note de débit, une note de débit pour un avoir —, puis par une nouvelle note si
 * besoin. Une note de REPRISE, figée à l'import, ne se MODIFIE pas davantage ; mais,
 * n'étant pas une pièce émise par Joseara, elle se SUPPRIME comme un brouillon (paiement
 * d'abord) — un import erroné se défait (décision de Serge du 2026-10-16).
 *
 * Restent permis : l'ajout et la suppression d'un PAIEMENT (encaisser, corriger une
 * saisie erronée) et les pièces jointes — ils ne touchent ni la note ni ses lignes.
 *
 * LA RÈGLE, ET SES TROIS GARDIENS : l'écran (409 qui explique, dialogue en lecture
 * seule), Ket (blocage dès l'analyse), et en dernier rempart le flush lui-même
 * ({@see \App\EventListener\IntangibiliteDesNotesListener}), que toute porte traverse.
 */
final class NoteIntangible
{
    /** La note est-elle intangible : validée, ou figée (note de reprise) ? */
    public static function estIntangible(?Note $note): bool
    {
        return $note !== null && ($note->isValidated() === true || $note->estFigee());
    }

    /**
     * Pourquoi cette note, ou cette ligne, ne s'écrit pas — null si elle le peut.
     */
    public static function refus(object $entite): ?string
    {
        if ($entite instanceof Note && $entite->getId() !== null && self::estIntangible($entite)) {
            return self::motif($entite);
        }
        if ($entite instanceof Article && self::estIntangible($entite->getNote())) {
            return self::motif($entite->getNote(), true);
        }

        return null;
    }

    public static function motif(Note $note, bool $pourUneLigne = false): string
    {
        $nom = 'la note n° ' . $note->getReference();
        if ($note->estDeReprise()) {
            return sprintf(
                '%s retrace une commission reprise de votre ancien système : elle ne se modifie plus.',
                $pourUneLigne ? 'Cette ligne appartient à ' . $nom . ', qui' : ucfirst($nom),
            );
        }
        $contraire = $note->getType() === Note::TYPE_NOTE_DE_CREDIT ? 'une note de débit' : 'une note de crédit (avoir)';

        return sprintf(
            '%s est validée : une pièce comptable émise ne se modifie plus. Corrigez-la par %s, puis émettez-en une nouvelle si besoin.',
            $pourUneLigne ? 'Cette ligne appartient à ' . $nom . ', qui' : ucfirst($nom),
            $contraire,
        );
    }
}
