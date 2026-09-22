<?php

namespace App\Ai\Dictee;

use App\Ai\Fournisseur\FournisseurDatable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Membre de la chaîne des fournisseurs de finition de dictée.
 *
 * Même rôle, et même raison d'exister, que MoteurDeTexte et
 * FournisseurDeComprehension : le résolveur implémente lui aussi le contrat, et
 * le tagger le ferait entrer dans sa propre chaîne. Le marqueur ferme le cycle
 * et supprime toute liste à tenir à jour.
 */
#[AutoconfigureTag('app.fournisseur_finition')]
interface FournisseurDeFinition extends AppelDeFinition, FournisseurDatable
{
}
