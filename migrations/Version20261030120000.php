<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN REVERSEMENT EST UNE PIÈCE COMPTABLE INTANGIBLE (Serge, 2026-10-30) : il ne se corrige
 * pas, il se CONTRE-PASSE — une ligne de montant opposé qui pointe vers celle qu'elle annule.
 * Unique : une ligne ne se contre-passe qu'une fois. RESTRICT : l'original ne disparaît pas
 * sous sa contre-passation. Aucune ligne existante n'est touchée (colonne nulle).
 */
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reversement : lien de contre-passation (ligne de montant opposé vers celle qu\'elle annule).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reversement_retro_agent ADD contre_passation_de_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reversement_retro_agent ADD CONSTRAINT FK_REVERSEMENT_CONTRE_PASSATION_DE FOREIGN KEY (contre_passation_de_id) REFERENCES reversement_retro_agent (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_62F1D80F5D2EEF51 ON reversement_retro_agent (contre_passation_de_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reversement_retro_agent DROP FOREIGN KEY FK_REVERSEMENT_CONTRE_PASSATION_DE');
        $this->addSql('DROP INDEX UNIQ_62F1D80F5D2EEF51 ON reversement_retro_agent');
        $this->addSql('ALTER TABLE reversement_retro_agent DROP contre_passation_de_id');
    }
}
