<?php

namespace App\Ai\Tool;

/**
 * MARQUEUR : cet outil peut répondre « pret: true » et faire ainsi APPARAÎTRE la
 * barre de décision d'un plan d'écriture (uiAction ket-mutation.review, émise par
 * PreparerOperationsTool — directement, ou par délégation depuis un outil dédié
 * qui traduit ses arguments en opérations génériques).
 *
 * Aucune méthode : son seul rôle est de rendre la liste des outils producteurs de
 * plan DÉRIVABLE DU CODE (cf. App\Ai\Mutation\OutilsDePlan). Le prompt système
 * énumérait cette liste À LA MAIN dans la règle « JAMAIS DE PLAN NI DE BOUTON
 * FANTÔME », et elle a dérivé à CHAQUE nouvel outil d'écriture — deux incidents
 * de production : preparer_mouvement_avenant manquant (2026-08-04), puis
 * signaler_paiement_prime manquant (2026-08-05, plan recopié en prose après un
 * simple suivi_impayes). Un outil qui produit un plan implémente cette interface,
 * et le prompt le nomme automatiquement : la liste ne peut plus mentir.
 *
 * Ne l'implémente PAS un outil qui pré-remplit seulement un gabarit sans chiffrer
 * de budget (parcours_saisie) ni un outil dont l'uiAction ouvre autre chose qu'une
 * barre de décision (ouvrir_dialogue, preparer_envoi_soa) : le modèle en
 * déduirait qu'un bouton « Valider et exécuter » existe alors qu'il n'y en a pas.
 */
interface AiToolProduisantUnPlan extends AiToolInterface
{
}
