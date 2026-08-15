<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traitement asynchrone des messages de l'assistant IA.
 *
 *  - `assistant_tache` : l'ÉTAT d'une question (en attente / en cours / terminée
 *    / échouée), interrogeable par conversation et par ordre d'arrivée.
 *  - `assistant_conversation.traitement_depuis` : le VERROU qui garantit qu'un
 *    seul worker draine une conversation à la fois (cf. VerrouDeConversation).
 *  - `messenger_messages` : le transport du SIGNAL. La table n'a jamais existé
 *    dans ce dépôt — MESSENGER_TRANSPORT_DSN porte `auto_setup=0`, donc elle ne
 *    peut pas naître toute seule au premier dispatch.
 *
 * ⚠️ CETTE MIGRATION NE CORRIGE PAS LA DÉRIVE DE SCHÉMA EXISTANTE. Un
 * `doctrine:migrations:diff` sur ce dépôt propose aussi une soixantaine de
 * renommages d'index (noms explicites posés à la main contre noms générés par
 * Doctrine) sans aucun rapport avec l'assistant. Les embarquer ici mêlerait un
 * chantier à un autre et rendrait ce lot irréversible sans risque. Ils sont
 * laissés en l'état, à traiter séparément.
 */
final class Version20260815115215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Assistant IA : file de traitement des messages (tâches, verrou de conversation, transport Messenger).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assistant_tache (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, repond_a_id INT DEFAULT NULL, message_utilisateur_id INT DEFAULT NULL, message_assistant_id INT DEFAULT NULL, contenu LONGTEXT NOT NULL, contexte_objets JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', fichiers_joints JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', statut VARCHAR(16) NOT NULL, etape JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', erreur LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', finished_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AE9CE2AD9AC0396 (conversation_id), INDEX IDX_AE9CE2ADEB5A4A82 (repond_a_id), INDEX IDX_AE9CE2ADA51FB507 (message_utilisateur_id), INDEX IDX_AE9CE2AD2452B209 (message_assistant_id), INDEX idx_tache_file (conversation_id, statut, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE assistant_tache ADD CONSTRAINT FK_AE9CE2AD9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_tache ADD CONSTRAINT FK_AE9CE2ADEB5A4A82 FOREIGN KEY (repond_a_id) REFERENCES assistant_message (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assistant_tache ADD CONSTRAINT FK_AE9CE2ADA51FB507 FOREIGN KEY (message_utilisateur_id) REFERENCES assistant_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_tache ADD CONSTRAINT FK_AE9CE2AD2452B209 FOREIGN KEY (message_assistant_id) REFERENCES assistant_message (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE assistant_conversation ADD traitement_depuis DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // Schéma standard du transport Doctrine de Messenger. Recopié ici plutôt
        // que laissé à `auto_setup` : la création d'une table au premier dispatch
        // est un effet de bord qu'on ne veut pas en production.
        // Les trois horodatages portent le commentaire DC2Type:datetime_immutable :
        // c'est ainsi que symfony/doctrine-messenger déclare sa table au schéma.
        // Sans eux, chaque `doctrine:schema:update` proposerait éternellement de
        // les rajouter — un écart cosmétique, mais qui pollue tous les diffs
        // suivants et finit par masquer les vrais.
        $this->addSql('CREATE TABLE IF NOT EXISTS messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_tache DROP FOREIGN KEY FK_AE9CE2AD9AC0396');
        $this->addSql('ALTER TABLE assistant_tache DROP FOREIGN KEY FK_AE9CE2ADEB5A4A82');
        $this->addSql('ALTER TABLE assistant_tache DROP FOREIGN KEY FK_AE9CE2ADA51FB507');
        $this->addSql('ALTER TABLE assistant_tache DROP FOREIGN KEY FK_AE9CE2AD2452B209');
        $this->addSql('DROP TABLE assistant_tache');
        $this->addSql('ALTER TABLE assistant_conversation DROP traitement_depuis');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
