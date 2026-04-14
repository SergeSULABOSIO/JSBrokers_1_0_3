<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260413122029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');

        // --- Entités sans dépendances (ou dépendant de `invite` qui est déjà là) ---
        $this->addSql('ALTER TABLE assureur ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE assureur SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE assureur CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE assureur ADD CONSTRAINT FK_7B0E5955EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_7B0E5955EA417747 ON assureur (invite_id)');

        $this->addSql('ALTER TABLE bordereau ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE bordereau b JOIN invite i ON b.invite_id = i.id SET b.entreprise_id = i.entreprise_id WHERE b.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE bordereau CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE bordereau ADD CONSTRAINT FK_F7B4C561A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_F7B4C561A4AEAFEA ON bordereau (entreprise_id)');

        $this->addSql('ALTER TABLE chargement ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE chargement SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE chargement CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE chargement ADD CONSTRAINT FK_36328758EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_36328758EA417747 ON chargement (invite_id)');

        $this->addSql('ALTER TABLE classeur ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE classeur SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE classeur CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE classeur ADD CONSTRAINT FK_D15F835AEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_D15F835AEA417747 ON classeur (invite_id)');

        $this->addSql('ALTER TABLE client ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE client SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE client CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_C7440455EA417747 ON client (invite_id)');

        $this->addSql('ALTER TABLE compte_bancaire ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE compte_bancaire SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE compte_bancaire CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE compte_bancaire ADD CONSTRAINT FK_50BC21DEEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_50BC21DEEA417747 ON compte_bancaire (invite_id)');

        $this->addSql('ALTER TABLE groupe ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE groupe SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE groupe CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE groupe ADD CONSTRAINT FK_4B98C21EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_4B98C21EA417747 ON groupe (invite_id)');

        $this->addSql('ALTER TABLE modele_piece_sinistre ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE modele_piece_sinistre ADD CONSTRAINT FK_3723B98AEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_3723B98AEA417747 ON modele_piece_sinistre (invite_id)');

        $this->addSql('ALTER TABLE monnaie ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE monnaie SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE monnaie CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE monnaie ADD CONSTRAINT FK_B3A6E2E6EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_B3A6E2E6EA417747 ON monnaie (invite_id)');

        $this->addSql('ALTER TABLE partenaire ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE partenaire SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE partenaire CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE partenaire ADD CONSTRAINT FK_32FFA373EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_32FFA373EA417747 ON partenaire (invite_id)');

        $this->addSql('ALTER TABLE risque ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE risque SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE risque CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE risque ADD CONSTRAINT FK_20230D24EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_20230D24EA417747 ON risque (invite_id)');

        $this->addSql('ALTER TABLE taxe ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE taxe SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE taxe CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE taxe ADD CONSTRAINT FK_56322FE9EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_56322FE9EA417747 ON taxe (invite_id)');

        $this->addSql('ALTER TABLE type_revenu ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE type_revenu SET entreprise_id = (SELECT id FROM entreprise ORDER BY id LIMIT 1) WHERE entreprise_id IS NULL');
        $this->addSql('ALTER TABLE type_revenu CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE type_revenu ADD CONSTRAINT FK_5E74AB7DEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_5E74AB7DEA417747 ON type_revenu (invite_id)');

        $this->addSql('ALTER TABLE roles_en_administration ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE roles_en_administration r JOIN invite i ON r.invite_id = i.id SET r.entreprise_id = i.entreprise_id WHERE r.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE roles_en_administration CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_administration ADD CONSTRAINT FK_E4BAB4BAA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_E4BAB4BAA4AEAFEA ON roles_en_administration (entreprise_id)');
        $this->addSql('ALTER TABLE roles_en_finance ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE roles_en_finance r JOIN invite i ON r.invite_id = i.id SET r.entreprise_id = i.entreprise_id WHERE r.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE roles_en_finance CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_finance ADD CONSTRAINT FK_D3801708A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_D3801708A4AEAFEA ON roles_en_finance (entreprise_id)');
        $this->addSql('ALTER TABLE roles_en_marketing ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE roles_en_marketing r JOIN invite i ON r.invite_id = i.id SET r.entreprise_id = i.entreprise_id WHERE r.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE roles_en_marketing CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_marketing ADD CONSTRAINT FK_C12A8B85A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_C12A8B85A4AEAFEA ON roles_en_marketing (entreprise_id)');
        $this->addSql('ALTER TABLE roles_en_production ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE roles_en_production r JOIN invite i ON r.invite_id = i.id SET r.entreprise_id = i.entreprise_id WHERE r.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE roles_en_production CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_production ADD CONSTRAINT FK_D0384CFA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_D0384CFA4AEAFEA ON roles_en_production (entreprise_id)');
        $this->addSql('ALTER TABLE roles_en_sinistre ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE roles_en_sinistre r JOIN invite i ON r.invite_id = i.id SET r.entreprise_id = i.entreprise_id WHERE r.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE roles_en_sinistre CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE roles_en_sinistre ADD CONSTRAINT FK_5B60B8D0A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_5B60B8D0A4AEAFEA ON roles_en_sinistre (entreprise_id)');

        // --- Entités de Niveau 1 (dépendant des précédentes) ---
        $this->addSql('ALTER TABLE autorite_fiscale ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE autorite_fiscale af JOIN taxe t ON af.taxe_id = t.id SET af.entreprise_id = t.entreprise_id, af.invite_id = t.invite_id WHERE af.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE autorite_fiscale CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE autorite_fiscale ADD CONSTRAINT FK_FF484230A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE autorite_fiscale ADD CONSTRAINT FK_FF484230EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_FF484230A4AEAFEA ON autorite_fiscale (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_FF484230EA417747 ON autorite_fiscale (invite_id)');

        $this->addSql('ALTER TABLE contact ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE contact c JOIN client cl ON c.client_id = cl.id SET c.entreprise_id = cl.entreprise_id, c.invite_id = cl.invite_id WHERE c.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE contact CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT FK_4C62E638A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT FK_4C62E638EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_4C62E638A4AEAFEA ON contact (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_4C62E638EA417747 ON contact (invite_id)');

        $this->addSql('ALTER TABLE piste ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE piste p JOIN invite i ON p.invite_id = i.id SET p.entreprise_id = i.entreprise_id WHERE p.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE piste CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE piste ADD CONSTRAINT FK_59E25077A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_59E25077A4AEAFEA ON piste (entreprise_id)');

        $this->addSql('ALTER TABLE note ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE note n JOIN invite i ON n.invite_id = i.id SET n.entreprise_id = i.entreprise_id WHERE n.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE note CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_CFBDFA14A4AEAFEA ON note (entreprise_id)');

        $this->addSql('ALTER TABLE notification_sinistre ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE notification_sinistre ns JOIN invite i ON ns.invite_id = i.id SET ns.entreprise_id = i.entreprise_id WHERE ns.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE notification_sinistre CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE notification_sinistre ADD CONSTRAINT FK_A0BC42C2A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_A0BC42C2A4AEAFEA ON notification_sinistre (entreprise_id)');

        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720758ABD09BB');
        $this->addSql('DROP INDEX IDX_938720758ABD09BB ON tache');
        $this->addSql('ALTER TABLE tache ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE executor_id invite_id INT DEFAULT NULL');
        $this->addSql('UPDATE tache t JOIN invite i ON t.invite_id = i.id SET t.entreprise_id = i.entreprise_id WHERE t.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE tache CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_93872075A4AEAFEA ON tache (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_93872075EA417747 ON tache (invite_id)');

        // --- Entités de Niveau 2 (dépendant des précédentes) ---
        // --- Article ---
        $this->addSql('ALTER TABLE article ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE article a JOIN note n ON a.note_id = n.id SET a.entreprise_id = n.entreprise_id, a.invite_id = n.invite_id WHERE a.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE article CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_23A0E66A4AEAFEA ON article (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_23A0E66EA417747 ON article (invite_id)');

        $this->addSql('ALTER TABLE cotation ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE cotation c JOIN piste p ON c.piste_id = p.id SET c.entreprise_id = p.entreprise_id, c.invite_id = p.invite_id WHERE c.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE cotation CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE cotation ADD CONSTRAINT FK_996DA944A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE cotation ADD CONSTRAINT FK_996DA944EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_996DA944A4AEAFEA ON cotation (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_996DA944EA417747 ON cotation (invite_id)');

        $this->addSql('ALTER TABLE feedback ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE feedback f JOIN tache t ON f.tache_id = t.id SET f.entreprise_id = t.entreprise_id, f.invite_id = t.invite_id WHERE f.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE feedback CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE feedback ADD CONSTRAINT FK_D2294458A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_D2294458A4AEAFEA ON feedback (entreprise_id)');

        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE offre_indemnisation_sinistre ois JOIN notification_sinistre ns ON ois.notification_sinistre_id = ns.id SET ois.entreprise_id = ns.entreprise_id, ois.invite_id = ns.invite_id WHERE ois.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre ADD CONSTRAINT FK_D73D1ECCEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_D73D1ECCA4AEAFEA ON offre_indemnisation_sinistre (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_D73D1ECCEA417747 ON offre_indemnisation_sinistre (invite_id)');

        $this->addSql('ALTER TABLE paiement ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE paiement p JOIN note n ON p.note_id = n.id SET p.entreprise_id = n.entreprise_id, p.invite_id = n.invite_id WHERE p.entreprise_id IS NULL AND p.note_id IS NOT NULL');
        $this->addSql('ALTER TABLE paiement CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_B1DC7A1EA4AEAFEA ON paiement (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_B1DC7A1EEA417747 ON paiement (invite_id)');

        $this->addSql('ALTER TABLE piece_sinistre ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE piece_sinistre ps JOIN notification_sinistre ns ON ps.notification_sinistre_id = ns.id SET ps.entreprise_id = ns.entreprise_id, ps.invite_id = ns.invite_id WHERE ps.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE piece_sinistre CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE piece_sinistre ADD CONSTRAINT FK_C1DE1588A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_C1DE1588A4AEAFEA ON piece_sinistre (entreprise_id)');

        // --- Entités de Niveau 3 (dépendant des précédentes) ---
        $this->addSql('ALTER TABLE avenant ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE avenant av JOIN cotation c ON av.cotation_id = c.id SET av.entreprise_id = c.entreprise_id, av.invite_id = c.invite_id WHERE av.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE avenant CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_2FE5CE5A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE avenant ADD CONSTRAINT FK_2FE5CE5EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_2FE5CE5A4AEAFEA ON avenant (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_2FE5CE5EA417747 ON avenant (invite_id)');

        $this->addSql('ALTER TABLE chargement_pour_prime ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL');
        $this->addSql('UPDATE chargement_pour_prime cpp JOIN cotation c ON cpp.cotation_id = c.id SET cpp.entreprise_id = c.entreprise_id, cpp.invite_id = c.invite_id WHERE cpp.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE chargement_pour_prime CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE chargement_pour_prime ADD CONSTRAINT FK_8065D3CAA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE chargement_pour_prime ADD CONSTRAINT FK_8065D3CAEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_8065D3CAA4AEAFEA ON chargement_pour_prime (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_8065D3CAEA417747 ON chargement_pour_prime (invite_id)');

        $this->addSql('ALTER TABLE tranche ADD COLUMN IF NOT EXISTS entreprise_id INT NULL, ADD COLUMN IF NOT EXISTS invite_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE tranche t JOIN cotation c ON t.cotation_id = c.id SET t.entreprise_id = c.entreprise_id, t.invite_id = c.invite_id WHERE t.entreprise_id IS NULL');
        $this->addSql('ALTER TABLE tranche CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_66675840A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_66675840EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_66675840A4AEAFEA ON tranche (entreprise_id)');
        $this->addSql('CREATE INDEX IDX_66675840EA417747 ON tranche (invite_id)');

        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66A4AEAFEA');
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66EA417747');
        $this->addSql('DROP INDEX IDX_23A0E66A4AEAFEA ON article');
        $this->addSql('DROP INDEX IDX_23A0E66EA417747 ON article');
        $this->addSql('ALTER TABLE article DROP entreprise_id, DROP invite_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE assureur DROP FOREIGN KEY FK_7B0E5955EA417747');
        $this->addSql('DROP INDEX IDX_7B0E5955EA417747 ON assureur');
        $this->addSql('ALTER TABLE assureur DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE autorite_fiscale DROP FOREIGN KEY FK_FF484230A4AEAFEA');
        $this->addSql('ALTER TABLE autorite_fiscale DROP FOREIGN KEY FK_FF484230EA417747');
        $this->addSql('DROP INDEX IDX_FF484230A4AEAFEA ON autorite_fiscale');
        $this->addSql('DROP INDEX IDX_FF484230EA417747 ON autorite_fiscale');
        $this->addSql('ALTER TABLE autorite_fiscale DROP entreprise_id, DROP invite_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_2FE5CE5A4AEAFEA');
        $this->addSql('ALTER TABLE avenant DROP FOREIGN KEY FK_2FE5CE5EA417747');
        $this->addSql('DROP INDEX IDX_2FE5CE5A4AEAFEA ON avenant');
        $this->addSql('DROP INDEX IDX_2FE5CE5EA417747 ON avenant');
        $this->addSql('ALTER TABLE avenant DROP entreprise_id, DROP invite_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE bordereau DROP FOREIGN KEY FK_F7B4C561A4AEAFEA');
        $this->addSql('DROP INDEX IDX_F7B4C561A4AEAFEA ON bordereau');
        $this->addSql('ALTER TABLE bordereau DROP entreprise_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE chargement DROP FOREIGN KEY FK_36328758EA417747');
        $this->addSql('DROP INDEX IDX_36328758EA417747 ON chargement');
        $this->addSql('ALTER TABLE chargement DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE chargement_pour_prime DROP FOREIGN KEY FK_8065D3CAA4AEAFEA');
        $this->addSql('ALTER TABLE chargement_pour_prime DROP FOREIGN KEY FK_8065D3CAEA417747');
        $this->addSql('DROP INDEX IDX_8065D3CAA4AEAFEA ON chargement_pour_prime');
        $this->addSql('DROP INDEX IDX_8065D3CAEA417747 ON chargement_pour_prime');
        $this->addSql('ALTER TABLE chargement_pour_prime DROP entreprise_id, DROP invite_id');
        $this->addSql('ALTER TABLE classeur DROP FOREIGN KEY FK_D15F835AEA417747');
        $this->addSql('DROP INDEX IDX_D15F835AEA417747 ON classeur');
        $this->addSql('ALTER TABLE classeur DROP invite_id, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C7440455EA417747');
        $this->addSql('DROP INDEX IDX_C7440455EA417747 ON client');
        $this->addSql('ALTER TABLE client DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE compte_bancaire DROP FOREIGN KEY FK_50BC21DEEA417747');
        $this->addSql('DROP INDEX IDX_50BC21DEEA417747 ON compte_bancaire');
        $this->addSql('ALTER TABLE compte_bancaire DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE condition_partage DROP FOREIGN KEY FK_CF012D1FA4AEAFEA');
        $this->addSql('ALTER TABLE condition_partage DROP FOREIGN KEY FK_CF012D1FEA417747');
        $this->addSql('DROP INDEX IDX_CF012D1FA4AEAFEA ON condition_partage');
        $this->addSql('DROP INDEX IDX_CF012D1FEA417747 ON condition_partage');
        $this->addSql('ALTER TABLE condition_partage DROP entreprise_id, DROP invite_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_4C62E638A4AEAFEA');
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_4C62E638EA417747');
        $this->addSql('DROP INDEX IDX_4C62E638A4AEAFEA ON contact');
        $this->addSql('DROP INDEX IDX_4C62E638EA417747 ON contact');
        $this->addSql('ALTER TABLE contact DROP entreprise_id, DROP invite_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE cotation DROP FOREIGN KEY FK_996DA944A4AEAFEA');
        $this->addSql('ALTER TABLE cotation DROP FOREIGN KEY FK_996DA944EA417747');
        $this->addSql('DROP INDEX IDX_996DA944A4AEAFEA ON cotation');
        $this->addSql('DROP INDEX IDX_996DA944EA417747 ON cotation');
        $this->addSql('ALTER TABLE cotation DROP entreprise_id, DROP invite_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A4AEAFEA');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76EA417747');
        $this->addSql('DROP INDEX IDX_D8698A76A4AEAFEA ON document');
        $this->addSql('DROP INDEX IDX_D8698A76EA417747 ON document');
        $this->addSql('ALTER TABLE document DROP entreprise_id, DROP invite_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE feedback DROP FOREIGN KEY FK_D2294458A4AEAFEA');
        $this->addSql('DROP INDEX IDX_D2294458A4AEAFEA ON feedback');
        $this->addSql('ALTER TABLE feedback DROP entreprise_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE groupe DROP FOREIGN KEY FK_4B98C21EA417747');
        $this->addSql('DROP INDEX IDX_4B98C21EA417747 ON groupe');
        $this->addSql('ALTER TABLE groupe DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE modele_piece_sinistre DROP FOREIGN KEY FK_3723B98AEA417747');
        $this->addSql('DROP INDEX IDX_3723B98AEA417747 ON modele_piece_sinistre');
        $this->addSql('ALTER TABLE modele_piece_sinistre DROP invite_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE monnaie DROP FOREIGN KEY FK_B3A6E2E6EA417747');
        $this->addSql('DROP INDEX IDX_B3A6E2E6EA417747 ON monnaie');
        $this->addSql('ALTER TABLE monnaie DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14A4AEAFEA');
        $this->addSql('DROP INDEX IDX_CFBDFA14A4AEAFEA ON note');
        $this->addSql('ALTER TABLE note DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE notification_sinistre DROP FOREIGN KEY FK_A0BC42C2A4AEAFEA');
        $this->addSql('DROP INDEX IDX_A0BC42C2A4AEAFEA ON notification_sinistre');
        $this->addSql('ALTER TABLE notification_sinistre DROP entreprise_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCA4AEAFEA');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP FOREIGN KEY FK_D73D1ECCEA417747');
        $this->addSql('DROP INDEX IDX_D73D1ECCA4AEAFEA ON offre_indemnisation_sinistre');
        $this->addSql('DROP INDEX IDX_D73D1ECCEA417747 ON offre_indemnisation_sinistre');
        $this->addSql('ALTER TABLE offre_indemnisation_sinistre DROP entreprise_id, DROP invite_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EA4AEAFEA');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EEA417747');
        $this->addSql('DROP INDEX IDX_B1DC7A1EA4AEAFEA ON paiement');
        $this->addSql('DROP INDEX IDX_B1DC7A1EEA417747 ON paiement');
        $this->addSql('ALTER TABLE paiement DROP entreprise_id, DROP invite_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE partenaire DROP FOREIGN KEY FK_32FFA373EA417747');
        $this->addSql('DROP INDEX IDX_32FFA373EA417747 ON partenaire');
        $this->addSql('ALTER TABLE partenaire DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE piece_sinistre DROP FOREIGN KEY FK_C1DE1588A4AEAFEA');
        $this->addSql('DROP INDEX IDX_C1DE1588A4AEAFEA ON piece_sinistre');
        $this->addSql('ALTER TABLE piece_sinistre DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE piste DROP FOREIGN KEY FK_59E25077A4AEAFEA');
        $this->addSql('DROP INDEX IDX_59E25077A4AEAFEA ON piste');
        $this->addSql('ALTER TABLE piste DROP entreprise_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE revenu_pour_courtier CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE risque DROP FOREIGN KEY FK_20230D24EA417747');
        $this->addSql('DROP INDEX IDX_20230D24EA417747 ON risque');
        $this->addSql('ALTER TABLE risque DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles_en_administration DROP FOREIGN KEY FK_E4BAB4BAA4AEAFEA');
        $this->addSql('DROP INDEX IDX_E4BAB4BAA4AEAFEA ON roles_en_administration');
        $this->addSql('ALTER TABLE roles_en_administration DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE roles_en_finance DROP FOREIGN KEY FK_D3801708A4AEAFEA');
        $this->addSql('DROP INDEX IDX_D3801708A4AEAFEA ON roles_en_finance');
        $this->addSql('ALTER TABLE roles_en_finance DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE roles_en_marketing DROP FOREIGN KEY FK_C12A8B85A4AEAFEA');
        $this->addSql('DROP INDEX IDX_C12A8B85A4AEAFEA ON roles_en_marketing');
        $this->addSql('ALTER TABLE roles_en_marketing DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE roles_en_production DROP FOREIGN KEY FK_D0384CFA4AEAFEA');
        $this->addSql('DROP INDEX IDX_D0384CFA4AEAFEA ON roles_en_production');
        $this->addSql('ALTER TABLE roles_en_production DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE roles_en_sinistre DROP FOREIGN KEY FK_5B60B8D0A4AEAFEA');
        $this->addSql('DROP INDEX IDX_5B60B8D0A4AEAFEA ON roles_en_sinistre');
        $this->addSql('ALTER TABLE roles_en_sinistre DROP entreprise_id, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075A4AEAFEA');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075EA417747');
        $this->addSql('DROP INDEX IDX_93872075A4AEAFEA ON tache');
        $this->addSql('DROP INDEX IDX_93872075EA417747 ON tache');
        $this->addSql('ALTER TABLE tache DROP entreprise_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE invite_id executor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720758ABD09BB FOREIGN KEY (executor_id) REFERENCES invite (id)');
        $this->addSql('CREATE INDEX IDX_938720758ABD09BB ON tache (executor_id)');
        $this->addSql('ALTER TABLE taxe DROP FOREIGN KEY FK_56322FE9EA417747');
        $this->addSql('DROP INDEX IDX_56322FE9EA417747 ON taxe');
        $this->addSql('ALTER TABLE taxe DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_66675840A4AEAFEA');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_66675840EA417747');
        $this->addSql('DROP INDEX IDX_66675840A4AEAFEA ON tranche');
        $this->addSql('DROP INDEX IDX_66675840EA417747 ON tranche');
        $this->addSql('ALTER TABLE tranche DROP entreprise_id, DROP invite_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE type_revenu DROP FOREIGN KEY FK_5E74AB7DEA417747');
        $this->addSql('DROP INDEX IDX_5E74AB7DEA417747 ON type_revenu');
        $this->addSql('ALTER TABLE type_revenu DROP invite_id, DROP created_at, DROP updated_at, CHANGE entreprise_id entreprise_id INT DEFAULT NULL');
    }
}
