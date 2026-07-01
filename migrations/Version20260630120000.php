<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paiement réel PSP-agnostique — cycle de vie d'achat + numérotation des factures.
 *
 * Enrichit token_purchase (prestataire, référence prestataire idempotente,
 * numéro de facture, dates d'encaissement / remboursement, motif d'échec) et
 * crée invoice_counter (séquence annuelle des factures). Backfill : les achats
 * existants (simulés) sont marqués provider='simulated' et paid_at=created_at ;
 * leur statut historique 'paid_simulated' est conservé (compté comme revenu).
 * Modifications additives — aucune donnée perdue.
 */
final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement réel PSP-agnostique : cycle de vie token_purchase + invoice_counter.';
    }

    public function up(Schema $schema): void
    {
        // Nouveaux attributs du cycle de vie de paiement sur l'achat de tokens.
        $this->addSql("ALTER TABLE token_purchase
            ADD provider VARCHAR(40) DEFAULT NULL,
            ADD provider_reference VARCHAR(120) DEFAULT NULL,
            ADD invoice_number VARCHAR(30) DEFAULT NULL,
            ADD paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD refunded_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD failed_reason VARCHAR(255) DEFAULT NULL");

        // Unicité : la référence prestataire est la clé d'idempotence (webhook + retour) ;
        // le numéro de facture doit être unique sur toute la base.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TOKEN_PURCHASE_PROVIDER_REF ON token_purchase (provider_reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TOKEN_PURCHASE_INVOICE_NUMBER ON token_purchase (invoice_number)');

        // Backfill des achats historiques (simulés) : prestataire + date d'encaissement.
        $this->addSql("UPDATE token_purchase SET provider = 'simulated' WHERE provider IS NULL");
        $this->addSql('UPDATE token_purchase SET paid_at = created_at WHERE paid_at IS NULL');

        // Compteur de numérotation des factures (une ligne par année civile).
        $this->addSql('CREATE TABLE invoice_counter (
            id INT AUTO_INCREMENT NOT NULL,
            annee INT NOT NULL,
            sequence INT NOT NULL,
            UNIQUE INDEX UNIQ_INVOICE_COUNTER_ANNEE (annee),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invoice_counter');
        $this->addSql('DROP INDEX UNIQ_TOKEN_PURCHASE_PROVIDER_REF ON token_purchase');
        $this->addSql('DROP INDEX UNIQ_TOKEN_PURCHASE_INVOICE_NUMBER ON token_purchase');
        $this->addSql('ALTER TABLE token_purchase
            DROP provider,
            DROP provider_reference,
            DROP invoice_number,
            DROP paid_at,
            DROP refunded_at,
            DROP failed_reason');
    }
}
