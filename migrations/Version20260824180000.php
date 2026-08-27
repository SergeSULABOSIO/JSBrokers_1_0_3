<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE REVERSEMENT D'UNE RÉTROCOMMISSION SE RATTACHE À UNE TRANCHE.
 *
 * La prime ET la commission se paient par tranche : c'est donc à ce rythme que
 * l'intermédiaire est rémunéré. Le dû était déjà proratisé par tranche
 * (`TrancheIndicatorStrategy::retroAgentDue` via `getTrancheTauxFactor()`) tandis que le
 * versé restait accroché à l'avenant — dû et payé ne se comparaient jamais à la même
 * maille, et la colonne « rétro reversée » d'une tranche était indérivable.
 *
 * ── AUCUNE DONNÉE N'EST RÉÉCRITE, ET C'EST VOULU ────────────────────────────────────
 *
 * La tentation était de VENTILER les reversements existants sur les tranches de leur
 * cotation, au prorata du facteur déclaré. Deux raisons de ne pas le faire :
 *
 *  1. le facteur dépend, dans le cas d'un montant forfaitaire, de la prime totale de la
 *     cotation — une grandeur calculée par le moteur, hors de portée du SQL. La migration
 *     aurait donc dû inventer un repli, c'est-à-dire une formule ;
 *  2. un virement réel serait devenu plusieurs lignes. Le total, la date et la pièce
 *     auraient survécu, mais un rapprochement bancaire ligne à ligne, non.
 *
 * Les DEUX liens coexistent donc : `tranche` dit QUAND (la maille du fait), `avenant` dit
 * SUR QUOI (l'affaire réglée). La lecture compte les lignes anciennes au niveau de leur
 * cotation et les nouvelles à la tranche : le total par affaire est exact dans les deux
 * cas. Seule la précision PAR TRANCHE manque aux lignes anciennes — ce qui était déjà le
 * cas avant ce lot, et n'a donc rien perdu.
 *
 * `avenant` devient nullable pour la même raison : une cotation peut porter plusieurs
 * avenants, et une tranche n'en désigne aucun en particulier. Quand l'affaire est connue,
 * l'invariant `tranche.cotation === avenant.cotation` est vérifié côté applicatif.
 */
final class Version20260824180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reversement de rétrocommission : rattachement à une TRANCHE (maille du paiement), avenant devenu nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reversement_retro_agent ADD tranche_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE reversement_retro_agent '
            . 'ADD CONSTRAINT FK_RRA_TRANCHE FOREIGN KEY (tranche_id) REFERENCES tranche (id)'
        );
        $this->addSql('CREATE INDEX IDX_RRA_TRANCHE ON reversement_retro_agent (tranche_id)');

        // L'avenant cesse d'être obligatoire : la maille du fait est désormais la tranche.
        $this->addSql('ALTER TABLE reversement_retro_agent CHANGE avenant_id avenant_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Redescendre exige que tout reversement porte un avenant : les lignes créées à la
        // maille de la tranche sans avenant l'empêcheraient. On ne force donc rien — on
        // retire seulement ce que cette migration a ajouté.
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_RRA_TRANCHE');
        $this->addSql('DROP INDEX IDX_RRA_TRANCHE ON reversement_retro_agent');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP tranche_id');
    }
}
