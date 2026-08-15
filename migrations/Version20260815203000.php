<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Toute entité métier peut porter une collection « Documents », comme Avenant.
 *
 * CE QUI REMPLACE QUOI. La migration précédente (Version20260815160000) avait ouvert le
 * rattachement à toutes les entités par un couple polymorphe `cible_type`/`cible_id` :
 * deux colonnes, aucune clé étrangère, et une collection qui n'en était pas une —
 * impossible à mapper en OneToMany, donc impossible à éditer avec le widget de
 * collection des écrans. On lui substitue ici de VRAIES relations : une colonne de
 * parent par entité, exactement comme les quinze qui existaient déjà. Le couple
 * polymorphe est donc retiré : deux façons d'écrire le même rattachement en auraient
 * fait deux vérités concurrentes, et la rubrique Documents n'aurait pas dit la même
 * chose que l'assistant.
 *
 * VINGT-HUIT COLONNES, ET C'EST ASSUMÉ. C'est le prix d'une intégrité référentielle
 * réelle (la base emporte les pièces avec leur objet, sans code applicatif) et d'une
 * collection Doctrine véritable, seule forme que le widget des formulaires sait éditer.
 *
 * PAS DE PERTE DE DONNÉE. Les colonnes retirées n'ont jamais servi en production : le
 * mécanisme polymorphe n'a vécu qu'une demi-journée, entre deux commits du même jour, et
 * aucun rattachement n'a été écrit par cette voie. Le `down()` les rétablit à vide.
 *
 * Écrite à la main, comme les précédentes : un `doctrine:migrations:diff` sur ce dépôt
 * embarquerait une soixantaine de renommages d'index et quelques dérives de colonnes
 * (crm_tache, coupon, notification_sinistre…) sans aucun rapport avec ce lot.
 */
final class Version20260815203000 extends AbstractMigration
{
    /** colonne => [table référencée, nom de contrainte, nom d'index] */
    private const RELATIONS = [
        'assureur_id'              => ['assureur',              'FK_D8698A7680F7E20A', 'IDX_D8698A7680F7E20A'],
        'autorite_fiscale_id'      => ['autorite_fiscale',      'FK_D8698A76F862C47A', 'IDX_D8698A76F862C47A'],
        'charge_id'                => ['charge',                'FK_D8698A7655284914', 'IDX_D8698A7655284914'],
        'charge_courtier_id'       => ['charge_courtier',       'FK_D8698A76F1D0A50C', 'IDX_D8698A76F1D0A50C'],
        'chargement_id'            => ['chargement',            'FK_D8698A76B8FBE502', 'IDX_D8698A76B8FBE502'],
        'chargement_pour_prime_id' => ['chargement_pour_prime', 'FK_D8698A766DB4D52D', 'IDX_D8698A766DB4D52D'],
        'condition_partage_id'     => ['condition_partage',     'FK_D8698A7626164BCB', 'IDX_D8698A7626164BCB'],
        'contact_id'               => ['contact',               'FK_D8698A76E7A1254A', 'IDX_D8698A76E7A1254A'],
        'depense_id'               => ['depense',               'FK_D8698A7641D81563', 'IDX_D8698A7641D81563'],
        'depense_courtier_id'      => ['depense_courtier',      'FK_D8698A769BA297CD', 'IDX_D8698A769BA297CD'],
        'entreprise_rattachee_id'  => ['entreprise',            'FK_D8698A762CD670FF', 'IDX_D8698A762CD670FF'],
        'evaluation_id'            => ['evaluation',            'FK_D8698A76456C5646', 'IDX_D8698A76456C5646'],
        'groupe_id'                => ['groupe',                'FK_D8698A767A45358C', 'IDX_D8698A767A45358C'],
        'invite_rattache_id'       => ['invite',                'FK_D8698A76DE7C3C82', 'IDX_D8698A76DE7C3C82'],
        'modele_piece_sinistre_id' => ['modele_piece_sinistre', 'FK_D8698A761D833FD2', 'IDX_D8698A761D833FD2'],
        'monnaie_id'               => ['monnaie',               'FK_D8698A7698D3FE22', 'IDX_D8698A7698D3FE22'],
        'note_id'                  => ['note',                  'FK_D8698A7626ED0855', 'IDX_D8698A7626ED0855'],
        'notification_sinistre_id' => ['notification_sinistre', 'FK_D8698A76F4F2559E', 'IDX_D8698A76F4F2559E'],
        'objectif_id'              => ['objectif',              'FK_D8698A76157D1AD4', 'IDX_D8698A76157D1AD4'],
        // `operation` est un mot réservé MySQL : il se cite, sans quoi la contrainte échoue.
        'operation_id'             => ['`operation`',           'FK_D8698A7644AC3583', 'IDX_D8698A7644AC3583'],
        'portefeuille_id'          => ['portefeuille',          'FK_D8698A76513EC3CA', 'IDX_D8698A76513EC3CA'],
        'reglement_taxe_id'        => ['reglement_taxe',        'FK_D8698A76A8784A1E', 'IDX_D8698A76A8784A1E'],
        'revenu_pour_courtier_id'  => ['revenu_pour_courtier',  'FK_D8698A76A443B639', 'IDX_D8698A76A443B639'],
        'risque_id'                => ['risque',                'FK_D8698A764ECC2413', 'IDX_D8698A764ECC2413'],
        'taxe_id'                  => ['taxe',                  'FK_D8698A761AB947A4', 'IDX_D8698A761AB947A4'],
        'taxe_vente_id'            => ['taxe_vente',            'FK_D8698A76EF4AB1',   'IDX_D8698A76EF4AB1'],
        'tranche_id'               => ['tranche',               'FK_D8698A76B76F6B31', 'IDX_D8698A76B76F6B31'],
        'type_revenu_id'           => ['type_revenu',           'FK_D8698A7620F3EE6A', 'IDX_D8698A7620F3EE6A'],
    ];

    public function getDescription(): string
    {
        return "Documents : une vraie collection sur chaque entité métier (28 relations), en remplacement du couple polymorphe.";
    }

    public function up(Schema $schema): void
    {
        // 1. Le couple polymorphe s'en va, index d'abord.
        $this->addSql('DROP INDEX idx_document_cible ON document');
        $this->addSql('ALTER TABLE document DROP cible_type, DROP cible_id');

        // 2. Une colonne de parent par entité, toutes nullables : un document a UNE
        //    origine, pas vingt-huit.
        $colonnes = array_map(
            static fn (string $colonne) => 'ADD ' . $colonne . ' INT DEFAULT NULL',
            array_keys(self::RELATIONS),
        );
        $this->addSql('ALTER TABLE document ' . implode(', ', $colonnes));

        // 3. Les contraintes et leurs index. C'est ce que le couple polymorphe ne
        //    pouvait pas offrir : la base garantit elle-même qu'un document ne pointe
        //    pas dans le vide.
        foreach (self::RELATIONS as $colonne => [$table, $contrainte, $index]) {
            $this->addSql(sprintf(
                'ALTER TABLE document ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id)',
                $contrainte,
                $colonne,
                $table,
            ));
            $this->addSql(sprintf('CREATE INDEX %s ON document (%s)', $index, $colonne));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RELATIONS as $colonne => [$table, $contrainte, $index]) {
            $this->addSql(sprintf('ALTER TABLE document DROP FOREIGN KEY %s', $contrainte));
            $this->addSql(sprintf('DROP INDEX %s ON document', $index));
        }

        $colonnes = array_map(
            static fn (string $colonne) => 'DROP ' . $colonne,
            array_keys(self::RELATIONS),
        );
        $this->addSql('ALTER TABLE document ' . implode(', ', $colonnes));

        $this->addSql('ALTER TABLE document ADD cible_type VARCHAR(80) DEFAULT NULL, ADD cible_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_cible ON document (cible_type, cible_id)');
    }
}
