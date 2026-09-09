<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE TERMINAL D'ORIGINE D'UNE QUESTION POSÉE À KET.
 *
 * Joseara sert deux surfaces : la conversation en plein écran sur téléphone et
 * tablette, l'espace de travail à colonnes sur ordinateur. Ket ne doit donc pas
 * disposer, sur un téléphone, des outils qui ouvrent une rubrique ou une fiche —
 * elle promettrait un écran qui n'arrivera jamais.
 *
 * Or le traitement d'une question peut être ASYNCHRONE (ASSISTANT_ASYNC, worker
 * Messenger) : au moment où l'outil s'exécute, la requête HTTP qui portait le
 * terminal n'existe plus. Le terminal voyage donc AVEC la tâche, comme un
 * instantané pris à l'envoi — exactement comme `contexte_objets` juste à côté.
 *
 * NULLABLE, et `null` vaut « ordinateur » côté PHP : les tâches déjà en file au
 * moment du déploiement n'en portent pas, et elles doivent se traiter comme
 * avant. Aucune reprise de données n'est donc nécessaire.
 *
 * ⚠ CETTE MIGRATION A ÉTÉ RÉDUITE À LA MAIN. `migrations:diff` régénère aussi
 * une dérive de schéma PRÉEXISTANTE (renommages d'index et de clés étrangères
 * du module CRM, valeurs par défaut de `coupon`, `reglement_taxe`,
 * `token_purchase`…) qui n'a rien à voir avec ce changement. La rejouer ici
 * l'aurait rendue inséparable du présent ajout — et un retour en arrière aurait
 * emporté les deux. Une migration = un changement.
 */
final class Version20260909153245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Terminal d'origine d'une question posée à l'assistant (assistant_tache.terminal).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_tache ADD terminal VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_tache DROP terminal');
    }
}
