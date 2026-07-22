<?php

namespace App\Ai\Tool;

/**
 * Garde de ROUTAGE partagée du moteur simulé : « cette question porte-t-elle sur le
 * paiement d'une PRIME d'assurance ? »
 *
 * Raison d'être : le lexique des entités fait correspondre le mot « paiements » à la
 * rubrique Paiements — la TRÉSORERIE du courtier —, un tout autre circuit métier que
 * le signalement du paiement d'une prime (encaissée par l'ASSUREUR). Sans cette garde,
 * « liste les paiements de prime de la tranche 12 » partait sur entite=Paiement et
 * répondait juste à côté. Les outils génériques (rechercher/compter/lire_fiche) et
 * ouvrir_dialogue s'écartent donc quand elle répond vrai, laissant la main aux deux
 * outils dédiés : paiements_prime (lecture) et signaler_paiement_prime (action).
 *
 * Ne concerne QUE le chemin simulé : un LLM réel arbitre sur les descriptions d'outils.
 */
final class PaiementPrimeIntent
{
    /** @param string $texteNormalise Question passée par AiText::normalize(). */
    public static function concerne(string $texteNormalise): bool
    {
        return (bool) preg_match('/\bprimes?\b/', $texteNormalise)
            && (bool) preg_match('/\b(paiements?|payee?s?|regle[es]?|reglements?|signal\w*)\b/', $texteNormalise);
    }

    /**
     * La formulation demande-t-elle à VOIR (lecture) plutôt qu'à FAIRE (action) ?
     *
     * Indispensable ici parce que l'accent tombe à la normalisation : « signalés »
     * (participe, donc une question) devient « signales », indiscernable de l'impératif
     * « signale ». L'interrogation l'emporte donc explicitement — sans quoi « quels
     * paiements de prime ont été signalés ? » ouvrait un formulaire de saisie.
     */
    public static function estInterrogatif(string $texteNormalise): bool
    {
        return (bool) preg_match(
            '/\b(quels?|quelles?|liste[rsz]?|affiche[rsz]?|montre[rsz]?|details?|detaille[rsz]?|historique'
            . '|combien|infos?|informations?|a[- ]t[- ]elle|ont ete|est[- ]elle|y a[- ]t[- ]il)\b/',
            $texteNormalise
        );
    }
}
