<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rétrocommission des AGENTS INTERNES : la condition de partage gagne un second
 * bénéficiaire, et le cabinet trace ce qu'il leur reverse.
 *
 *  1. condition_partage.agent_id  → le bénéficiaire interne, miroir de partenaire_id ;
 *  2. piste_condition_partage     → table de liaison N-N : une même condition (« prime
 *     apporteur 15 % ») se rattache à autant d'affaires que l'agent en apporte, sans
 *     être dupliquée. C'est ce qui en fait une source unique de vérité, là où
 *     condition_partage.piste_id (inchangé) désigne une condition propre à UNE piste,
 *     clonée au renouvellement ;
 *  3. reversement_retro_agent     → ce qui a été effectivement versé, avenant par
 *     avenant. `lot_reference` regroupe les lignes d'un même virement, pour que la
 *     comptabilité émette une écriture par versement réel et non par affaire ;
 *  4. document.reversement_retro_agent_id → les preuves de versement.
 *
 * NOTE — la version auto-générée de cette migration embarquait une centaine
 * d'instructions SANS AUCUN RAPPORT : renommages d'index vers les conventions Doctrine
 * sur assistant_document, crm_*, coupon, token_purchase, invoice_counter, plus la
 * SUPPRESSION de IDX_REGLEMENT_TAXE_PERIODE et une réécriture du défaut de
 * notification_sinistre.lieu (avec ses accents cassés au passage). Ces index portent des
 * noms explicites voulus par le projet ; les renommer n'apporte rien et le faire ici les
 * aurait liés à un chantier qui ne les concerne pas. Elles ont été retirées.
 */
final class Version20260817224220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rétrocommission des agents internes : bénéficiaire agent sur la condition de partage, '
            . 'rattachement N-N aux pistes, et reversements tracés par ligne d\'affaire.';
    }

    public function up(Schema $schema): void
    {
        // 1. Le bénéficiaire interne, à côté du partenaire externe.
        $this->addSql('ALTER TABLE condition_partage ADD agent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE condition_partage ADD CONSTRAINT FK_CF012D1F3414710B FOREIGN KEY (agent_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_CF012D1F3414710B ON condition_partage (agent_id)');

        // 2. Rattachement N-N : la condition est PRÊTÉE à l'affaire, jamais possédée par
        // elle — d'où le ON DELETE CASCADE sur la seule ligne de liaison.
        $this->addSql('CREATE TABLE piste_condition_partage (piste_id INT NOT NULL, condition_partage_id INT NOT NULL, INDEX IDX_7B024F17C34065BC (piste_id), INDEX IDX_7B024F1726164BCB (condition_partage_id), PRIMARY KEY(piste_id, condition_partage_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE piste_condition_partage ADD CONSTRAINT FK_7B024F17C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piste_condition_partage ADD CONSTRAINT FK_7B024F1726164BCB FOREIGN KEY (condition_partage_id) REFERENCES condition_partage (id) ON DELETE CASCADE');

        // 3. Les reversements. Aucun ON DELETE : un décaissement réel, déjà comptabilisé,
        // ne doit pas disparaître dans le sillage d'une suppression d'avenant.
        $this->addSql('CREATE TABLE reversement_retro_agent (id INT AUTO_INCREMENT NOT NULL, agent_id INT NOT NULL, avenant_id INT NOT NULL, compte_bancaire_id INT DEFAULT NULL, entreprise_id INT NOT NULL, invite_id INT DEFAULT NULL, montant DOUBLE PRECISION NOT NULL, paid_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', reference VARCHAR(255) NOT NULL, lot_reference VARCHAR(255) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_62F1D80F3414710B (agent_id), INDEX IDX_62F1D80F85631A3A (avenant_id), INDEX IDX_62F1D80FAF1E371E (compte_bancaire_id), INDEX IDX_62F1D80FA4AEAFEA (entreprise_id), INDEX IDX_62F1D80FEA417747 (invite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reversement_retro_agent ADD CONSTRAINT FK_62F1D80F3414710B FOREIGN KEY (agent_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE reversement_retro_agent ADD CONSTRAINT FK_62F1D80F85631A3A FOREIGN KEY (avenant_id) REFERENCES avenant (id)');
        $this->addSql('ALTER TABLE reversement_retro_agent ADD CONSTRAINT FK_62F1D80FAF1E371E FOREIGN KEY (compte_bancaire_id) REFERENCES compte_bancaire (id)');
        $this->addSql('ALTER TABLE reversement_retro_agent ADD CONSTRAINT FK_62F1D80FA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE reversement_retro_agent ADD CONSTRAINT FK_62F1D80FEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        // Recherche du lot : une écriture comptable par virement réel se construit en
        // regroupant sur cette colonne, sur tout l'historique de l'entreprise.
        $this->addSql('CREATE INDEX IDX_RETRO_AGENT_LOT ON reversement_retro_agent (entreprise_id, lot_reference)');

        // 4. Les preuves de versement, via le rattachement universel des documents.
        $this->addSql('ALTER TABLE document ADD reversement_retro_agent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76EE460FF2 FOREIGN KEY (reversement_retro_agent_id) REFERENCES reversement_retro_agent (id)');
        $this->addSql('CREATE INDEX IDX_D8698A76EE460FF2 ON document (reversement_retro_agent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76EE460FF2');
        $this->addSql('DROP INDEX IDX_D8698A76EE460FF2 ON document');
        $this->addSql('ALTER TABLE document DROP reversement_retro_agent_id');

        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_62F1D80F3414710B');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_62F1D80F85631A3A');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_62F1D80FAF1E371E');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_62F1D80FA4AEAFEA');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_62F1D80FEA417747');
        $this->addSql('DROP TABLE reversement_retro_agent');

        $this->addSql('ALTER TABLE piste_condition_partage DROP FOREIGN KEY FK_7B024F17C34065BC');
        $this->addSql('ALTER TABLE piste_condition_partage DROP FOREIGN KEY FK_7B024F1726164BCB');
        $this->addSql('DROP TABLE piste_condition_partage');

        $this->addSql('ALTER TABLE condition_partage DROP FOREIGN KEY FK_CF012D1F3414710B');
        $this->addSql('DROP INDEX IDX_CF012D1F3414710B ON condition_partage');
        $this->addSql('ALTER TABLE condition_partage DROP agent_id');
    }
}
