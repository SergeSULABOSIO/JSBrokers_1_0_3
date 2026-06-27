<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM Support : rattache un ticket à l'entreprise depuis laquelle il a été émis
 * (renseignée pour les demandes self-service ouvertes depuis l'espace de travail
 * du courtier). Colonne additive et nullable (ON DELETE SET NULL) : aucun impact
 * sur les tickets existants ni sur ceux saisis depuis la console.
 */
final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM : ajout de l\'entreprise émettrice sur les tickets de support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_ticket ADD entreprise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_ticket ADD CONSTRAINT FK_CRM_TICKET_ENTREPRISE FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CRM_TICKET_ENTREPRISE ON crm_ticket (entreprise_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_ticket DROP FOREIGN KEY FK_CRM_TICKET_ENTREPRISE');
        $this->addSql('DROP INDEX IDX_CRM_TICKET_ENTREPRISE ON crm_ticket');
        $this->addSql('ALTER TABLE crm_ticket DROP entreprise_id');
    }
}
