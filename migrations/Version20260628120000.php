<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Support : les messages du formulaire de contact de la vitrine deviennent des
 * tickets. Le demandeur pouvant être anonyme (pas de compte), on rend client_id
 * nullable et on ajoute ses coordonnées (contact_*). On crée aussi la table des
 * feedbacks (notes internes des collaborateurs sur un ticket). Modifications
 * additives/compatibles : aucun ticket existant n'est impacté.
 */
final class Version20260628120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support : tickets issus du formulaire de contact + fil de feedbacks.';
    }

    public function up(Schema $schema): void
    {
        // client_id devient nullable (visiteur anonyme du formulaire de contact).
        // On retire puis recrée la contrainte autour du MODIFY (ON DELETE CASCADE conservé).
        $this->addSql('ALTER TABLE crm_ticket DROP FOREIGN KEY FK_CRM_TICKET_CLIENT');
        $this->addSql('ALTER TABLE crm_ticket MODIFY client_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_ticket ADD CONSTRAINT FK_CRM_TICKET_CLIENT FOREIGN KEY (client_id) REFERENCES utilisateur (id) ON DELETE CASCADE');

        // Coordonnées du demandeur anonyme.
        $this->addSql('ALTER TABLE crm_ticket
            ADD contact_nom VARCHAR(120) DEFAULT NULL,
            ADD contact_email VARCHAR(180) DEFAULT NULL,
            ADD contact_telephone VARCHAR(30) DEFAULT NULL');

        // Fil des feedbacks (notes internes des collaborateurs).
        $this->addSql('CREATE TABLE crm_ticket_feedback (
            id INT AUTO_INCREMENT NOT NULL,
            ticket_id INT NOT NULL,
            auteur_id INT DEFAULT NULL,
            contenu LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_CRM_TICKET_FB_TICKET (ticket_id),
            INDEX IDX_CRM_TICKET_FB_AUTEUR (auteur_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crm_ticket_feedback ADD CONSTRAINT FK_CRM_TICKET_FB_TICKET FOREIGN KEY (ticket_id) REFERENCES crm_ticket (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_ticket_feedback ADD CONSTRAINT FK_CRM_TICKET_FB_AUTEUR FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crm_ticket_feedback');

        $this->addSql('ALTER TABLE crm_ticket DROP contact_nom, DROP contact_email, DROP contact_telephone');

        // Restaure client_id NOT NULL (les tickets anonymes doivent être purgés au préalable).
        $this->addSql('ALTER TABLE crm_ticket DROP FOREIGN KEY FK_CRM_TICKET_CLIENT');
        $this->addSql('ALTER TABLE crm_ticket MODIFY client_id INT NOT NULL');
        $this->addSql('ALTER TABLE crm_ticket ADD CONSTRAINT FK_CRM_TICKET_CLIENT FOREIGN KEY (client_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }
}
