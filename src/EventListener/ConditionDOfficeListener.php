<?php

namespace App\EventListener;

use App\Entity\ConditionPartage;
use App\Entity\Partenaire;
use App\Service\Partage\ConditionDOffice;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * LA « PART % » RESTE LA SOURCE DU TAUX DE LA CONDITION D'OFFICE.
 *
 * ── LE RISQUE QU'IL ÉCARTE ──────────────────────────────────────────────────────────
 * Un partenaire porte désormais deux écritures du même nombre : sa « Part % », affichée
 * sur sa fiche, et le taux de la condition que son formulaire lui donne
 * ({@see \App\Services\FormListenerFactory::conditionDOffice()}). Deux écritures du même
 * nombre finissent toujours par diverger — et c'est le TAUX qui paierait, pendant que la
 * fiche annoncerait la part. Corriger la part sans corriger la condition, c'est donc payer
 * l'ancien pourcentage en croyant avoir changé de règle.
 *
 * ── POURQUOI ICI, ET NON DANS LE FORMULAIRE ─────────────────────────────────────────
 * La règle a besoin de la part d'AVANT : comparer la condition à la part NOUVELLE, déjà
 * posée sur l'entité, ne dirait jamais rien. Le jeu de changements de Doctrine est le seul
 * endroit où l'ancienne valeur existe encore — et il vaut pour l'écran comme pour
 * l'assistant, sans second chemin à tenir d'accord.
 *
 * `onFlush` plutôt que `preUpdate` : on modifie une AUTRE entité que celle qui change, ce
 * que `preUpdate` interdit ; à charge pour nous de recalculer son jeu de changements,
 * puisque Doctrine a déjà fait son inventaire (même patron que
 * {@see ClasseurAutomatiqueListener}).
 *
 * ── ET JAMAIS PAR-DESSUS UNE DÉCISION ───────────────────────────────────────────────
 * Seule suit la condition dont le taux ÉGALE ENCORE la part précédente. Dès qu'il s'en
 * écarte, elle appartient à l'utilisateur : un taux négocié à la main n'est pas rattrapé
 * par un ajustement de la part. Cette filiation ne demande aucune colonne — elle se lit
 * dans les valeurs elles-mêmes ({@see ConditionDOffice::suivantLaPart()}).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ConditionDOfficeListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $partenaires = array_filter(
            $uow->getScheduledEntityUpdates(),
            static fn (object $e): bool => $e instanceof Partenaire,
        );

        if ($partenaires === []) {
            return;
        }

        $metaCondition = $em->getClassMetadata(ConditionPartage::class);

        foreach ($partenaires as $partenaire) {
            $changements = $uow->getEntityChangeSet($partenaire);
            if (!isset($changements['part'])) {
                continue;
            }

            [$avant, $apres] = $changements['part'];
            if (ConditionDOffice::memeTaux(self::enNombre($avant), self::enNombre($apres))) {
                continue;
            }

            $condition = ConditionDOffice::suivantLaPart($partenaire, self::enNombre($avant));
            if ($condition === null) {
                continue;
            }

            $condition->setTaux(self::enNombre($apres));
            $uow->recomputeSingleEntityChangeSet($metaCondition, $condition);
        }
    }

    /** Une part absente est une absence, pas un zéro : la confondre paierait 0 %. */
    private static function enNombre(mixed $valeur): ?float
    {
        return $valeur === null ? null : (float) $valeur;
    }
}
