<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Signaler qu'une police n'est PAS à renouveler, avec un motif écrit pour le futur.
 *
 * Une police peut arriver à échéance sans qu'il y ait rien à renouveler (client parti,
 * véhicule vendu, activité cessée). Sans ces colonnes, elle restait à jamais dans le chip
 * « Échus », le widget Renouvellements, la vigie de l'assistante et sa boussole — au même
 * rang qu'un vrai renouvellement en retard.
 *
 * COLONNE SUR L'AVENANT, et non sur la Piste : la décision porte sur CETTE police, pas sur
 * la nature du contrat. L'ancien chemin (Piste.renewal_condition = « temporaire non
 * renouvelable ») réécrivait l'historique de l'opportunité et, surtout, ne faisait sortir
 * la police d'aucune vue.
 *
 * non_renouvelable_leve_le date une LEVÉE de marquage. Les trois colonnes de trace sont
 * alors conservées : effacer le motif à la levée supprimerait précisément ce qu'on cherche
 * à garder — pourquoi on avait cru que le client partait, et qui l'avait consigné. Seul le
 * booléen gouverne l'appartenance au pipeline d'échéance.
 *
 * renewal_status n'est PAS touché : la couverture court jusqu'à son terme, la police reste
 * active et sa prime reste dans les totaux. Le recouvrement de ce qui reste dû continue.
 */
final class Version20260804140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Avenant : décision de non-renouvellement (marquage réversible + motif, auteur et dates).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE avenant ADD non_renouvelable TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE avenant ADD non_renouvelable_motif LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE avenant ADD non_renouvelable_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE avenant ADD non_renouvelable_leve_le DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE avenant ADD non_renouvelable_par_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_AVENANT_NON_RENOUVELABLE_PAR ON avenant (non_renouvelable_par_id)');
        // SET NULL : la suppression d'un invité ne doit pas emporter la décision qu'il a prise.
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_AVENANT_NON_RENOUVELABLE_PAR FOREIGN KEY (non_renouvelable_par_id) REFERENCES invite (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_AVENANT_NON_RENOUVELABLE_PAR');
        $this->addSql('DROP INDEX IDX_AVENANT_NON_RENOUVELABLE_PAR ON avenant');
        $this->addSql('ALTER TABLE avenant DROP non_renouvelable_par_id');
        $this->addSql('ALTER TABLE avenant DROP non_renouvelable_leve_le');
        $this->addSql('ALTER TABLE avenant DROP non_renouvelable_le');
        $this->addSql('ALTER TABLE avenant DROP non_renouvelable_motif');
        $this->addSql('ALTER TABLE avenant DROP non_renouvelable');
    }
}
