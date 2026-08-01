<?php

namespace App\Services\Search;

use App\Entity\Avenant;
use App\Entity\Piste;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNE POLICE DONT LE SORT EST SCELLÉ SORT DU PIPELINE D'ÉCHÉANCE.
 *
 * RAISON D'ÊTRE. La finalité du courtier est que l'assuré SOIT COUVERT. Quand une
 * police échue a été reprise par un avenant dérivé, cette finalité est atteinte :
 * la couverture continue, non plus sous la police de base mais sous son
 * successeur. Réclamer son renouvellement dans les chips « Échus » — et dans la
 * boussole de Ket — c'est réclamer une action déjà faite. La police de base est
 * vivante INDIRECTEMENT.
 *
 * LA RÈGLE, énoncée une fois :
 *
 *   Une police SORT du pipeline d'échéance dès que son sort est SCELLÉ : une
 *   opportunité dérivée lui a donné un AVENANT SUCCESSEUR (renouvellement,
 *   prorogation), ou porte une DÉCISION DE FIN (annulation, résiliation). Elle y
 *   RESTE tant qu'une action est due : mouvement AMORCÉ SANS AVENANT, ou AUCUNE
 *   SUITE.
 *
 * DEUX DIALECTES, UN SEUL TEXTE. La face PHP (estScelle) sert les indicateurs et
 * le badge de ligne ; la face SQL (dqlSuccessionScellee) sert le filtre et le
 * comptage du moteur de recherche, où le critère doit s'exprimer en base sous
 * peine de fausser la pagination. Même partage que AvenantEcheanceScope, qui
 * porte bornes() pour le SQL et classifier() pour le badge. Un test confronte les
 * deux faces sur le même jeu de données : c'est le garde-fou contre la divergence.
 *
 * CE QUI N'EST PAS CONCERNÉ. Les agrégats « polices actives » et les totaux de
 * primes (filtrés sur la colonne stockée renewalStatus) ne connaissent PAS cette
 * règle : y faire entrer une police reprise la compterait EN DOUBLE avec son
 * successeur. Une police reprise est vivante au sens de la COUVERTURE, jamais au
 * sens comptable.
 */
final class AvenantSuccessionScope
{
    /**
     * Types de mouvement qui scellent le sort d'une police par DÉCISION, sans
     * qu'un avenant successeur soit nécessaire : la couverture s'arrête, mais il
     * n'y a plus rien à faire — donc plus rien à réclamer dans les échéances.
     */
    public const MOUVEMENTS_SCELLANTS = [
        Piste::AVENANT_ANNULATION,
        Piste::AVENANT_RESILIATION,
    ];

    /**
     * Sorts (codes Avenant::RENEWAL_STATUS_*) qui font sortir du pipeline.
     * RENEWING en est volontairement absent : un renouvellement amorcé mais sans
     * avenant est précisément le cas qu'il faut continuer de voir.
     */
    public const CODES_SCELLES = [
        Avenant::RENEWAL_STATUS_RENEWED,
        Avenant::RENEWAL_STATUS_EXTENDED,
        Avenant::RENEWAL_STATUS_CANCELLED,
    ];

    /** Face PHP de la règle, sur le code rendu par AvenantRenouvellementResolver. */
    public static function estScelle(int $codeRenouvellement): bool
    {
        return in_array($codeRenouvellement, self::CODES_SCELLES, true);
    }

    /**
     * Face SQL de la règle : DQL d'un EXISTS CORRÉLÉ à l'avenant racine, vrai
     * quand son sort est scellé. À employer en NOT EXISTS pour retirer ces
     * polices d'une fenêtre d'échéance.
     *
     * GOTCHA — EXISTS, jamais NOT IN. Un « IDENTITY(e.pisteDeRenouvellement) NOT IN
     * (…) » vaut NULL, donc FAUX, pour toute police SANS piste dérivée : le filtre
     * les éliminerait TOUTES, soit exactement l'inverse du but recherché (une
     * rubrique Avenants silencieusement vide). NOT EXISTS n'a pas ce piège.
     *
     * Le double lien Piste::avenantDeBase ⇄ Avenant::pisteDeRenouvellement est fait
     * de deux relations INDÉPENDANTES et unidirectionnelles : les deux sens sont
     * lus, un lien à moitié posé ne vaut pas « aucune suite ».
     *
     * @param string $rootAlias alias de l'Avenant dans la requête appelante (ex. « e »)
     * @param string $suffix    suffixe d'unicité des alias internes (ex. « _count »)
     */
    public static function dqlSuccessionScellee(
        EntityManagerInterface $em,
        string $rootAlias,
        string $suffix = '',
    ): string {
        $p = 'succPiste' . $suffix;
        $c = 'succCotation' . $suffix;
        $a = 'succAvenant' . $suffix;

        return $em->createQueryBuilder()
            ->select('1')
            ->from(Piste::class, $p)
            // LEFT JOIN : une opportunité dérivée SANS proposition reste candidate —
            // elle peut sceller le sort de la police par décision (annulation).
            ->leftJoin("{$p}.cotations", $c)
            ->leftJoin("{$c}.avenants", $a)
            // Les DEUX sens du double lien.
            ->where("IDENTITY({$p}.avenantDeBase) = {$rootAlias}.id")
            ->orWhere("{$p}.id = IDENTITY({$rootAlias}.pisteDeRenouvellement)")
            // Sort scellé : un avenant successeur existe (et n'est pas la police
            // elle-même — un lien mal posé ne doit pas la rendre sa propre suite),
            // OU le mouvement est une décision de fin.
            ->andWhere(
                "({$a}.id IS NOT NULL AND {$a}.id <> {$rootAlias}.id) "
                . "OR {$p}.typeAvenant IN (:mouvementsScellants{$suffix})"
            )
            ->getDQL();
    }

    /** Nom du paramètre à lier avec MOUVEMENTS_SCELLANTS pour le DQL ci-dessus. */
    public static function parametreMouvementsScellants(string $suffix = ''): string
    {
        return 'mouvementsScellants' . $suffix;
    }
}
