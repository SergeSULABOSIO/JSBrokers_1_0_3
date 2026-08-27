<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN REVERSEMENT DE RÉTRO PEUT ALLER À UN PARTENAIRE EXTERNE.
 *
 * Le bénéficiaire devient un agent interne OU un partenaire externe — jamais les deux,
 * jamais aucun. Le partenaire envoie SA note de débit : il facture le cabinet, le cabinet
 * lui reverse et garde la pièce. Son circuit de règlement est donc celui de l'agent, ce qui
 * permet de tenir les deux familles sur un seul enregistrement, une seule liste, un seul
 * écran.
 *
 * Auparavant sa rétrocommission se facturait par NOTE DE CRÉDIT et son « payé » se déduisait
 * du prorata des règlements de cette note : aucun enregistrement de versement n'existait
 * pour lui. C'est ce déséquilibre que ce lot referme.
 *
 * ── `agent_id` DEVIENT NULLABLE, ET C'EST LE CŒUR DE CETTE MIGRATION ────────────────
 *
 * Sans cela, aucune ligne ne pourrait désigner un partenaire. La contrainte « l'un OU
 * l'autre » ne peut pas s'exprimer en SQL portable — une CHECK sur deux colonnes n'est pas
 * gérée uniformément — elle est donc tenue côté applicatif, en 422, exactement comme
 * `ConditionPartage` le fait déjà pour le même XOR.
 *
 * AUCUNE DONNÉE N'EST TOUCHÉE : les lignes existantes portent toutes leur agent et le
 * gardent. La nullabilité ouvre une possibilité, elle ne modifie rien.
 */
final class Version20260824200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reversement de rétrocommission : bénéficiaire agent OU partenaire (XOR), agent devenu nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reversement_retro_agent ADD partenaire_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE reversement_retro_agent '
            . 'ADD CONSTRAINT FK_RRA_PARTENAIRE FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)'
        );
        $this->addSql('CREATE INDEX IDX_RRA_PARTENAIRE ON reversement_retro_agent (partenaire_id)');

        // L'agent cesse d'être obligatoire : le bénéficiaire peut être un partenaire.
        $this->addSql('ALTER TABLE reversement_retro_agent CHANGE agent_id agent_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Redescendre exigerait que toute ligne porte un agent : celles qui désignent un
        // partenaire l'empêcheraient. On ne force donc rien — on retire seulement ce que
        // cette migration a ajouté, en laissant `agent_id` nullable.
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_RRA_PARTENAIRE');
        $this->addSql('DROP INDEX IDX_RRA_PARTENAIRE ON reversement_retro_agent');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP partenaire_id');
    }
}
