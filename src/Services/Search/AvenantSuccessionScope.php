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
 *   prorogation), porte une DÉCISION DE FIN (annulation, résiliation), ou le
 *   courtier a SIGNALÉ qu'elle ne serait pas renouvelée. Elle y RESTE tant qu'une
 *   action est due : mouvement AMORCÉ SANS AVENANT, ou AUCUNE SUITE.
 *
 * LE QUATRIÈME SORT — la décision sans mouvement. Les trois premiers se lisent dans
 * la chaîne (une piste, un avenant successeur) ; le quatrième est une note portée par
 * la police elle-même : Avenant::$nonRenouvelable, posée à tout moment de sa vie, dès
 * que le courtier apprend qu'il n'y aura pas de suite. Étant une COLONNE DE LA RACINE
 * et non un fait de la chaîne, elle ne peut pas entrer dans l'EXISTS corrélé : d'où
 * estScellePour() et predicatSortNonScelle(), qui COMPOSENT la règle historique avec
 * elle. Les deux faces d'origine restent intactes et publiques — le test de
 * non-divergence continue de les viser directement.
 *
 * CE QUE LE MARQUAGE NE FAIT PAS. Il n'éteint pas la couverture (une police marquée en
 * cours couvre jusqu'à son terme, renewalStatus n'est pas touché) et il ne solde rien :
 * prime exigible, commissions, taxes et rétrocommissions restent réclamées par les
 * suivis de recouvrement, qui ignorent totalement cette règle.
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
     * Face PHP COMPLÈTE : sort scellé par la chaîne OU par décision explicite du courtier.
     *
     * Prend l'avenant, et pas seulement son code, parce que le marquage n'est justement PAS
     * un code : une police signalée non renouvelable alors qu'elle couvre encore garde le
     * code RUNNING (elle couvre pour de bon), et une police échue marquée garde LOST. Faire
     * porter le sort par un code aurait obligé à mentir sur l'un des deux.
     *
     * Jumelle de predicatSortNonScelle() ; un test confronte les deux sur le même jeu de
     * données.
     */
    public static function estScellePour(Avenant $avenant, int $codeRenouvellement): bool
    {
        return $avenant->isNonRenouvelable() || self::estScelle($codeRenouvellement);
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

    /**
     * Face SQL COMPLÈTE : prédicat « cette police RESTE dans le pipeline d'échéance », à
     * poser tel quel en andWhere(). C'est LE point d'application unique de la règle — les
     * appelants ne composent plus rien eux-mêmes, sans quoi la décision de non-renouvellement
     * devrait être répétée à chaque site et finirait par y manquer.
     *
     * Le marquage est une colonne de la RACINE : il ne peut pas entrer dans l'EXISTS corrélé,
     * d'où un prédicat composé plutôt qu'un enrichissement du sous-select.
     *
     * Le paramètre à lier reste celui de parametreMouvementsScellants($suffix).
     *
     * @param string $rootAlias alias de l'Avenant dans la requête appelante (ex. « e »)
     * @param string $suffix    suffixe d'unicité des alias internes (ex. « _count »)
     */
    public static function predicatSortNonScelle(
        EntityManagerInterface $em,
        string $rootAlias,
        string $suffix = '',
    ): string {
        return sprintf(
            '%s.nonRenouvelable = false AND NOT EXISTS (%s)',
            $rootAlias,
            self::dqlSuccessionScellee($em, $rootAlias, $suffix),
        );
    }

    /** Nom du paramètre à lier avec MOUVEMENTS_SCELLANTS pour le DQL ci-dessus. */
    public static function parametreMouvementsScellants(string $suffix = ''): string
    {
        return 'mouvementsScellants' . $suffix;
    }
}
