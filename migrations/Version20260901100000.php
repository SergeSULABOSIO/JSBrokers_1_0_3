<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CE QUE LE CABINET EXIGE D'UNE DEMANDE DE CONGÉ — les réglages, et la trace de ce qu'un
 * valideur fait franchir.
 *
 * ── POURQUOI DES RÉGLAGES, ET NON DES CONSTANTES ────────────────────────────────────
 * Un préavis de cinq jours convient à un cabinet et pas à l'autre. Coder ces valeurs en
 * dur aurait obligé chaque cabinet à demander un déploiement pour changer un chiffre qui
 * ne regarde que lui — et aurait rendu impossible de désactiver un contrôle dont il ne
 * veut pas. Un contrôle qu'on ne peut pas éteindre est un contrôle qu'on apprend à
 * contourner.
 *
 * ── UN SEUL JEU PAR CABINET ─────────────────────────────────────────────────────────
 * Aucune contrainte d'unicité n'est posée : elle interdirait la coexistence transitoire
 * pendant une reprise, pour un risque que le code écarte déjà
 * (ParametresCongeRepository::pourEntreprise lit la PREMIÈRE ligne du cabinet, et la
 * rubrique refuse la création). La règle vit là où elle se lit, pas dans un index.
 *
 * ── LA TRACE DU CONTOURNEMENT ───────────────────────────────────────────────────────
 * `demande_conge.controles_contournes` conserve ce que le statut de valideur a permis de
 * franchir AU MOMENT de la soumission. Conservé plutôt que recalculé : les réglages du
 * cabinet changent, et un contrôle rejoué six mois après ne dirait plus ce qui a
 * réellement été contourné ce jour-là.
 *
 * ── AUCUNE REPRISE DE DONNÉES ───────────────────────────────────────────────────────
 * Les cabinets sans réglages ne sont pas des cabinets sans règles : ce sont des cabinets
 * aux valeurs par défaut, que le repository rend sans rien écrire. La ligne naît au
 * premier affichage de la rubrique. Semer trois cents lignes identiques pour couvrir une
 * lecture aurait été du bruit.
 *
 * ── ÉCRITE À LA MAIN ────────────────────────────────────────────────────────────────
 * Le diff Doctrine embarquerait la dérive de schéma préexistante du dépôt (renommages
 * d'index hérités du CRM, de l'assistant et des avenants). Cette migration ne contient
 * QUE les huit instructions de ce lot.
 */
final class Version20260901100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Congés (lot 2) : réglages par cabinet (préavis, absents simultanés, relances, dotation, seuil de report), périodes de blocage, et trace des contrôles contournés par un valideur.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE parametres_conge (
                id INT AUTO_INCREMENT NOT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                delai_preavis_jours INT NOT NULL,
                max_absents_simultanes INT DEFAULT NULL,
                seuil_alerte_report NUMERIC(4, 2) NOT NULL,
                relance_apres_jours INT NOT NULL,
                dotation_annuelle NUMERIC(5, 1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_F65C3147A4AEAFEA (entreprise_id),
                INDEX IDX_F65C3147EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE periode_blocage (
                id INT AUTO_INCREMENT NOT NULL,
                parametres_id INT DEFAULT NULL,
                entreprise_id INT NOT NULL,
                invite_id INT DEFAULT NULL,
                libelle VARCHAR(150) NOT NULL,
                date_debut DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                date_fin DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                actif TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_38EFA71044AEE5AE (parametres_id),
                INDEX IDX_38EFA710A4AEAFEA (entreprise_id),
                INDEX IDX_38EFA710EA417747 (invite_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE parametres_conge ADD CONSTRAINT FK_F65C3147A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE parametres_conge ADD CONSTRAINT FK_F65C3147EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        // CASCADE : une période de blocage n'a aucun sens hors des réglages qui la portent.
        $this->addSql('ALTER TABLE periode_blocage ADD CONSTRAINT FK_38EFA71044AEE5AE FOREIGN KEY (parametres_id) REFERENCES parametres_conge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE periode_blocage ADD CONSTRAINT FK_38EFA710A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE periode_blocage ADD CONSTRAINT FK_38EFA710EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id)');

        // NULLABLE, et c'est voulu : l'immense majorité des demandes ne contourne rien.
        // Une chaîne vide se lirait comme « calculé, et il n'y avait rien » ; NULL se lit
        // « la question ne s'est pas posée » — les demandes antérieures à ce lot sont
        // dans ce cas.
        $this->addSql('ALTER TABLE demande_conge ADD controles_contournes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande_conge DROP controles_contournes');
        $this->addSql('DROP TABLE periode_blocage');
        $this->addSql('DROP TABLE parametres_conge');
    }
}
