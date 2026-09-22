<?php

namespace App\Ai\Comprehension;

use App\Ai\Fournisseur\FournisseurDatable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Membre de la chaîne des fournisseurs de la phase de compréhension.
 *
 * Même rôle, et même raison d'exister, que MoteurDeTexte pour le moteur : le
 * résolveur implémente lui aussi `AppelDeComprehension`, et le tagger par
 * `_instanceof` le ferait entrer dans sa propre chaîne — il s'appellerait en
 * boucle. Un marqueur que les IMPLÉMENTATIONS portent et que le résolveur ne
 * porte pas ferme le cycle sans écrire de liste nulle part.
 *
 * Ajouter un fournisseur de compréhension, c'est implémenter ceci. Rien d'autre.
 */
#[AutoconfigureTag('app.fournisseur_comprehension')]
interface FournisseurDeComprehension extends AppelDeComprehension, FournisseurDatable
{
}
