<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UNE AFFAIRE N'A QU'UN SEUL INTERMÉDIAIRE.
 *
 * `Piste.partenaires` (ManyToMany) devient `Piste.partenaire` (ManyToOne). Le champ acceptait
 * plusieurs apporteurs, mais le moteur n'en a jamais retenu qu'un : `getCotationPartenaire()`
 * prenait le PREMIER d'une table de liaison — laquelle n'a aucun ordre défini. L'écran
 * promettait donc un partage multi-apporteurs que le calcul ne savait pas faire, et le tableau
 * de bord comptait un même avenant sous chacun des partenaires listés.
 *
 * ⚠ Le diff Doctrine proposait 192 instructions, dont 186 sans rapport avec ce lot (renommages
 * d'index sur crm_*, assistant_document, coupon…). Cette migration a été écrite à la main : elle
 * ne contient QUE ce changement — et la reprise des données, que le diff ne sait pas générer.
 */
final class Version20260820122109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Piste : un seul intermédiaire (ManyToMany → ManyToOne), avec reprise du rattachement existant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE piste ADD partenaire_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E2507798DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)');
        $this->addSql('CREATE INDEX IDX_59E2507798DE13AC ON piste (partenaire_id)');

        // REPRISE DES RATTACHEMENTS EXISTANTS, avant de perdre la table de liaison.
        //
        // MIN() et non « le premier » : une table de liaison n'a pas d'ordre, et
        // `->first()` ne rendait qu'un résultat reproductible par accident. Puisqu'il faut
        // choisir, autant que le choix soit déterministe et qu'il soit dit.
        //
        // Une affaire qui portait PLUSIEURS intermédiaires n'en garde donc qu'un — c'est le
        // resserrement voulu. Au moment de l'écriture, aucune affaire n'était concernée
        // (`SELECT COUNT(DISTINCT piste_id) FROM piste_partenaire` = 0 en dev comme en test).
        $this->addSql('UPDATE piste p SET p.partenaire_id = (SELECT MIN(pp.partenaire_id) FROM piste_partenaire pp WHERE pp.piste_id = p.id)');

        $this->addSql('ALTER TABLE piste_partenaire DROP FOREIGN KEY FK_6110D3B398DE13AC');
        $this->addSql('ALTER TABLE piste_partenaire DROP FOREIGN KEY FK_6110D3B3C34065BC');
        $this->addSql('DROP TABLE piste_partenaire');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE piste_partenaire (piste_id INT NOT NULL, partenaire_id INT NOT NULL, INDEX IDX_6110D3B3C34065BC (piste_id), INDEX IDX_6110D3B398DE13AC (partenaire_id), PRIMARY KEY(piste_id, partenaire_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE piste_partenaire ADD CONSTRAINT FK_6110D3B398DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piste_partenaire ADD CONSTRAINT FK_6110D3B3C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id) ON DELETE CASCADE');

        // Le retour en arrière restitue le rattachement, mais pas ceux que le resserrement
        // avait écartés : ils n'existent plus nulle part. La réversibilité est structurelle,
        // pas intégrale — autant l'écrire ici que de le laisser découvrir.
        $this->addSql('INSERT INTO piste_partenaire (piste_id, partenaire_id) SELECT id, partenaire_id FROM piste WHERE partenaire_id IS NOT NULL');

        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E2507798DE13AC');
        $this->addSql('DROP INDEX IDX_59E2507798DE13AC ON piste');
        $this->addSql('ALTER TABLE piste DROP partenaire_id');
    }
}
