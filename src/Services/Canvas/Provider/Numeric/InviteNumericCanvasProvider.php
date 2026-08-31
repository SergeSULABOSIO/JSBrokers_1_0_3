<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Invite;

/**
 * L'ALIGNEMENT DE CETTE RUBRIQUE ÉTAIT JUSTE — MAIS PAR HASARD.
 *
 * Ses huit colonnes correspondaient déjà aux huit options de la barre. Non parce qu'une
 * règle le garantissait, mais parce que les huit codes se trouvaient dans le dictionnaire
 * figé du trait générique, et que `property_exists()` les y retrouvait.
 *
 * Or trois d'entre eux — primeTotale, montantTTC, montantPur — ne sont déclarés NULLE PART
 * sur Invite : InviteIndicatorStrategy les pose à la volée, et rend un tableau vide quand
 * l'invité n'a pas d'entreprise. Il suffisait qu'un tel invité soit en TÊTE de page pour
 * que les huit options disparaissent du sélecteur — pour la page entière, puisqu'il se
 * construit sur la première ligne seulement.
 *
 * L'alignement est maintenant CONSTRUIT : les colonnes le décident, et chaque clé existe
 * même pour un invité sans la moindre affaire.
 */
class InviteNumericCanvasProvider extends AbstractListColumnsNumericCanvasProvider
{
    protected function entityClass(): string
    {
        return Invite::class;
    }
}
