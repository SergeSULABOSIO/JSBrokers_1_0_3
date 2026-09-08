<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN POSTE DU CATALOGUE N'EXISTE QU'UNE FOIS.
 *
 * ── CE QU'ON A CONSTATÉ ─────────────────────────────────────────────────────────────
 * Le catalogue d'un cabinet portait six fois « Prime nette », six fois « Commission
 * Ordinaire », six fois « Commission sur Fronting » ; les risques étaient à 228 lignes
 * pour 56 codes distincts. Un utilisateur qui ouvre sa liste de types de revenu y trouve
 * six entrées identiques et ne peut pas savoir laquelle sa police utilise — ni laquelle
 * modifier le jour où le taux change.
 *
 * ── D'OÙ CELA VENAIT ────────────────────────────────────────────────────────────────
 * `ServiceInitialisationEntreprise::initialiser()` sème le catalogue d'un cabinet neuf :
 * monnaies, taxes, chargements, types de revenu, risques, groupes. Ce semis créait SANS
 * REGARDER si le poste existait déjà — ce qui est sans conséquence tant qu'il ne tourne
 * qu'une fois, à la création.
 *
 * Or `app:conges:provisionner`, dont l'objet est d'installer les types d'absence et la
 * dotation de l'exercice sur les cabinets EXISTANTS, appelait `initialiser()` en entier.
 * Chaque passage rejouait donc tout le catalogue. Les dates le disent : les copies du
 * cabinet 1 portent 2026-08-31, puis quatre fois 2026-09-02 — autant que d'exécutions.
 *
 * Le correctif de fond est dans le code (semis idempotent, et commande de congés qui ne
 * sème plus que les congés). Cette migration répare ce qui est déjà en base, puis pose
 * les index UNIQUES qui interdisent la rechute — parce qu'une règle qu'on n'a écrite que
 * dans du PHP est une règle qu'un import, un script ou une seconde route peut contourner.
 *
 * ── COMMENT ON RÉPARE, ET POURQUOI DANS CET ORDRE ───────────────────────────────────
 * On garde la copie la PLUS ANCIENNE (`MIN(id)`) : c'est celle vers laquelle pointent les
 * données les plus anciennes, et la seule dont on soit sûr qu'elle préexistait aux
 * rejeux. Toutes les références sont ramenées sur elle AVANT toute suppression — une
 * suppression d'abord ferait tomber des clés étrangères, ou pire, viderait en cascade des
 * chargements de polices réelles.
 *
 * ⚠ LES RATTACHEMENTS MULTIPLES SE DÉDOUBLONNENT D'ABORD. `condition_partage_risque` a
 * pour clé primaire le COUPLE (condition, risque) : ramener deux copies d'un risque sur
 * la même survivante y créerait deux fois la même ligne, et l'UPDATE échouerait sur la
 * clé primaire. On retire donc d'abord les rattachements qui feraient doublon.
 *
 * ⚠ ET LES ACCENTS ABÎMÉS SONT REMIS D'APLOMB AU PASSAGE. Les copies d'avant le
 * 2026-08-31 portent « Cr??dit », « A??ronefs » — séquelle d'un ancien encodage. La copie
 * qu'on garde est justement la plus ancienne, donc l'abîmée. On lui reprend le texte de
 * la copie la plus récente, et SEULEMENT quand le sien porte la marque « ?? » : une
 * divergence d'une autre nature serait une retouche de l'utilisateur, qu'on n'écrase pas.
 */
final class Version20260908090000 extends AbstractMigration
{
    /**
     * Les catalogues à dédoublonner : table => [clé naturelle, colonnes de texte à réparer].
     *
     * La clé naturelle est ce qui fait qu'un poste EST le même poste : le nom pour un
     * chargement ou un type de revenu, le code pour une taxe, un risque, une monnaie.
     */
    private const CATALOGUES = [
        'chargement' => ['nom', ['description']],
        'type_revenu' => ['nom', []],
        'taxe' => ['code', ['description']],
        'risque' => ['code', ['nom_complet', 'description']],
        'monnaie' => ['code', ['nom']],
        // ⚠ APRÈS `taxe`, ET C'EST L'ORDRE QUI COMPTE : chaque autorité fiscale pointait
        // vers SA copie de la taxe. Une fois les taxes ramenées sur une seule, les six
        // « DGI » du cabinet 1 visent toutes la même — on peut alors n'en garder qu'une.
        'autorite_fiscale' => ['abreviation', ['nom']],
        'groupe' => ['nom', ['description']],
    ];

    /**
     * Ce qui pointe vers un catalogue : table => [[table référente, colonne], …].
     *
     * Relevé sur `information_schema.KEY_COLUMN_USAGE` plutôt que déduit des entités : une
     * relation oubliée ici, et la suppression échouerait sur une clé étrangère — ou, si la
     * cascade est de la partie, emporterait des données de production.
     */
    private const REFERENCES = [
        'chargement' => [
            ['chargement_pour_prime', 'type_id'],
            ['document', 'chargement_id'],
            ['type_revenu', 'type_chargement_id'],
        ],
        'type_revenu' => [
            ['document', 'type_revenu_id'],
            ['revenu_pour_courtier', 'type_revenu_id'],
        ],
        'taxe' => [
            ['autorite_fiscale', 'taxe_id'],
            ['document', 'taxe_id'],
        ],
        'risque' => [
            ['condition_partage_risque', 'risque_id'],
            ['document', 'risque_id'],
            ['notification_sinistre', 'risque_id'],
            ['piste', 'risque_id'],
        ],
        'monnaie' => [
            ['document', 'monnaie_id'],
        ],
        'autorite_fiscale' => [
            ['document', 'autorite_fiscale_id'],
            ['note', 'autoritefiscale_id'],
        ],
        'groupe' => [
            ['client', 'groupe_id'],
            ['document', 'groupe_id'],
        ],
    ];

    /** Les tables de rattachement multiple, et l'autre colonne de leur clé primaire. */
    private const RATTACHEMENTS = [
        'condition_partage_risque' => 'condition_partage_id',
    ];

    public function getDescription(): string
    {
        return 'Catalogues : supprime les postes en double, ramène les références sur la copie retenue, et interdit la rechute.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CATALOGUES as $table => [$cle, $textes]) {
            $this->reparerLesTextes($table, $cle, $textes);
            $this->ramenerLesReferences($table, $cle);
            $this->supprimerLesDoublons($table, $cle);
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX uniq_%s_entreprise_cle ON %s (entreprise_id, %s)',
                $table,
                $table,
                $cle,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // ⚠ ON NE REPEUPLE PAS LES DOUBLONS. Ils n'ont jamais rien signifié, et les
        // références qui pointaient vers eux pointent maintenant vers la copie retenue :
        // les recréer laisserait des postes vides que personne ne réclame. Seule la
        // contrainte se retire — c'est elle, et elle seule, que cette migration ajoute.
        foreach (array_keys(self::CATALOGUES) as $table) {
            $this->addSql(sprintf('DROP INDEX uniq_%s_entreprise_cle ON %s', $table, $table));
        }
    }

    /**
     * Rend à la copie retenue le texte que l'ancien encodage avait abîmé.
     *
     * @param string[] $colonnes
     */
    private function reparerLesTextes(string $table, string $cle, array $colonnes): void
    {
        foreach ($colonnes as $colonne) {
            // `recente` : le texte de la copie la plus récente du même poste. C'est celle
            // qu'un semis récent a écrite, donc celle dont l'encodage est bon.
            $this->addSql(sprintf(
                'UPDATE %1$s g
                 JOIN (SELECT s.entreprise_id, s.%2$s AS cle, s.%3$s AS texte
                       FROM %1$s s
                       JOIN (SELECT entreprise_id, %2$s AS cle, MAX(id) AS dernier
                             FROM %1$s GROUP BY entreprise_id, %2$s) m
                         ON m.dernier = s.id) recente
                   ON recente.entreprise_id = g.entreprise_id AND recente.cle = g.%2$s
                 SET g.%3$s = recente.texte
                 WHERE g.%3$s IS NOT NULL
                   AND recente.texte IS NOT NULL
                   AND g.%3$s <> recente.texte
                   AND g.%3$s LIKE %4$s',
                $table,
                $cle,
                $colonne,
                "'%??%'",
            ));
        }
    }

    /** Ramène toutes les références des doublons vers la copie retenue. */
    private function ramenerLesReferences(string $table, string $cle): void
    {
        foreach (self::REFERENCES[$table] as [$referente, $colonne]) {
            // ⚠ D'ABORD LES COLLISIONS DE RATTACHEMENT. Deux copies d'un risque visées par
            // la MÊME condition de partage donneraient, une fois ramenées, deux lignes
            // identiques dans une table dont la clé primaire est le couple entier.
            if (isset(self::RATTACHEMENTS[$referente])) {
                $this->addSql(sprintf(
                    'DELETE r FROM %1$s r
                     JOIN %2$s d ON d.id = r.%3$s
                     JOIN (SELECT entreprise_id, %4$s AS cle, MIN(id) AS retenu
                           FROM %2$s GROUP BY entreprise_id, %4$s) s
                       ON s.entreprise_id = d.entreprise_id AND s.cle = d.%4$s
                     WHERE r.%3$s <> s.retenu
                       AND EXISTS (
                           SELECT 1 FROM (SELECT %5$s, %3$s FROM %1$s) deja
                           WHERE deja.%5$s = r.%5$s AND deja.%3$s = s.retenu
                       )',
                    $referente,
                    $table,
                    $colonne,
                    $cle,
                    self::RATTACHEMENTS[$referente],
                ));
            }

            $this->addSql(sprintf(
                'UPDATE %1$s r
                 JOIN %2$s d ON d.id = r.%3$s
                 JOIN (SELECT entreprise_id, %4$s AS cle, MIN(id) AS retenu
                       FROM %2$s GROUP BY entreprise_id, %4$s) s
                   ON s.entreprise_id = d.entreprise_id AND s.cle = d.%4$s
                 SET r.%3$s = s.retenu
                 WHERE r.%3$s <> s.retenu',
                $referente,
                $table,
                $colonne,
                $cle,
            ));
        }
    }

    /** Supprime les copies surnuméraires, une fois qu'elles ne sont plus référencées. */
    private function supprimerLesDoublons(string $table, string $cle): void
    {
        // La sous-requête est enveloppée dans une table dérivée : MySQL refuse de lire la
        // table qu'il est en train de supprimer.
        $this->addSql(sprintf(
            'DELETE g FROM %1$s g
             JOIN (SELECT * FROM (SELECT entreprise_id, %2$s AS cle, MIN(id) AS retenu
                                  FROM %1$s GROUP BY entreprise_id, %2$s) x) s
               ON s.entreprise_id = g.entreprise_id AND s.cle = g.%2$s
             WHERE g.id <> s.retenu',
            $table,
            $cle,
        ));
    }
}
