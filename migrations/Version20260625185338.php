<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625185338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents comptables : TVA déductible sur les dépenses (depense.taux_tva) '
            . 'et capital social / date de constitution (plateforme_parametres).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE depense ADD taux_tva NUMERIC(5, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE plateforme_parametres ADD capital_social NUMERIC(14, 2) DEFAULT NULL, ADD date_constitution DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE depense DROP taux_tva');
        $this->addSql('ALTER TABLE plateforme_parametres DROP capital_social, DROP date_constitution');
    }
}
