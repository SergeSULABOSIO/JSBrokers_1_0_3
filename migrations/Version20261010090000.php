<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ÉCHÉANCES « À RÉGULARISER » APRÈS UNE ANNULATION OU UNE RÉSILIATION.
 *
 * Schéma seulement : l'état (`regularisation`), le mouvement qui l'a posé
 * (`regularisation_par_id`, mis à NULL si l'opportunité dérivée disparaît) et l'état
 * d'avant (`regularisation_avant`), restitué à l'abandon du mouvement.
 *
 * LE RATTRAPAGE DES DONNÉES N'EST PAS ICI. Savoir si une échéance a déjà été encaissée
 * demande la règle PHP complète (paiements déclarés, notes, inférences de bordereau) :
 * un UPDATE SQL marquerait « à régulariser » une échéance réputée payée. Il est fait par
 * la commande idempotente `app:echeances:regulariser --rattrapage`, que bin/deploy.sh
 * appelle après les migrations (et qui accepte --dry-run pour un examen préalable).
 */
final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échéances à régulariser après annulation / résiliation (état, mouvement, état d’avant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tranche ADD regularisation_par_id INT DEFAULT NULL, ADD regularisation VARCHAR(20) DEFAULT NULL, ADD regularisation_avant VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_66675840CB863EB9 FOREIGN KEY (regularisation_par_id) REFERENCES piste (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_66675840CB863EB9 ON tranche (regularisation_par_id)');
    }

    /** Retour arrière : les trois colonnes disparaissent avec les marquages qu'elles portent. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_66675840CB863EB9');
        $this->addSql('DROP INDEX IDX_66675840CB863EB9 ON tranche');
        $this->addSql('ALTER TABLE tranche DROP regularisation_par_id, DROP regularisation, DROP regularisation_avant');
    }
}
