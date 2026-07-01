<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiscalité — suivi des reversements de TVA à l'autorité fiscale.
 *
 * Crée reglement_taxe : chaque paiement de TVA nette (par période mois/année)
 * dû par JS Brokers à une autorité. Permet d'afficher, dans la Fiscalité, le
 * montant dû (collectée − déductible), le montant payé et le solde dû, et de
 * générer l'écriture comptable correspondante. Modification additive.
 */
final class Version20260630160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiscalité : reglement_taxe (reversements de TVA à l\'autorité fiscale).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE reglement_taxe (
            id INT AUTO_INCREMENT NOT NULL,
            autorite VARCHAR(120) NOT NULL,
            annee INT NOT NULL,
            mois INT NOT NULL,
            montant NUMERIC(14, 2) NOT NULL,
            date_paiement DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            moyen_paiement VARCHAR(20) NOT NULL,
            reference VARCHAR(80) DEFAULT NULL,
            note LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_REGLEMENT_TAXE_PERIODE (annee, mois),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reglement_taxe');
    }
}
