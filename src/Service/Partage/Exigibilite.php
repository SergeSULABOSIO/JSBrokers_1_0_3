<?php

namespace App\Service\Partage;

/**
 * QUAND LA DETTE DE RÉTROCOMMISSION NAÎT-ELLE — ET POUR COMBIEN ?
 *
 * Une seule formule, pour les DEUX familles d'intermédiaires. Elle vivait en double, une
 * copie par camp, et les deux ne lisaient déjà plus le même « encaissé » : sur une même
 * échéance soldée par un bordereau partiellement réglé, l'agent devenait exigible et le
 * partenaire non. Le courtier ne payait pas quelqu'un qu'il devait payer, et rien ne le
 * signalait. Deux copies d'une règle d'argent finissent toujours par diverger — celles-ci
 * l'avaient déjà fait.
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────────────
 * L'argent qui est RENTRÉ doit ressortir. Ce que le cabinet a encaissé sur une échéance
 * fait naître, à due proportion, la part de l'intermédiaire :
 *
 *     ratio      = min(1, encaissée ÷ due)      la part réellement rentrée
 *     réclamable = rétro due × ratio            la part de la dette qui est NÉE
 *     exigible   = max(0, réclamable − versé)   ce qui reste à sortir
 *
 * ── POURQUOI PROPORTIONNELLE, ET NON TOUT-OU-RIEN ───────────────────────────────────
 * La règle exigeait auparavant l'encaissement INTÉGRAL de l'échéance. Un courtier ayant
 * perçu 60 % de sa commission gardait donc 100 % de la rétro — alors que 60 % de la
 * créance qui la justifie était recouvrée. Le prorata est la lecture littérale de « ce qui
 * est rentré doit être payé », et c'est déjà ainsi que le PAYÉ d'un partenaire se calcule.
 *
 * Aux deux bouts, elle redonne exactement l'ancien comportement : `ratio = 1` rend le solde
 * entier, `ratio = 0` ne rend rien.
 *
 * ── CE QU'ELLE NE FAIT PAS ──────────────────────────────────────────────────────────
 * Elle ne touche PAS au montant DÛ, qui naît à la souscription et ne dépend d'aucun
 * encaissement. Exigibilité et dette sont deux questions distinctes : confondre les deux
 * ferait disparaître de la vue ce que le cabinet doit encore.
 */
final class Exigibilite
{
    /**
     * La part RÉCLAMABLE d'une dette de rétrocommission, au centime.
     *
     * @param float $retroDue  ce que l'échéance doit à l'intermédiaire
     * @param float $dueCommission ce que le cabinet attend de l'assureur sur cette échéance
     * @param float $encaissee     ce qu'il en a effectivement perçu
     */
    public static function reclamable(float $retroDue, float $dueCommission, float $encaissee): float
    {
        if ($retroDue <= 0.0) {
            return 0.0;
        }

        return round($retroDue * self::ratio($dueCommission, $encaissee), 2);
    }

    /**
     * Ce qui reste à SORTIR : le réclamable, moins ce qui est déjà parti.
     *
     * Jamais négatif — un trop-versé n'est pas une créance du cabinet, et l'afficher en
     * exigible négatif inviterait à « récupérer » un virement passé.
     */
    public static function exigible(
        float $retroDue,
        float $dueCommission,
        float $encaissee,
        float $dejaVerse,
    ): float {
        return max(0.0, round(self::reclamable($retroDue, $dueCommission, $encaissee) - $dejaVerse, 2));
    }

    /**
     * LA PART RÉELLEMENT RENTRÉE, entre 0 et 1.
     *
     * Sans commission attendue — une affaire à honoraires purs, une échéance sans revenu de
     * l'assureur — il n'y a rien à percevoir : la dette est donc née dès la souscription, et
     * le ratio vaut 1. Rendre 0 aurait rendu ces rétros éternellement inexigibles.
     *
     * Le plafond à 1 n'est pas cosmétique : un encaissement supérieur au dû (arrondi,
     * régularisation) ne doit pas rendre exigible plus que la dette elle-même.
     */
    public static function ratio(float $dueCommission, float $encaissee): float
    {
        if ($dueCommission <= 0.0) {
            return 1.0;
        }
        if ($encaissee <= 0.0) {
            return 0.0;
        }

        return min(1.0, $encaissee / $dueCommission);
    }
}
