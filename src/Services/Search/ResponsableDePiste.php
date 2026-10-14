<?php

namespace App\Services\Search;

use App\Entity\Invite;
use App\Entity\Piste;

/**
 * QUI EST RESPONSABLE D'UNE PISTE — une seule règle, pour la carte, la liste, Ket et la vigie.
 *
 * Le gestionnaire du portefeuille du client ; à défaut (client sans portefeuille, portefeuille
 * sans gestionnaire, piste sans client), celui qui a créé la piste.
 *
 * Elle vivait en trois versions : la carte disait « gestionnaire, sinon créateur », tandis que
 * « Mon portefeuille » (liste, Ket, vigie) ne retenait que le gestionnaire. Une piste montée
 * pour un client sans portefeuille portait donc le nom de son créateur sur sa carte… et
 * disparaissait de « Mon portefeuille » de ce même créateur.
 */
final class ResponsableDePiste
{
    /** Le responsable d'une piste (règle ci-dessus), ou null si personne n'est connu. */
    public static function de(Piste $piste): ?Invite
    {
        return $piste->getClient()?->getPortefeuille()?->getGestionnaire() ?? $piste->getInvite();
    }

    /**
     * La même règle, en DQL : « :param est le responsable de la piste ».
     *
     * @param string $gestionnaire alias du gestionnaire, joint en LEFT JOIN par
     *                             piste.client.portefeuille.gestionnaire
     * @param string $piste        alias de la piste
     * @param string $parametre    nom du paramètre portant l'id de l'invité
     */
    public static function predicat(string $gestionnaire, string $piste, string $parametre): string
    {
        return sprintf('(%1$s.id = :%3$s OR (%1$s.id IS NULL AND IDENTITY(%2$s.invite) = :%3$s))', $gestionnaire, $piste, $parametre);
    }

    /** Le chemin, depuis la piste, qui mène au gestionnaire (à joindre en LEFT JOIN). */
    public const CHEMIN_GESTIONNAIRE = 'client.portefeuille.gestionnaire';
}
