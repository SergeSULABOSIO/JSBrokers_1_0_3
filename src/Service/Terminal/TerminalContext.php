<?php

namespace App\Service\Terminal;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @file Le terminal de la requête en cours, à disposition de tout le monde.
 * @description Contrôleurs, extension Twig et sonde partagent une seule réponse
 * — sans quoi le gabarit pourrait annoncer un mode que le contrôleur n'a pas
 * servi.
 *
 * ── AUCUNE MÉMORISATION, VOLONTAIREMENT ────────────────────────────────────
 * La tentation serait de retenir le résultat dans une propriété. Ce serait un
 * piège : les services de ce projet sont aussi instanciés dans un worker
 * Messenger, où le même objet vit à travers PLUSIEURS messages. Une valeur
 * retenue là-bas serait celle de la première requête, pour toujours. La
 * détection ne coûte que deux `preg_match` — moins cher qu'un bug invisible.
 *
 * Hors requête (console, worker), la réponse est `ORDINATEUR` : c'est le mode
 * qui n'enlève rien, et le seul qui ait un sens quand personne ne regarde.
 */
final class TerminalContext
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly DetecteurDeTerminal $detecteur,
    ) {
    }

    public function courant(): Terminal
    {
        $requete = $this->requete();

        return $requete === null ? Terminal::ORDINATEUR : $this->detecteur->detecter($requete);
    }

    /** Cet appareil reçoit-il la conversation en plein écran plutôt que les colonnes ? */
    public function modeKet(): bool
    {
        return $this->courant()->modeKet();
    }

    /**
     * Ce que l'appareil est, indépendamment du mode demandé. Sert uniquement à
     * proposer le retour au mode conversation, et à ne pas le proposer sur un
     * vrai ordinateur. Cf. DetecteurDeTerminal::detecterSansChoix().
     */
    public function appareil(): Terminal
    {
        $requete = $this->requete();

        return $requete === null ? Terminal::ORDINATEUR : $this->detecteur->detecterSansChoix($requete);
    }

    /** Le mode a-t-il été choisi explicitement (URL ou cookie) ? Cf. DetecteurDeTerminal::estFige(). */
    public function estFige(): bool
    {
        $requete = $this->requete();

        return $requete !== null && $this->detecteur->estFige($requete);
    }

    /**
     * La requête PRINCIPALE, et non la courante : un fragment rendu en
     * sous-requête (`render()` Twig, ESI) n'apporte ni cookies ni en-têtes
     * propres, et répondrait « ordinateur » au milieu d'une page mobile.
     */
    private function requete(): ?Request
    {
        return $this->requestStack->getMainRequest();
    }
}
