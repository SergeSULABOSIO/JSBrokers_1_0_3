<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NUMÉROTATION DES NOTES : UNE RÉFÉRENCE UNIQUE DANS LE CABINET, ET CHRONOLOGIQUE.
 *
 * Décisions du 2026-10-14 : le produit génère `ND-AAAA-NNNNN` / `NC-AAAA-NNNNN`, une série
 * par cabinet, par année et par type ; le champ reste éditable ; une référence n'appartient
 * qu'à une note du cabinet.
 *
 * 1. La table des compteurs (`compteur_de_notes`), supprimée avec son cabinet.
 * 2. LES DOUBLONS EXISTANTS, AVANT L'INDEX : sans quoi celui-ci ne se poserait pas. Dans
 *    chaque cabinet, la plus ancienne note garde sa référence ; les suivantes reçoivent un
 *    suffixe (-2, -3…) qui ne collisionne avec aucune autre. CHAQUE RENOMMAGE EST LISTÉ
 *    dans la sortie de la migration. Les doublons sont trouvés par SQL, donc selon la
 *    collation de la colonne (casse, accents et espaces finaux ignorés), comme l'index.
 * 3. L'index unique (entreprise_id, reference).
 */
final class Version20261014160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes : compteurs de numérotation par cabinet, année et type ; référence unique dans le cabinet (doublons suffixés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE compteur_de_notes (id INT AUTO_INCREMENT NOT NULL, entreprise_id INT NOT NULL, annee INT NOT NULL, type INT NOT NULL, sequence INT NOT NULL, INDEX IDX_A7349D29A4AEAFEA (entreprise_id), UNIQUE INDEX uniq_compteur_de_notes_serie (entreprise_id, annee, type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE compteur_de_notes ADD CONSTRAINT FK_A7349D29A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');

        foreach ($this->renommages() as [$id, $avant, $apres]) {
            $this->write(sprintf('  Note #%d : référence « %s » déjà portée dans son cabinet → « %s »', $id, $avant, $apres));
            $this->addSql('UPDATE note SET reference = ? WHERE id = ?', [$apres, $id]);
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_note_entreprise_reference ON note (entreprise_id, reference)');
    }

    /**
     * Les doublons à renommer, la plus ancienne note (plus petit id) gardant sa référence.
     *
     * @return list<array{0: int, 1: string, 2: string}> id, référence d'avant, référence d'après
     */
    private function renommages(): array
    {
        // Les doublons, tels que la COLLATION de la colonne les voit (casse, accents et
        // espaces finaux ignorés) : c'est elle que l'index appliquera.
        $groupes = $this->connection->fetchAllAssociative(
            'SELECT entreprise_id, GROUP_CONCAT(id ORDER BY id) AS ids FROM note'
            . ' GROUP BY entreprise_id, reference HAVING COUNT(*) > 1',
        );
        if ($groupes === []) {
            return [];
        }

        $renommages = [];
        foreach ($groupes as $groupe) {
            $cabinet = (int) $groupe['entreprise_id'];
            $ids = array_map('intval', explode(',', (string) $groupe['ids']));
            array_shift($ids); // la plus ancienne garde sa référence

            foreach ($ids as $id) {
                $reference = (string) $this->connection->fetchOne('SELECT reference FROM note WHERE id = ?', [$id]);
                $base = rtrim($reference) !== '' ? rtrim($reference) : 'NOTE-' . $id;
                $rang = 2;
                // Un suffixe ne tombe jamais sur une référence existante, ni sur un
                // renommage déjà décidé dans ce cabinet ; et il tient dans les 255
                // caractères de la colonne.
                $suffixee = static fn (int $r): string => mb_substr($base, 0, 255 - strlen('-' . $r)) . '-' . $r;
                while ($this->dejaPrise($cabinet, $suffixee($rang), $renommages)) {
                    ++$rang;
                }
                $renommages[] = [$id, $reference, $suffixee($rang), $cabinet];
            }
        }

        return array_map(static fn (array $r): array => [$r[0], $r[1], $r[2]], $renommages);
    }

    /** @param list<array{0: int, 1: string, 2: string, 3: int}> $decides */
    private function dejaPrise(int $cabinet, string $reference, array $decides): bool
    {
        foreach ($decides as [, , $apres, $deCabinet]) {
            if ($deCabinet === $cabinet && mb_strtolower($apres) === mb_strtolower($reference)) {
                return true;
            }
        }

        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM note WHERE entreprise_id = ? AND reference = ? LIMIT 1',
            [$cabinet, $reference],
        );
    }

    /** Retour arrière : l'index et les compteurs disparaissent ; les renommages restent. */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_note_entreprise_reference ON note');
        $this->addSql('ALTER TABLE compteur_de_notes DROP FOREIGN KEY FK_A7349D29A4AEAFEA');
        $this->addSql('DROP TABLE compteur_de_notes');
    }
}
