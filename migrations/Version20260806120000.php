<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PROGRAMMES de Ket : une mission en plusieurs plans, validés l'un après l'autre.
 *
 * Jusqu'ici, une demande portant sur plusieurs objets (« signale le paiement des tranches
 * 60, 64 et 74 ») s'arrêtait après le premier plan : l'exécution d'un plan est hors-LLM et
 * ne crée aucun message, donc la conversation restait muette jusqu'à ce que l'utilisateur
 * relance — et l'assistante affirmait ensuite que toute la série était passée.
 *
 * La mémoire de la série ne pouvait donc pas vivre dans le modèle : elle vit ici, avec une
 * référence unique et un statut PAR ÉTAPE. C'est ce qui rend l'enchaînement déterministe
 * (le serveur prépare l'étape suivante lui-même) et le rapport final vérifiable (relecture
 * en base de chaque écriture, étapes non faites nommées).
 *
 * Le PLAN de chaque étape n'est pas dupliqué ici : il reste dans assistant_message.meta,
 * là où l'endpoint d'exécution le lit déjà. L'étape ne fait que pointer son message
 * (message_id ON DELETE SET NULL : purger un message ne doit pas effacer l'historique de
 * la mission).
 *
 * Écrite à la main plutôt que générée : un doctrine:migrations:diff embarquerait la dérive
 * de schéma préexistante du module CRM, sans rapport avec ce chantier.
 */
final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant IA : programmes (séries de plans) et leurs étapes, avec référence et statut.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE assistant_programme (
                id INT AUTO_INCREMENT NOT NULL,
                conversation_id INT NOT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT NOT NULL,
                corrige_id INT DEFAULT NULL,
                reference VARCHAR(24) NOT NULL,
                objectif LONGTEXT NOT NULL,
                statut VARCHAR(16) NOT NULL,
                rapport JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                closed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_BD49ED2CAEA34913 (reference),
                INDEX IDX_BD49ED2C9AC0396 (conversation_id),
                INDEX IDX_BD49ED2CA4AEAFEA (entreprise_id),
                INDEX IDX_BD49ED2CEA417747 (invite_id),
                INDEX IDX_BD49ED2C5E132043 (corrige_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assistant_programme_etape (
                id INT AUTO_INCREMENT NOT NULL,
                programme_id INT NOT NULL,
                message_id INT DEFAULT NULL,
                ordre INT NOT NULL,
                reference VARCHAR(32) NOT NULL,
                libelle VARCHAR(255) NOT NULL,
                outil VARCHAR(64) NOT NULL,
                arguments JSON NOT NULL COMMENT '(DC2Type:json)',
                statut VARCHAR(16) NOT NULL,
                journal JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                verification JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                erreur LONGTEXT DEFAULT NULL,
                INDEX IDX_29D7259662BB7AEE (programme_id),
                INDEX IDX_29D72596537A1329 (message_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE assistant_programme ADD CONSTRAINT FK_BD49ED2C9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_programme ADD CONSTRAINT FK_BD49ED2CA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_programme ADD CONSTRAINT FK_BD49ED2CEA417747 FOREIGN KEY (invite_id) REFERENCES invite (id) ON DELETE CASCADE');
        // Auto-référence : un programme de CORRECTION garde le lien vers la mission dont il
        // répare les écarts. SET NULL — purger l'ancienne mission ne doit pas emporter sa correction.
        $this->addSql('ALTER TABLE assistant_programme ADD CONSTRAINT FK_BD49ED2C5E132043 FOREIGN KEY (corrige_id) REFERENCES assistant_programme (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assistant_programme_etape ADD CONSTRAINT FK_29D7259662BB7AEE FOREIGN KEY (programme_id) REFERENCES assistant_programme (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assistant_programme_etape ADD CONSTRAINT FK_29D72596537A1329 FOREIGN KEY (message_id) REFERENCES assistant_message (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_programme_etape DROP FOREIGN KEY FK_29D72596537A1329');
        $this->addSql('ALTER TABLE assistant_programme_etape DROP FOREIGN KEY FK_29D7259662BB7AEE');
        $this->addSql('ALTER TABLE assistant_programme DROP FOREIGN KEY FK_BD49ED2C5E132043');
        $this->addSql('ALTER TABLE assistant_programme DROP FOREIGN KEY FK_BD49ED2CEA417747');
        $this->addSql('ALTER TABLE assistant_programme DROP FOREIGN KEY FK_BD49ED2CA4AEAFEA');
        $this->addSql('ALTER TABLE assistant_programme DROP FOREIGN KEY FK_BD49ED2C9AC0396');
        $this->addSql('DROP TABLE assistant_programme_etape');
        $this->addSql('DROP TABLE assistant_programme');
    }
}
