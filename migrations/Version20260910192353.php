<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA FRANCHISE DE REPRISE : ce que la reprise offre à un cabinet, et ce qu'elle a déjà
 * offert.
 *
 * ⚠ ÉCRITE À LA MAIN, ET PAS PAR `migrations:diff`. Le diff proposait deux cents requêtes :
 * des renommages d'index et des recréations de clés étrangères sans aucun rapport, dus à
 * des divergences anciennes entre le schéma de développement et les entités. Les emporter
 * dans cette migration aurait fait passer une réorganisation massive du schéma sous
 * couvert d'un ajout de tarif — et personne ne l'aurait relue.
 */
final class Version20260910192353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reprise de données : seuil de lignes offertes (paramétrable en console) et compteur consommé par run.';
    }

    public function up(Schema $schema): void
    {
        // Le seuil, réglable en console et ANNONCÉ SUR LE SITE PUBLIC. NULL = on applique
        // la constante du barème (TokenPricing::ECHANGE_FRANCHISE_LIGNES), comme tous les
        // autres réglages de cette table.
        $this->addSql('ALTER TABLE plateforme_parametres ADD echange_franchise_lignes INT DEFAULT NULL');

        // Ce qu'un dépôt a réellement consommé de cette franchise. Il vit sur le RUN et non
        // sur l'occurrence d'échange : une occurrence n'est écrite qu'à la fin d'un import
        // réussi, si bien qu'un import interrompu aurait écrit des lignes gratuites sans
        // laisser de trace — et il aurait suffi de redéposer puis d'échouer pour obtenir
        // une franchise sans fin.
        $this->addSql('ALTER TABLE echange_import_run ADD lignes_franchisees INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres DROP echange_franchise_lignes');
        $this->addSql('ALTER TABLE echange_import_run DROP lignes_franchisees');
    }
}
