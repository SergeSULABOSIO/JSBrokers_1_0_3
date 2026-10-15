<?php

namespace App\Service\Note;

use App\Entity\Note;
use Doctrine\DBAL\Connection;

/**
 * LE NUMÉRO D'UNE NOTE : unique, chronologique et CONTINU dans sa série.
 *
 * ── LA RÈGLE (pratique comptable OHADA, décision de Serge du 2026-10-15) ────────────
 * Chaque note VALIDÉE porte un numéro `ND-AAAA-NNNNN` (débit) ou `NC-AAAA-NNNNN`
 * (crédit) : une série par cabinet, par année d'émission et par type, sans trou ni
 * doublon. Le numéro est attribué À LA VALIDATION — c'est elle qui fait naître la
 * créance —, jamais à la saisie, et il ne se saisit pas. Un brouillon porte une
 * référence PROVISOIRE, `BROUILLON-…`, clairement distincte : il peut être supprimé sans
 * laisser de trou. (Avant le 2026-10-15, le numéro était pris à la création et la
 * référence restait éditable ; la règle OHADA l'a remplacé.)
 *
 * ── POURQUOI UNE SEULE INSTRUCTION SQL ──────────────────────────────────────────────
 * `UPDATE … SET sequence = LAST_INSERT_ID(sequence + 1)` incrémente et retient la nouvelle
 * valeur pour CETTE connexion, en un seul geste. La ligne de la série reste verrouillée
 * (InnoDB) jusqu'à la fin de la transaction : une seconde validation de la même série
 * ATTEND, puis prend le numéro suivant. Deux validations simultanées ne lisent donc
 * jamais la même valeur, et la chronologie suit l'ordre des numéros.
 *
 * ⚠ PAS DE TROU, À UNE CONDITION : QUE LA VALIDATION SOIT DANS UNE TRANSACTION. Si elle
 * échoue après la prise du numéro, l'annulation rend l'incrément à la série. C'est
 * pourquoi toute validation passe par {@see EmissionDeNote::valider}, qui l'enveloppe —
 * Doctrine, lui, déclenche `onFlush` AVANT d'ouvrir sa propre transaction.
 */
final class NumerotationDesNotes
{
    /** Préfixe des références provisoires : un brouillon n'a pas de numéro. */
    public const PREFIXE_BROUILLON = 'BROUILLON-';

    public function __construct(private readonly Connection $connexion)
    {
    }

    /** Le préfixe de la série : une note de crédit a la sienne, tout le reste est débit. */
    public static function prefixe(?int $type): string
    {
        return $type === Note::TYPE_NOTE_DE_CREDIT ? 'NC' : 'ND';
    }

    public static function formater(?int $type, int $annee, int $sequence): string
    {
        return sprintf('%s-%d-%05d', self::prefixe($type), $annee, $sequence);
    }

    /**
     * Réserve et rend le numéro suivant de la série.
     *
     * Un numéro déjà PORTÉ par une note du cabinet est sauté : il est déjà émis (pièce
     * d'avant cette règle, quand la référence se saisissait encore), donc pas un trou. Le
     * refuser bloquerait la série pour toujours — chaque essai annulé retomberait dessus.
     */
    public function prochaine(int $entrepriseId, ?int $type, int $annee): string
    {
        $serie = $type === Note::TYPE_NOTE_DE_CREDIT ? Note::TYPE_NOTE_DE_CREDIT : Note::TYPE_NOTE_DE_DEBIT;

        do {
            $reference = self::formater($serie, $annee, $this->incrementer($entrepriseId, $annee, $serie));
        } while ($this->estDejaPortee($entrepriseId, $reference));

        return $reference;
    }

    /**
     * Incrémente la série et rend la nouvelle valeur.
     *
     * ⚠ L'UPDATE D'ABORD, la création de la ligne seulement à défaut. Un `INSERT IGNORE`
     * sur une ligne existante pose un verrou PARTAGÉ tenu jusqu'au commit : deux
     * validations simultanées le prenaient chacune, puis chacune attendait l'autre pour
     * l'UPDATE — un interblocage. Une série existe presque toujours : l'UPDATE seul suffit.
     */
    private function incrementer(int $entrepriseId, int $annee, int $serie): int
    {
        $sql = 'UPDATE compteur_de_notes SET sequence = LAST_INSERT_ID(sequence + 1) WHERE entreprise_id = ? AND annee = ? AND type = ?';
        $touchees = $this->connexion->executeStatement($sql, [$entrepriseId, $annee, $serie]);

        if ($touchees !== 1) {
            // La série naît à 0 ; IGNORE laisse intacte celle qu'une validation simultanée
            // viendrait de créer.
            $this->connexion->executeStatement(
                'INSERT IGNORE INTO compteur_de_notes (entreprise_id, annee, type, sequence) VALUES (?, ?, ?, 0)',
                [$entrepriseId, $annee, $serie],
            );
            $touchees = $this->connexion->executeStatement($sql, [$entrepriseId, $annee, $serie]);
        }
        // ⚠ SANS LIGNE TOUCHÉE, LAST_INSERT_ID() GARDE UNE VALEUR PÉRIMÉE. IGNORE n'avale
        // pas que les doublons (une clé étrangère refusée aussi) : on ne se fie qu'au fait.
        if ($touchees !== 1) {
            throw new \LogicException(sprintf('Série de notes introuvable (cabinet %d, %d, type %d) : aucun numéro attribué.', $entrepriseId, $annee, $serie));
        }

        return (int) $this->connexion->fetchOne('SELECT LAST_INSERT_ID()');
    }

    /**
     * Une référence provisoire, unique dans le cabinet : `BROUILLON-` (ou le préfixe
     * donné) suivi de huit caractères hexadécimaux. Elle ne consomme aucun numéro.
     *
     * @param list<string> $dejaPrises références posées dans la même écriture
     */
    public function provisoire(int $entrepriseId, string $prefixe = self::PREFIXE_BROUILLON, array $dejaPrises = []): string
    {
        do {
            $reference = $prefixe . strtoupper(bin2hex(random_bytes(4)));
        } while (in_array($reference, $dejaPrises, true) || $this->estDejaPortee($entrepriseId, $reference));

        return $reference;
    }

    /**
     * Cette référence est-elle déjà portée par une note du cabinet ?
     *
     * La comparaison suit la collation de la colonne, comme l'index unique qui tranche
     * en dernier ressort : « nd-2026-00001 » et « ND-2026-00001 » sont une même référence.
     */
    public function estDejaPortee(int $entrepriseId, string $reference, ?int $saufLaNote = null): bool
    {
        return (bool) $this->connexion->fetchOne(
            'SELECT 1 FROM note WHERE entreprise_id = ? AND reference = ? AND id <> ? LIMIT 1',
            [$entrepriseId, $reference, $saufLaNote ?? 0],
        );
    }
}
