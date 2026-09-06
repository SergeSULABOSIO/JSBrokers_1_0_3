<?php

namespace App\Echange\Etat;

use App\Entity\Avenant;
use App\Services\Search\CotationSouscriptionScope;
use Doctrine\ORM\QueryBuilder;

/**
 * QUELLES TRANCHES L'ÉTAT RETIENT : celles des polices, celles des projets, celles des
 * propositions perdues — ou toutes.
 *
 * ── POURQUOI CE FILTRE EXISTE ───────────────────────────────────────────────────────
 * Une tranche naît sur une PROPOSITION, bien avant qu'un contrat existe. Tant que le
 * client n'a rien validé, elle ne décrit qu'un projet : `TrancheIndicatorStrategy` lui
 * donne d'ailleurs le statut « N/A » et l'exclut du suivi de recouvrement. Les mêler aux
 * échéances de vraies polices dans un état qu'on présente à un assureur, c'est annoncer
 * un portefeuille qu'on n'a pas.
 *
 * À l'inverse, un directeur commercial veut précisément voir les projets — et les
 * caduques, pour mesurer ce qui a été perdu.
 *
 * ── LE VOCABULAIRE N'EST PAS INVENTÉ ICI ────────────────────────────────────────────
 * ⚠ Les trois statuts et leurs libellés viennent de `CotationSouscriptionScope`, source
 * unique déjà partagée par les chips de la rubrique Propositions et par les outils de
 * l'assistant. Les redéclarer aurait fait dire « Caduques » à un écran et « Sans suite »
 * à un autre, pour le même ensemble.
 *
 * ⚠ ET LA PARTITION EST COMPLÈTE : Souscrites ⊎ En attente ⊎ Caduques = toutes les
 * cotations. Un chip « Toutes » n'est donc pas la somme de trois filtres, c'est l'absence
 * de filtre — ce qui garde les tranches SANS cotation, que les trois autres écartent.
 */
final class ValiditeDesTranches
{
    /** Aucun filtre : toutes les tranches, y compris celles sans cotation. */
    public const TOUTES = 'toutes';

    /**
     * Les quatre chips, dans l'ordre de l'écran.
     *
     * @return array<string, string> valeur => libellé
     */
    public static function valeurs(): array
    {
        return [
            self::TOUTES => 'Toutes',
            CotationSouscriptionScope::STATUT_SOUSCRITES => 'Polices (souscrites)',
            CotationSouscriptionScope::STATUT_EN_ATTENTE => 'Projets (en attente)',
            CotationSouscriptionScope::STATUT_CADUQUES => 'Caduques',
        ];
    }

    /** Ce que ce choix veut dire, pour le dictionnaire du fichier et pour l'assistant. */
    public static function explication(string $statut): string
    {
        return match ($statut) {
            CotationSouscriptionScope::STATUT_SOUSCRITES =>
                'Uniquement les échéances de polices : leur proposition porte au moins un avenant, '
                . 'le contrat existe.',
            CotationSouscriptionScope::STATUT_EN_ATTENTE =>
                'Uniquement les échéances de PROJETS : la proposition n\'est pas encore validée par '
                . 'le client, et aucune proposition concurrente ne l\'est non plus. Ces montants ne '
                . 'sont pas des créances.',
            CotationSouscriptionScope::STATUT_CADUQUES =>
                'Uniquement les échéances de propositions PERDUES : une proposition concurrente de '
                . 'la même piste a emporté le marché.',
            default =>
                'Toutes les tranches, projets et polices confondus — y compris celles qui ne se '
                . 'rattachent à aucune proposition.',
        };
    }

    public static function estValide(?string $statut): bool
    {
        return $statut !== null && isset(self::valeurs()[$statut]);
    }

    /** Le statut demandé, ramené à une valeur connue. Tout le reste vaut « toutes ». */
    public static function normaliser(?string $statut): string
    {
        return self::estValide($statut) ? (string) $statut : self::TOUTES;
    }

    public static function libelle(string $statut): string
    {
        return self::valeurs()[$statut] ?? $statut;
    }

    /**
     * Restreint une requête de TRANCHES au statut demandé.
     *
     * ⚠ LES PRÉDICATS SONT CEUX DE LA BARRE DE RECHERCHE, transposés de la cotation à la
     * tranche : une cotation est souscrite dès qu'elle porte un avenant ; elle est caduque
     * quand elle n'en porte pas mais qu'une sœur de la même piste en porte un. Les
     * réinventer aurait fait diverger le chip de la rubrique Propositions et celui de
     * l'export, sur la même question.
     *
     * ⚠ LA JOINTURE N'EST POSÉE QUE SI L'ON FILTRE. Une tranche sans cotation — cas
     * marginal mais réel — disparaîtrait sinon de l'état « Toutes », et son absence ne se
     * verrait qu'à un total qui ne tombe pas juste.
     *
     * @param string $alias alias de la racine Tranche dans la requête
     */
    public static function appliquer(QueryBuilder $qb, string $alias, ?string $statut): void
    {
        $statut = self::normaliser($statut);
        if ($statut === self::TOUTES) {
            return;
        }

        $qb->join($alias . '.cotation', 'val_cot');

        // Les cotations DIRECTEMENT souscrites : au moins un avenant.
        $souscrites = $qb->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(val_av.cotation)')
            ->from(Avenant::class, 'val_av')
            ->getDQL();

        if ($statut === CotationSouscriptionScope::STATUT_SOUSCRITES) {
            $qb->andWhere($qb->expr()->in('val_cot.id', $souscrites));

            return;
        }

        // « En attente » et « caduques » : la cotation elle-même n'est jamais souscrite.
        $qb->andWhere($qb->expr()->notIn('val_cot.id', $souscrites));

        // On les distingue par l'état de la PISTE : porte-t-elle une cotation souscrite ?
        $pistesBound = $qb->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(val_cotp.piste)')
            ->from(Avenant::class, 'val_avp')
            ->join('val_avp.cotation', 'val_cotp')
            ->getDQL();

        if ($statut === CotationSouscriptionScope::STATUT_CADUQUES) {
            $qb->andWhere($qb->expr()->in('IDENTITY(val_cot.piste)', $pistesBound));

            return;
        }

        $qb->andWhere($qb->expr()->notIn('IDENTITY(val_cot.piste)', $pistesBound));
    }
}
