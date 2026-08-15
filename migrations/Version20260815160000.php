<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rattachement UNIVERSEL d'un document à n'importe quel objet de la plateforme.
 *
 * Jusqu'ici, `document` portait quinze colonnes de parent — une par entité qu'un
 * fichier avait le droit d'accompagner (avenant, client, cotation, piste, paiement…).
 * Pour les soixante-deux autres, le serveur avertissait honnêtement que LE FICHIER NE
 * SERAIT PAS CONSERVÉ : la donnée extraite entrait en base, la pièce qui la justifiait
 * mourait avec la conversation de l'assistant.
 *
 * Le couple `cible_type` / `cible_id` supprime cette limite sans faire grossir la table
 * d'une colonne par entité nouvelle. Il n'est écrit QUE là où aucune relation typée
 * n'existe (règle centralisée dans PieceSourceRattachement) : les quinze colonnes
 * restent la forme canonique là où elles s'appliquent, et rien de l'existant ne change.
 *
 * PAS DE CLÉ ÉTRANGÈRE, et c'est le prix assumé du polymorphisme : aucune contrainte ne
 * peut pointer quinze tables à la fois. La suppression du parent est prise en charge
 * côté applicatif par DocumentsOrphelinsSubscriber. L'index composite est donc
 * indispensable — il sert à la fois la lecture (les documents d'un objet) et ce ménage.
 *
 * Écrite à la main, comme la précédente : un `doctrine:migrations:diff` sur ce dépôt
 * embarquerait une soixantaine de renommages d'index sans rapport avec ce lot.
 */
final class Version20260815160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Documents : rattachement universel (cible_type / cible_id) à n'importe quelle entité.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD cible_type VARCHAR(80) DEFAULT NULL, ADD cible_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_cible ON document (cible_type, cible_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_cible ON document');
        $this->addSql('ALTER TABLE document DROP cible_type, DROP cible_id');
    }
}
