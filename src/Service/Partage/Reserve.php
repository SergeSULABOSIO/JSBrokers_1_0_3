<?php

namespace App\Service\Partage;

/**
 * LA RÉSERVE DU CABINET, DÉFINIE UNE SEULE FOIS.
 *
 * Réserve = ce qui reste au courtier une fois toutes les taxes retirées (la commission
 * « pure » l'est déjà : HT − taxe dont le courtier est redevable) PUIS toutes les
 * rétrocommissions versées — celles des partenaires EXTERNES comme celles des agents
 * INTERNES du cabinet.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────────────
 * La même soustraction était écrite à l'identique dans QUATRE fichiers :
 *   - IndicatorCalculationHelper::getIndicateursGlobaux()  (agrégats de toutes les rubriques)
 *   - TrancheIndicatorStrategy                             (fiche et liste des tranches)
 *   - AvenantIndicatorStrategy                             (fiche et liste des avenants)
 *   - RevenuPourCourtierIndicatorStrategy                  (fiche et liste des revenus)
 * Quatre écritures indépendantes d'une même règle métier, donc quatre occasions de
 * diverger. L'ouverture d'un SECOND bénéficiaire (l'agent interne) rendait la situation
 * intenable : un troisième terme à ajouter aurait dû l'être quatre fois, sans que rien
 * ne signale l'oubli du quatrième.
 *
 * ── CE QUE CETTE CLASSE NE FAIT PAS ─────────────────────────────────────────────────
 * Elle ne va chercher AUCUN montant. Ses trois termes lui sont donnés déjà calculés par
 * l'appelant, qui seul sait à quelle maille il travaille (une tranche est un prorata de
 * sa cotation, un avenant vaut sa cotation entière, un revenu ne vaut que lui-même).
 * Elle ne connaît donc ni Doctrine, ni le helper, ni la notion de tranche : c'est une
 * formule, et c'est tout ce qu'elle doit rester.
 *
 * ── UN RÉSULTAT NÉGATIF EST UNE INFORMATION, PAS UNE ERREUR ─────────────────────────
 * Rien n'empêche la somme des taux rétrocédés de dépasser 100 % : plusieurs agents
 * peuvent porter chacun leur condition sur la même affaire. La réserve devient alors
 * négative — le cabinet reverse plus qu'il ne garde. On ne l'écrête PAS à zéro : un
 * plancher silencieux masquerait précisément le paramétrage qu'il faut corriger.
 * Cf. estDeficitaire(), que les écrans et le rapport utilisent pour avertir.
 */
final class Reserve
{
    /**
     * Réserve du cabinet, arrondie au centime.
     *
     * @param float $commissionPure    commission HT moins la taxe due par le courtier
     * @param float $retroPartenaire   somme rétrocédée aux partenaires EXTERNES
     * @param float $retroAgent        somme rétrocédée aux agents INTERNES du cabinet
     */
    public static function calculer(float $commissionPure, float $retroPartenaire, float $retroAgent = 0.0): float
    {
        return round($commissionPure - $retroPartenaire - $retroAgent, 2);
    }

    /**
     * Le cabinet reverse-t-il plus qu'il ne lui reste ? Vrai quand la réserve passe sous
     * zéro sur une affaire qui portait bien une commission — le cas d'un cumul de taux
     * mal paramétré. Une affaire sans commission (réserve nulle) n'est pas déficitaire :
     * elle n'a simplement rien à partager.
     */
    public static function estDeficitaire(float $commissionPure, float $retroPartenaire, float $retroAgent = 0.0): bool
    {
        return $commissionPure > 0.0
            && self::calculer($commissionPure, $retroPartenaire, $retroAgent) < 0.0;
    }
}
