<?php

namespace App\Service\Retro;

use App\Entity\ReversementRetroAgent;

/**
 * UN REVERSEMENT EST UNE PIÈCE COMPTABLE : ENREGISTRÉ, IL NE SE MODIFIE PLUS ET NE SE SUPPRIME
 * PAS (Serge, 2026-10-30 — la logique des notes émises, {@see \App\Service\Note\NoteIntangible}).
 *
 * Ses champs COMPTABLES sont figés dès l'enregistrement : le montant, la date du versement, le
 * bénéficiaire (agent ou partenaire), l'échéance, la police, le compte débité — et ce qui fait
 * le virement (`lotReference`) ou l'annule (`contrePassationDe`). La correction passe par la
 * CONTRE-PASSATION du virement entier ({@see ContrePassationDeReversement}), puis, si besoin, un
 * nouveau reversement. Restent modifiables, par la fenêtre du virement : la référence (un numéro
 * d'ordre mal saisi), la description, les justificatifs.
 *
 * LA RÈGLE, ET SES TROIS GARDIENS : l'écran (409 qui explique, fenêtre en lecture), Ket
 * (bloqué), et en dernier rempart le flush ({@see \App\EventListener\IntangibiliteDesReversementsListener}).
 */
final class ReversementIntangible
{
    /** Les champs dont la valeur fait l'écriture comptable. */
    public const CHAMPS_COMPTABLES = [
        'montant', 'paidAt', 'agent', 'partenaire', 'tranche', 'avenant', 'compteBancaire',
        'lotReference', 'contrePassationDe', 'entreprise',
    ];

    public const MOTIF = "Un reversement enregistré est une pièce comptable : son montant, sa date, son bénéficiaire, son échéance et son compte ne se modifient plus. Contre-passez le virement, puis enregistrez-en un nouveau si besoin.";

    public const MOTIF_SUPPRESSION = "Un reversement enregistré est une pièce comptable : il ne se supprime pas. Contre-passez le virement pour l'annuler.";

    public const DEJA_CONTRE_PASSE = 'Ce virement est déjà contre-passé : il ne s’annule qu’une fois.';

    public const CONTRE_PASSATION_SEULE = 'La contre-passation se fait seule : elle annule le virement entier, sans rien modifier d’autre.';

    public const CONTRE_PASSATION_AGENT_SANS_DROIT = 'Contre-passer le versement d’un agent relève de la gestion de l’espace de travail.';

    public const ANNONCE_CONTRE_PASSATION = 'Chaque échéance réglée par ce virement recevra une ligne de montant opposé, datée d’aujourd’hui : le virement d’origine reste visible, et le montant redevient dû au bénéficiaire. Une contre-passation ne s’annule pas.';

    public const MONTANT_POSITIF = 'Un reversement est une sortie de fonds : son montant est positif. Pour annuler un versement, contre-passez le virement.';

    public const LOT_CLOS = 'Ce virement est annulé (ou c’est une annulation) : on n’y ajoute pas de ligne. Enregistrez un nouveau versement.';

    public const CONTRE_PASSATION_PAR_SON_CHEMIN ='Une contre-passation annule un virement entier : elle se fait par « Contre-passer le virement », pas en saisissant une ligne.';

    public const CONTRE_PASSATION_NON_ANNULABLE = 'Une contre-passation ne se contre-passe pas : enregistrez un nouveau reversement si le versement doit être refait.';

    /** Les champs comptables touchés par un ensemble de changements (changeset Doctrine). */
    public static function champsComptablesTouches(array $changements): array
    {
        return array_values(array_intersect(array_keys($changements), self::CHAMPS_COMPTABLES));
    }

    /**
     * Le motif qui refuse de contre-passer ce reversement ; null s'il peut l'être.
     * `$dejaContrePasse` vient de LotDeVersement::estContrePasse (préchargé par page).
     */
    public static function refusDeContrePassation(ReversementRetroAgent $reversement, bool $dejaContrePasse): ?string
    {
        return match (true) {
            $reversement->estUneContrePassation() => self::CONTRE_PASSATION_NON_ANNULABLE,
            $dejaContrePasse => self::DEJA_CONTRE_PASSE,
            default => null,
        };
    }
}
