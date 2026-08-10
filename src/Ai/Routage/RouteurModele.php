<?php

namespace App\Ai\Routage;

/**
 * L'appel de COMPRÉHENSION du routeur, isolé derrière une interface.
 *
 * Deux raisons, et la première est structurelle : le routeur est consommé par le
 * moteur, donc il ne peut pas dépendre du moteur sans créer un cycle. La seconde est
 * qu'un routage n'a pas besoin de la machinerie d'un tour ordinaire — ni outils, ni
 * boucle de function calling, ni journal de tours : juste une question posée à un
 * modèle, et un mot en réponse.
 *
 * Rendre null n'est jamais une erreur : c'est « je ne sais pas ». L'appelant retombe
 * alors sur la trousse complète (FAIL-OPEN — on préfère payer un tour lourd que
 * priver l'utilisateur d'une capacité).
 */
interface RouteurModele
{
    /**
     * @param string       $instruction consigne de routage (courte, stable)
     * @param string       $catalogue   catalogue condensé des outils
     * @param list<array{role: string, content: string}> $messages fin de l'historique + question courante
     *
     * @return array{trousse: ?string, tokens: int} le nom de trousse choisi (null si
     *                                              indécis ou moteur indisponible) et
     *                                              les tokens d'entrée consommés
     */
    public function choisirTrousse(string $instruction, string $catalogue, array $messages): array;
}
