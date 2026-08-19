<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LES RISQUES CIBLÉS DEVIENNENT PARTAGEABLES.
 *
 * `ConditionPartage.produits` passe de OneToMany à ManyToMany. Un risque est une entrée du
 * CATALOGUE de l'entreprise (« Incendie », « RC automobile »), pas la propriété d'une
 * condition de partage : sous l'ancienne cardinalité, le cibler depuis une seconde
 * condition le retirait SILENCIEUSEMENT de la première. C'est aussi pourquoi l'écran ne
 * proposait que d'en créer un — ce qui dupliquait le catalogue.
 *
 * La règle métier ne change pas : `sappliqueAuRisque()` teste toujours l'appartenance à la
 * collection. Seule la cardinalité qui la porte est corrigée.
 *
 * ⚠ Le diff Doctrine proposait 192 instructions, dont 186 sans rapport avec ce lot
 * (renommages d'index sur crm_*, assistant_document, avenant, coupon…). Cette migration a
 * donc été écrite à la main : elle ne contient QUE ce changement — et la recopie des
 * données, que le diff ne sait pas générer.
 */
final class Version20260819122901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Risques ciblés : ConditionPartage.produits passe en ManyToMany (table de liaison + reprise des rattachements existants).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE condition_partage_risque (condition_partage_id INT NOT NULL, risque_id INT NOT NULL, INDEX IDX_E0FAC78826164BCB (condition_partage_id), INDEX IDX_E0FAC7884ECC2413 (risque_id), PRIMARY KEY(condition_partage_id, risque_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE condition_partage_risque ADD CONSTRAINT FK_E0FAC78826164BCB FOREIGN KEY (condition_partage_id) REFERENCES condition_partage (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE condition_partage_risque ADD CONSTRAINT FK_E0FAC7884ECC2413 FOREIGN KEY (risque_id) REFERENCES risque (id) ON DELETE CASCADE');

        // REPRISE DES RATTACHEMENTS EXISTANTS — avant de supprimer la colonne, sans quoi
        // les risques déjà ciblés par une condition en seraient détachés sans bruit, et
        // les conditions « n'inclure que ces risques » cesseraient de s'appliquer.
        $this->addSql('INSERT INTO condition_partage_risque (condition_partage_id, risque_id) SELECT condition_partage_id, id FROM risque WHERE condition_partage_id IS NOT NULL');

        $this->addSql('ALTER TABLE risque DROP FOREIGN KEY FK_20230D2426164BCB');
        $this->addSql('DROP INDEX IDX_20230D2426164BCB ON risque');
        $this->addSql('ALTER TABLE risque DROP condition_partage_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risque ADD condition_partage_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risque ADD CONSTRAINT FK_20230D2426164BCB FOREIGN KEY (condition_partage_id) REFERENCES condition_partage (id)');
        $this->addSql('CREATE INDEX IDX_20230D2426164BCB ON risque (condition_partage_id)');

        // Retour en arrière NÉCESSAIREMENT LACUNAIRE : une colonne to-one ne peut porter
        // qu'un seul rattachement par risque. On garde le plus ancien (MIN) et on le dit
        // ici plutôt que de laisser croire à une réversibilité parfaite.
        $this->addSql('UPDATE risque r SET r.condition_partage_id = (SELECT MIN(l.condition_partage_id) FROM condition_partage_risque l WHERE l.risque_id = r.id)');

        $this->addSql('ALTER TABLE condition_partage_risque DROP FOREIGN KEY FK_E0FAC78826164BCB');
        $this->addSql('ALTER TABLE condition_partage_risque DROP FOREIGN KEY FK_E0FAC7884ECC2413');
        $this->addSql('DROP TABLE condition_partage_risque');
    }
}
