<?php

namespace App\Ai\Fournisseur;

/**
 * Un fournisseur qui sait NOMMER sa marque d'épuisement.
 *
 * `estEpuise()` répond oui ou non ; cette interface-ci permet d'aller lire
 * JUSQU'À QUAND. C'est ce qui sépare « indisponible » — un mot qui n'aide
 * personne — de « à sec jusqu'à 09:05 », qui dit à l'agent s'il doit attendre ou
 * agir, et permet de réarmer une marque posée à tort.
 *
 * FACULTATIVE, à dessein. La voix Gemini parcourt une CHAÎNE de modèles TTS et
 * n'est « à sec » que lorsque tous le sont : elle n'a pas une marque mais
 * plusieurs, et forcer une clé unique la ferait mentir. Un fournisseur qui ne
 * l'implémente pas est simplement rendu sans échéance — jamais avec une échéance
 * inventée.
 */
interface FournisseurDatable
{
    /** La clé de ce fournisseur dans la mémoire d'épuisement — famille comprise. */
    public function cleDEpuisement(): string;
}
