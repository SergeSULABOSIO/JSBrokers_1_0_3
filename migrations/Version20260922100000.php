<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La POLITIQUE DES FOURNISSEURS de Ket passe en base, pilotable depuis la console.
 *
 * ── POURQUOI ──────────────────────────────────────────────────────────────────
 * Le moteur de texte, la voix, les oreilles, la phase de compréhension et la
 * finition de dictée sont cinq chaînes de fournisseurs. Leur ordre, leur choix et
 * leurs réglages vivaient dans le `.env` : changer de voix demandait un accès
 * serveur et un redémarrage. Les agents Joseara doivent pouvoir épingler un
 * fournisseur, en écarter un, ou laisser la chaîne jouer — depuis la console.
 *
 * ── UNE SEULE COLONNE, ET NULLABLE ────────────────────────────────────────────
 * Une colonne JSON par famille : c'est déjà le parti pris de `packs`,
 * `write_weights` et `document_formats` sur cette même table. NULL signifie
 * « rien de personnalisé » — la plateforme se comporte alors exactement comme
 * avant, sur les variables d'environnement. Aucun déploiement ne change de
 * comportement du seul fait de cette migration.
 *
 * ── LES CLÉS D'API N'Y SONT PAS ───────────────────────────────────────────────
 * Et c'est délibéré : la console pilote la POLITIQUE (ordre, épinglage, modèles,
 * voix), jamais les secrets. Ceux-ci restent en `.env`, comme tous les secrets de
 * ce projet — aucun n'a jamais été stocké en base, et les sauvegardes n'ont pas à
 * le devenir. L'écran affiche seulement si la clé est présente.
 */
final class Version20260922100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Politique des fournisseurs de Ket : plateforme_parametres.ket_fournisseurs (JSON, nullable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres ADD ket_fournisseurs JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plateforme_parametres DROP ket_fournisseurs');
    }
}
