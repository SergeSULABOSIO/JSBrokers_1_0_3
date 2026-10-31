<?php

namespace App\EventListener;

use App\Entity\ReversementRetroAgent;
use App\Service\Retro\ReversementIntangible;
use App\Service\Retro\ReversementIntangibleException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * LE DERNIER REMPART : aucune écriture ne modifie les champs comptables d'un reversement
 * enregistré, ni ne le supprime ({@see ReversementIntangible}, Serge 2026-10-30).
 *
 * L'écran et Ket refusent AVANT, avec un message qui explique ; ce gardien-ci arrête les portes
 * qui passent par le flush sans avoir été vues. Il laisse passer la référence, la description,
 * l'horodatage — et la NAISSANCE d'une contre-passation (une insertion, pas une modification).
 *
 * ⚠ CE QU'IL NE VOIT PAS : une suppression ou une mise à jour en masse (DQL, SQL) ne passe pas
 * par le flush. La suppression en chaîne refuse donc d'elle-même un plan qui emporterait un
 * reversement (SuppressionEnCascade), et aucun code ne supprime un reversement en DQL.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class IntangibiliteDesReversementsListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if (!$entite instanceof ReversementRetroAgent) {
                continue;
            }
            $touches = ReversementIntangible::champsComptablesTouches($uow->getEntityChangeSet($entite));
            if ($touches !== []) {
                throw new ReversementIntangibleException(ReversementIntangible::MOTIF . sprintf(' (champs : %s)', implode(', ', $touches)));
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            if ($entite instanceof ReversementRetroAgent) {
                throw new ReversementIntangibleException(ReversementIntangible::MOTIF_SUPPRESSION);
            }
        }
    }
}
