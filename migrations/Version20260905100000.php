<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE PÉRIMÈTRE CHOISI AU DÉPÔT SURVIT JUSQU'À LA CONFIRMATION.
 *
 * L'importation laisse désormais retenir une partie du fichier — la production sans les
 * paramètres, par exemple. Mais l'écriture RECONTRÔLE intégralement le classeur au moment
 * de confirmer : c'est ce qui la protège d'un état devenu faux entre le contrôle et le
 * clic. Elle repart donc du fichier entier.
 *
 * Sans cette colonne, confirmer un import volontairement restreint réécrirait aussi les
 * feuilles écartées — et rien, ni à l'écran ni au rapport, ne l'aurait annoncé. Le choix
 * de l'utilisateur doit vivre aussi longtemps que le contrôle auquel il appartient.
 *
 * Vide = tout ce que le fichier contient, ce qui est le cas de tous les contrôles déjà
 * en base : la reprise se fait donc d'elle-même, sans rien recalculer.
 */
final class Version20260905100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échange : mémorise sur le contrôle d\'import les données retenues au dépôt.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE echange_import_run ADD donnees JSON NOT NULL COMMENT '(DC2Type:json)'");

        // ⚠ MySQL remplit les lignes existantes avec la CHAÎNE VIDE, que le type JSON de
        // Doctrine ne sait pas désérialiser : toute lecture d'un contrôle antérieur
        // lèverait. On pose donc le tableau vide PARTOUT — qui signifie « tout le
        // fichier », c'est-à-dire exactement le comportement de ces contrôles-là.
        $this->addSql("UPDATE echange_import_run SET donnees = '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echange_import_run DROP donnees');
    }
}
