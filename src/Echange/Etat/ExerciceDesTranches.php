<?php

namespace App\Echange\Etat;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * L'EXERCICE D'UNE TRANCHE : l'année de la DATE D'EFFET de sa police.
 *
 * Un état de portefeuille se lit presque toujours par exercice — « qu'ai-je souscrit en
 * 2025 ? ». Sans ce filtre, il faut sortir tout l'historique puis trier dans Excel, ce qui
 * revient à faire faire au tableur le travail que la base sait faire.
 *
 * ── QUELLE DATE, ET POURQUOI CELLE-LÀ ───────────────────────────────────────────────
 * La date d'effet de la POLICE, non celle de l'échéance de prime : c'est l'exercice de
 * SOUSCRIPTION qui découpe l'activité d'un cabinet, et une police de décembre porte des
 * tranches qui tombent l'année suivante sans changer d'exercice pour autant.
 *
 * ⚠ UNE COTATION PEUT PORTER PLUSIEURS AVENANTS — l'originel, puis ses modifications. La
 * police est identifiée par le PLUS ANCIEN par date d'effet : c'est lui qui date la
 * souscription. Le filtre et la colonne « Police · Date d'effet » emploient la même
 * définition, sans quoi on filtrerait sur une date que le fichier n'affiche pas.
 *
 * ── COMMENT ON FILTRE ───────────────────────────────────────────────────────────────
 * Par un ENCADREMENT de dates, jamais par une fonction `YEAR()` en DQL : c'est la
 * discipline déjà suivie ailleurs dans le projet (cf. le filtre de période des
 * reversements), et elle reste utilisable par un index.
 */
final class ExerciceDesTranches
{
    /** Aucun filtre : tous les exercices, y compris les tranches sans police. */
    public const TOUS = 'tous';

    /**
     * Les exercices RÉELLEMENT présents chez ce cabinet, du plus récent au plus ancien.
     *
     * ⚠ On les DÉRIVE des données plutôt que d'énumérer une plage d'années : proposer
     * « 2019 » à un cabinet ouvert en 2024 donnerait un chip qui ne rend jamais rien, et
     * l'utilisateur croirait à une panne.
     *
     * @return int[]
     */
    public static function annees(EntityManagerInterface $em, Entreprise $entreprise): array
    {
        // Lecture directe : extraire une année est le seul point où le SQL du moteur dit
        // en un mot ce que DQL exprimerait en plusieurs. Le FILTRE, lui, reste un
        // encadrement de dates, donc parfaitement portable.
        $lignes = $em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT YEAR(a.starting_at) AS annee
               FROM avenant a
              WHERE a.entreprise_id = :entreprise
                AND a.starting_at IS NOT NULL
           ORDER BY annee DESC',
            ['entreprise' => $entreprise->getId()],
        );

        return array_map('intval', array_filter($lignes, static fn ($a) => (int) $a > 0));
    }

    public static function estValide(?string $exercice, array $annees): bool
    {
        return $exercice !== null && $exercice !== '' && \in_array((int) $exercice, $annees, true);
    }

    /** L'exercice demandé, ramené à une valeur connue. Tout le reste vaut « tous ». */
    public static function normaliser(?string $exercice, array $annees): string
    {
        return self::estValide($exercice, $annees) ? (string) (int) $exercice : self::TOUS;
    }

    public static function libelle(string $exercice): string
    {
        return $exercice === self::TOUS ? 'Tous les exercices' : 'Exercice ' . $exercice;
    }

    /** Ce que ce choix veut dire, pour le dictionnaire du fichier et pour l'assistant. */
    public static function explication(string $exercice): string
    {
        return $exercice === self::TOUS
            ? 'Tous les exercices confondus.'
            : sprintf(
                'Uniquement les tranches dont la police a pris effet en %s. L\'exercice est celui '
                . 'de la SOUSCRIPTION : une police de décembre %s garde cet exercice même si ses '
                . 'échéances tombent l\'année suivante.',
                $exercice,
                $exercice,
            );
    }

    /**
     * Restreint une requête de TRANCHES à un exercice.
     *
     * ⚠ LA DATE RETENUE EST LA PLUS ANCIENNE des avenants de la cotation — la même que
     * celle qu'affiche la colonne « Police · Date d'effet ». Filtrer sur n'importe quel
     * avenant (EXISTS) aurait gardé une tranche dont l'avenant de 2026 tombe dans
     * l'exercice demandé alors que le fichier affiche 2025 : un filtre qui contredit la
     * colonne qu'il prétend filtrer.
     *
     * Une tranche sans police est écartée dès qu'un exercice est demandé : elle n'a pas
     * de date d'effet, donc pas d'exercice.
     *
     * @param string $alias alias de la racine Tranche dans la requête
     */
    public static function appliquer(QueryBuilder $qb, string $alias, string $exercice): void
    {
        if ($exercice === self::TOUS) {
            return;
        }

        $annee = (int) $exercice;

        // ⚠ DEUX COMPARAISONS, PAS UN `BETWEEN` : DQL refuse `BETWEEN` après une
        // sous-requête scalaire (il attend un opérateur de comparaison).
        //
        // ⚠ ET DEUX ALIAS DISTINCTS : deux sous-requêtes de la même requête ne peuvent pas
        // partager le leur, fût-ce dans des clauses séparées — « ex_av is already defined ».
        $dateDePolice = static fn (string $sousAlias): string => sprintf(
            '(SELECT MIN(%1$s.startingAt) FROM %2$s %1$s WHERE %1$s.cotation = %3$s.cotation)',
            $sousAlias,
            Avenant::class,
            $alias,
        );

        $qb->andWhere($dateDePolice('ex_av_debut') . ' >= :exDebut')
            ->andWhere($dateDePolice('ex_av_fin') . ' <= :exFin')
            ->setParameter('exDebut', new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $annee)))
            ->setParameter('exFin', new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $annee)));
    }
}
