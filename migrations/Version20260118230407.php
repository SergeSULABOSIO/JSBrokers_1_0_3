<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260118230407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76DFB9960E FOREIGN KEY (piece_sinistre_id) REFERENCES piece_sinistre (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7672DDD90D FOREIGN KEY (offre_indemnisation_sinistre_id) REFERENCES offre_indemnisation_sinistre (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A765D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7685631A3A FOREIGN KEY (avenant_id) REFERENCES avenant (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D2235D39 FOREIGN KEY (tache_id) REFERENCES tache (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D249A887 FOREIGN KEY (feedback_id) REFERENCES feedback (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7619EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7655D5304E FOREIGN KEY (bordereau_id) REFERENCES bordereau (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76AF1E371E FOREIGN KEY (compte_bancaire_id) REFERENCES compte_bancaire (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7698DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A762A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id)');
        $this->addSql('ALTER TABLE feedback ADD CONSTRAINT FK_D2294458D2235D39 FOREIGN KEY (tache_id) REFERENCES tache (id)');
        $this->addSql('ALTER TABLE feedback ADD CONSTRAINT FK_D2294458EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE groupe ADD CONSTRAINT FK_4B98C21A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE modele_piece_sinistre ADD CONSTRAINT FK_3723B98AA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE monnaie ADD CONSTRAINT FK_B3A6E2E6A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1419EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1498DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA1480F7E20A FOREIGN KEY (assureur_id) REFERENCES assureur (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14D8FB132F FOREIGN KEY (autoritefiscale_id) REFERENCES autorite_fiscale (id)');
        $this->addSql('ALTER TABLE note_compte_bancaire ADD CONSTRAINT FK_956D59AE26ED0855 FOREIGN KEY (note_id) REFERENCES note (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_compte_bancaire ADD CONSTRAINT FK_956D59AEAF1E371E FOREIGN KEY (compte_bancaire_id) REFERENCES compte_bancaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C21F4BE942 FOREIGN KEY (assure_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C24ECC2413 FOREIGN KEY (risque_id) REFERENCES risque (id)');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C2EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C280F7E20A FOREIGN KEY (assureur_id) REFERENCES assureur (id)');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCF4F2559E FOREIGN KEY (notification_sinistre_id) REFERENCES notification_sinistre (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E72DDD90D FOREIGN KEY (offre_indemnisation_sinistre_id) REFERENCES offre_indemnisation_sinistre (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E26ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EAF1E371E FOREIGN KEY (compte_bancaire_id) REFERENCES compte_bancaire (id)');
        $this->addSql('ALTER TABLE partenaire ADD CONSTRAINT FK_32FFA373A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588C54C8C93 FOREIGN KEY (type_id) REFERENCES modele_piece_sinistre (id)');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588F4F2559E FOREIGN KEY (notification_sinistre_id) REFERENCES notification_sinistre (id)');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E250774ECC2413 FOREIGN KEY (risque_id) REFERENCES risque (id)');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E2507719EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E25077EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E25077DF6F6FEC FOREIGN KEY (avenant_de_base_id) REFERENCES avenant (id)');
        $this->addSql('ALTER TABLE piste_partenaire ADD CONSTRAINT FK_6110D3B3C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piste_partenaire ADD CONSTRAINT FK_6110D3B398DE13AC FOREIGN KEY (partenaire_id) REFERENCES partenaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C120F3EE6A FOREIGN KEY (type_revenu_id) REFERENCES type_revenu (id)');
        $this->addSql('ALTER TABLE revenu_pour_courtier ADD CONSTRAINT FK_8CAA04C15D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('ALTER TABLE risque ADD CONSTRAINT FK_20230D24A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE risque ADD CONSTRAINT FK_20230D2426164BCB FOREIGN KEY (condition_partage_id) REFERENCES condition_partage (id)');
        $this->addSql('ALTER TABLE roles_en_administration ADD CONSTRAINT FK_E4BAB4BAEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE roles_en_finance ADD CONSTRAINT FK_D3801708EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE roles_en_marketing ADD CONSTRAINT FK_C12A8B85EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE roles_en_production ADD CONSTRAINT FK_D0384CFEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE roles_en_sinistre ADD CONSTRAINT FK_5B60B8D0EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720758ABD09BB FOREIGN KEY (executor_id) REFERENCES invite (id)');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075C34065BC FOREIGN KEY (piste_id) REFERENCES piste (id)');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720755D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075F4F2559E FOREIGN KEY (notification_sinistre_id) REFERENCES notification_sinistre (id)');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207572DDD90D FOREIGN KEY (offre_indemnisation_sinistre_id) REFERENCES offre_indemnisation_sinistre (id)');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE9A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_666758405D14FAF0 FOREIGN KEY (cotation_id) REFERENCES cotation (id)');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7DA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7DC8165237 FOREIGN KEY (type_chargement_id) REFERENCES chargement (id)');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7D26ED0855 FOREIGN KEY (note_id) REFERENCES note (id)');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7D7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B3318EA8F9 FOREIGN KEY (connected_to_id) REFERENCES entreprise (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76DFB9960E');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7672DDD90D');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A765D14FAF0');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7685631A3A');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D2235D39');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D249A887');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7619EB6921');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7655D5304E');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76AF1E371E');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76C34065BC');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7698DE13AC');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A762A4C4478');
        $this->addSql('ALTER TABLE feedback DROP FOREIGN KEY FK_D2294458D2235D39');
        $this->addSql('ALTER TABLE feedback DROP FOREIGN KEY FK_D2294458EA417747');
        $this->addSql('ALTER TABLE groupe DROP FOREIGN KEY FK_4B98C21A4AEAFEA');
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7EA417747');
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7A4AEAFEA');
        $this->addSql('ALTER TABLE modele_piece_sinistre DROP FOREIGN KEY FK_3723B98AA4AEAFEA');
        $this->addSql('ALTER TABLE monnaie DROP FOREIGN KEY FK_B3A6E2E6A4AEAFEA');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14EA417747');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1419EB6921');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1498DE13AC');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA1480F7E20A');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14D8FB132F');
        $this->addSql('ALTER TABLE note_compte_bancaire DROP FOREIGN KEY FK_956D59AE26ED0855');
        $this->addSql('ALTER TABLE note_compte_bancaire DROP FOREIGN KEY FK_956D59AEAF1E371E');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C21F4BE942');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C24ECC2413');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C2EA417747');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C280F7E20A');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCF4F2559E');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E72DDD90D');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E26ED0855');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EAF1E371E');
        $this->addSql('ALTER TABLE partenaire DROP FOREIGN KEY FK_32FFA373A4AEAFEA');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588C54C8C93');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588EA417747');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588F4F2559E');
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E250774ECC2413');
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E2507719EB6921');
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E25077EA417747');
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E25077DF6F6FEC');
        $this->addSql('ALTER TABLE piste_partenaire DROP FOREIGN KEY FK_6110D3B3C34065BC');
        $this->addSql('ALTER TABLE piste_partenaire DROP FOREIGN KEY FK_6110D3B398DE13AC');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C120F3EE6A');
        $this->addSql('ALTER TABLE revenu_pour_courtier DROP FOREIGN KEY FK_8CAA04C15D14FAF0');
        $this->addSql('ALTER TABLE risque DROP FOREIGN KEY FK_20230D24A4AEAFEA');
        $this->addSql('ALTER TABLE risque DROP FOREIGN KEY FK_20230D2426164BCB');
        $this->addSql('ALTER TABLE roles_en_administration DROP FOREIGN KEY FK_E4BAB4BAEA417747');
        $this->addSql('ALTER TABLE roles_en_finance DROP FOREIGN KEY FK_D3801708EA417747');
        $this->addSql('ALTER TABLE roles_en_marketing DROP FOREIGN KEY FK_C12A8B85EA417747');
        $this->addSql('ALTER TABLE roles_en_production DROP FOREIGN KEY FK_D0384CFEA417747');
        $this->addSql('ALTER TABLE roles_en_sinistre DROP FOREIGN KEY FK_5B60B8D0EA417747');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720758ABD09BB');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075C34065BC');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720755D14FAF0');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075F4F2559E');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_9387207572DDD90D');
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE9A4AEAFEA');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_666758405D14FAF0');
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7DA4AEAFEA');
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7DC8165237');
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7D26ED0855');
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7D7294869C');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B3318EA8F9');
    }
}
