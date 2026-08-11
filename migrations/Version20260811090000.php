<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DOCUMENTS PRODUITS PAR KET : la table des livrables, et le barème qui les tarife.
 *
 * Jusqu'ici, aucun fichier né du serveur n'était conservé — facture PDF, export de
 * bulle, classeur comptable sont tous rendus à la volée. Un document produit par
 * l'assistant, lui, a été PAYÉ en tokens : le perdre au premier rechargement de page
 * reviendrait à le faire payer deux fois. D'où une vraie table.
 *
 * Deux blocs indépendants :
 *
 *  1. `assistant_document` — le livrable. `message_id` est UNIQUE : c'est l'anti-rejeu
 *     au niveau de la base. La meta du message porte bien un drapeau
 *     `documentPlanExecuted`, mais un drapeau se contourne par une course entre deux
 *     requêtes ; une contrainte d'unicité, non. Les FK sont en ON DELETE CASCADE pour
 *     que la base reste cohérente, mais la suppression applicative passe par l'ORM
 *     (cascade: remove + orphanRemoval) — c'est ce chemin-là, et lui seul, qui fait
 *     effacer le binaire par Vich.
 *
 *  2. quatre colonnes NULLABLE sur `plateforme_parametres` — le barème éditable en
 *     console. NULL y signifie « utiliser la constante du code » : c'est tout le
 *     contrat de cette entité, et c'est pourquoi rien n'est inséré ici. Trois
 *     scalaires séparés plutôt qu'un JSON unique, afin que chacun porte son propre
 *     repli : personnaliser le coût de base ne doit pas emporter le prix de la page.
 *
 * RÉVERSIBLE, contrairement aux migrations de données : on ne perd qu'un réglage.
 */
final class Version20260811090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents produits par l\'assistant IA : table des livrables + barème tarifaire éditable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE assistant_document (
                id INT AUTO_INCREMENT NOT NULL,
                conversation_id INT NOT NULL,
                message_id INT NOT NULL,
                entreprise_id INT NOT NULL,
                auteur_id INT DEFAULT NULL,
                titre VARCHAR(255) NOT NULL,
                nom_fichier VARCHAR(255) NOT NULL,
                format VARCHAR(8) NOT NULL,
                mime VARCHAR(160) NOT NULL,
                taille INT NOT NULL,
                caracteres INT NOT NULL,
                pages INT NOT NULL,
                cout_tokens INT NOT NULL,
                nom_fichier_stocke VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_ASSISTANT_DOCUMENT_CONVERSATION (conversation_id),
                INDEX IDX_ASSISTANT_DOCUMENT_ENTREPRISE (entreprise_id),
                INDEX IDX_ASSISTANT_DOCUMENT_AUTEUR (auteur_id),
                UNIQUE INDEX uniq_assistant_document_message (message_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE assistant_document ADD CONSTRAINT FK_ASSISTANT_DOCUMENT_CONVERSATION FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_document ADD CONSTRAINT FK_ASSISTANT_DOCUMENT_MESSAGE FOREIGN KEY (message_id) REFERENCES assistant_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_document ADD CONSTRAINT FK_ASSISTANT_DOCUMENT_ENTREPRISE FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_document ADD CONSTRAINT FK_ASSISTANT_DOCUMENT_AUTEUR FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE plateforme_parametres
                ADD document_base INT DEFAULT NULL,
                ADD document_par_page INT DEFAULT NULL,
                ADD document_caracteres_par_page INT DEFAULT NULL,
                ADD document_formats JSON DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE assistant_document');
        $this->addSql(<<<'SQL'
            ALTER TABLE plateforme_parametres
                DROP document_base,
                DROP document_par_page,
                DROP document_caracteres_par_page,
                DROP document_formats
        SQL);
    }
}
