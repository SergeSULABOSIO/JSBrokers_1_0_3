<?php

namespace App\Service\Retro;

/**
 * PAS DE REVERSEMENT SANS PREUVE — la règle, et son explication, à un seul endroit.
 *
 * Un reversement de rétrocommission est une sortie de fonds réelle : il a un bordereau de
 * virement, parfois un reçu signé. Un versement enregistré sans pièce est un montant que
 * rien ne rattache à la banque — introuvable en rapprochement, indéfendable en contrôle.
 *
 * ── LA RÈGLE VAUT SUR TOUS LES CHEMINS, ET C'EST TOUT L'INTÉRÊT ──────────────────────
 * Le picker et Ket écrivent le même acte. Exiger la pièce d'un côté seulement ferait de
 * l'autre le contournement de la règle : « paie Alice » dit à l'assistant suffirait à
 * s'en dispenser. Les deux appellent donc ce service, et rendent le MÊME message —
 * un utilisateur ne doit pas apprendre deux formulations d'une seule contrainte.
 *
 * ── ELLE VAUT À L'ÉCRITURE, PAS RÉTROACTIVEMENT ─────────────────────────────────────
 * Les reversements déjà en base n'ont pas de pièce et resteront ainsi : on ne réécrit pas
 * le passé. Le compte de pièces des écrans les montre à zéro — la dette de preuve
 * existante est rendue visible plutôt que masquée.
 */
final class JustificatifExige
{
    /**
     * Un versement peut-il s'écrire en l'état ?
     *
     * @param bool $aUnePiece une pièce accompagne-t-elle ce versement
     */
    public function estSatisfait(bool $aUnePiece): bool
    {
        return $aUnePiece;
    }

    /**
     * Ce qu'on dit à l'utilisateur de l'écran quand la pièce manque.
     *
     * Il a la zone de dépôt sous les yeux : le message la désigne, plutôt que d'énoncer
     * une règle abstraite qu'il faudrait traduire en geste.
     */
    public function messageEcran(): string
    {
        return 'Un reversement ne s\'enregistre pas sans justificatif : déposez le bordereau '
            . 'de virement (ou le reçu signé) dans « Pièce justificative » avant de valider.';
    }

    /**
     * Ce qu'on dit au modèle quand la pièce manque.
     *
     * Dire ce qui bloque SANS dire quoi faire produit l'impasse polie : le modèle annonce
     * un obstacle et s'arrête, l'utilisateur reste sans versement et sans marche à suivre.
     * On nomme donc le geste attendu, dans l'ordre où il doit être fait.
     */
    public function messageAssistant(): string
    {
        return 'Un reversement ne s\'enregistre pas sans justificatif. Demande à l\'utilisateur '
            . 'de joindre le bordereau de virement (ou le reçu signé) à la conversation, puis '
            . 'rappelle-moi avec son fichierId. La pièce vaut pour tout le virement : une seule '
            . 'suffit, même si le versement solde plusieurs affaires.';
    }
}
