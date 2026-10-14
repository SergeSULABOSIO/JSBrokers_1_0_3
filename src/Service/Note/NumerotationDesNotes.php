<?php

namespace App\Service\Note;

use App\Entity\Note;
use Doctrine\DBAL\Connection;

/**
 * LE NUMÉRO D'UNE NOTE : unique dans son cabinet, et chronologique.
 *
 * ── LA RÈGLE (décisions du 2026-10-14) ──────────────────────────────────────────────
 * Le produit génère la référence : `ND-AAAA-NNNNN` pour une note de débit,
 * `NC-AAAA-NNNNN` pour une note de crédit. Chaque cabinet a ses séries ; chacune repart à
 * 1 le 1er janvier. Le champ reste ÉDITABLE : une référence saisie est conservée telle
 * quelle et ne consomme aucun numéro. Seule l'unicité dans le cabinet est imposée.
 *
 * Elle remplace `'N' . time()`, qui ne disait rien de l'ordre d'émission et pouvait se
 * répéter, ce qu'aucun contrôle n'empêchait.
 *
 * ── POURQUOI UNE SEULE INSTRUCTION SQL ──────────────────────────────────────────────
 * `UPDATE … SET sequence = LAST_INSERT_ID(sequence + 1)` incrémente et retient la nouvelle
 * valeur pour CETTE connexion, en un seul geste : la ligne est verrouillée le temps de
 * l'instruction, et deux enregistrements simultanés obtiennent deux numéros. Lire puis
 * écrire en deux temps les aurait laissés repartir avec le même.
 *
 * ⚠ UN NUMÉRO PEUT SE PERDRE, JAMAIS SE RÉPÉTER. Le numéro est pris juste avant
 * l'écriture de la note ; si celle-ci échoue ensuite, il reste consommé (sauf quand une
 * transaction englobante l'annule, comme le contrôle à blanc de Ket). La série reste
 * unique et chronologique ; un trou y est possible, rare.
 *
 * ⚠ DANS UNE TRANSACTION ENGLOBANTE, LA SÉRIE RESTE VERROUILLÉE JUSQU'À SA FIN (plan de
 * Ket, palier d'import) : les autres notes du même cabinet et du même type attendent. C'est
 * le prix d'un numéro rendu à la série quand l'écriture est annulée. Prendre le numéro sur
 * une connexion à part libérerait la série aussitôt, mais chaque contrôle à blanc, chaque
 * import annulé y laisserait un trou.
 */
final class NumerotationDesNotes
{
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
     * Réserve et rend le prochain numéro libre de la série.
     *
     * Un numéro déjà porté par une note du cabinet (saisi à la main, ou repris d'un
     * import) est sauté : la référence générée ne collisionne jamais.
     *
     * @param list<string> $dejaPrises références posées dans la même écriture, pas
     *                                 encore en base
     */
    public function prochaine(int $entrepriseId, ?int $type, int $annee, array $dejaPrises = []): string
    {
        $serie = $type === Note::TYPE_NOTE_DE_CREDIT ? Note::TYPE_NOTE_DE_CREDIT : Note::TYPE_NOTE_DE_DEBIT;

        // La ligne de la série naît à 0 la première fois ; IGNORE laisse intacte celle
        // qui existe déjà, y compris si un enregistrement simultané vient de la créer.
        $this->connexion->executeStatement(
            'INSERT IGNORE INTO compteur_de_notes (entreprise_id, annee, type, sequence) VALUES (?, ?, ?, 0)',
            [$entrepriseId, $annee, $serie],
        );

        // Comparées comme la base les compare : la casse ne distingue pas deux références.
        $dejaPrises = array_map('mb_strtolower', $dejaPrises);

        do {
            $touchees = $this->connexion->executeStatement(
                'UPDATE compteur_de_notes SET sequence = LAST_INSERT_ID(sequence + 1)'
                . ' WHERE entreprise_id = ? AND annee = ? AND type = ?',
                [$entrepriseId, $annee, $serie],
            );
            // ⚠ SANS LIGNE TOUCHÉE, LAST_INSERT_ID() GARDE UNE VALEUR PÉRIMÉE — et la boucle
            // retomberait indéfiniment sur le même numéro. IGNORE n'avale pas que les
            // doublons (une clé étrangère refusée aussi) : on ne se fie qu'au fait.
            if ($touchees !== 1) {
                throw new \LogicException(sprintf('Série de notes introuvable (cabinet %d, %d, type %d) : aucun numéro attribué.', $entrepriseId, $annee, $serie));
            }
            $sequence = (int) $this->connexion->fetchOne('SELECT LAST_INSERT_ID()');
            $reference = self::formater($serie, $annee, $sequence);
        } while (in_array(mb_strtolower($reference), $dejaPrises, true) || $this->estDejaPortee($entrepriseId, $reference));

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
