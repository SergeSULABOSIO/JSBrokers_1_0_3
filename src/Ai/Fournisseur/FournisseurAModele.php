<?php

namespace App\Ai\Fournisseur;

/**
 * UN FOURNISSEUR QUI SAIT DIRE QUEL MODÈLE IL APPELLE.
 *
 * POURQUOI LA CONSOLE EN A BESOIN. L'écran des fournisseurs propose un champ
 * « Modèle » par ligne. Tant qu'il affichait « modèle par défaut » en filigrane,
 * il ne disait pas LEQUEL : l'agent voyait un champ qui ressemblait à une
 * étiquette, sans savoir ce qu'il remplacerait en y écrivant. Le filigrane porte
 * désormais le modèle réellement en vigueur — `claude-haiku-4-5`,
 * `gemini-3.1-flash-lite`, `eleven_multilingual_v2` — et le champ redevient ce
 * qu'il est : une dérogation à une valeur connue.
 *
 * AUCUN SECRET ICI. Le nom d'un modèle n'est pas une clé d'API : il est public,
 * il figure dans la documentation du fournisseur, et l'écran est réservé aux
 * agents Joseara (`ROLE_SUPER_ADMIN`). Ce qui reste caché reste caché — les
 * clés, elles, ne quittent jamais la configuration du serveur.
 *
 * FACULTATIVE, comme `FournisseurDatable`. Un fournisseur qui n'a pas de notion
 * de modèle — ou qui en parcourt toute une chaîne — n'est pas forcé d'en
 * inventer un ; l'écran affiche alors le filigrane générique.
 */
interface FournisseurAModele
{
    /**
     * Le modèle qui serait appelé au prochain tour, tel qu'on l'écrirait dans la
     * console pour le remplacer.
     *
     * Rendre une chaîne vide quand la question n'a pas de réponse simple à cet
     * instant — c'est plus honnête qu'un nom qui ne serait pas celui appelé.
     */
    public function modeleEnVigueur(): string;
}
