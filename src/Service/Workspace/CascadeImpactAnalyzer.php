<?php

namespace App\Service\Workspace;

/**
 * ANNONCE LA PORTÉE D'UNE SUPPRESSION, AVANT DE LA DEMANDER.
 *
 * On ne peut pas demander à quelqu'un de valider ce qu'on lui cache : effacer une
 * opportunité emporte ses propositions, ses polices, ses échéances, ses commissions, ses
 * factures et leurs règlements. C'est cette liste, chiffrée, que l'écran de confirmation
 * et l'aperçu de plan de l'assistant affichent.
 *
 * ⚠ IL N'Y A PLUS DE SECOND MOTEUR DE GRAPHE ICI. L'analyse DÉRIVE désormais du plan
 * réellement exécuté ({@see SuppressionEnCascade::planifier()}) : c'est le même calcul qui
 * annonce et qui applique. Deux moteurs auraient divergé — l'écran aurait promis une
 * portée que l'exécution démentait, ce qui est pire que de ne rien annoncer.
 *
 * Trois angles morts de l'ancienne version tombent par construction :
 *  - la profondeur n'est plus plafonnée à trois niveaux : la borne est le graphe visité,
 *    qui est fini (une facture se trouve au quatrième) ;
 *  - les clés entrantes sont examinées pour CHAQUE ligne condamnée, et non pour la seule
 *    racine — c'est par là que le blocage d'une facture passait inaperçu ;
 *  - une colonne non nullable ne se lit plus comme un blocage fatal : elle se lit comme
 *    l'aveu que la ligne ne peut pas exister sans sa cible, donc qu'elle part avec elle.
 */
class CascadeImpactAnalyzer
{
    public function __construct(
        private readonly SuppressionEnCascade $suppression,
    ) {
    }

    public function analyserSuppression(object $entity): CascadeImpact
    {
        try {
            $plan = $this->suppression->planifier($entity);
        } catch (\Throwable) {
            // Fail-safe : une analyse impossible ne doit pas empêcher un geste légitime.
            // La transaction d'exécution reste le filet de sécurité, et un refus de la
            // base est traduit en message métier par le contrôleur.
            return new CascadeImpact();
        }

        return new CascadeImpact(
            enfants: $plan->portee(),
            blocages: $plan->refus,
            conservations: $plan->conservations,
        );
    }
}
