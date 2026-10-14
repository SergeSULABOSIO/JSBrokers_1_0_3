<?php

namespace App\EventListener;

use App\Entity\Note;
use App\Service\Note\NumerotationDesNotes;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * UNE NOTE REÇOIT SON NUMÉRO AU MOMENT OÙ ELLE S'ÉCRIT, ET PAS AVANT.
 *
 * ── POURQUOI À L'ÉCRITURE ───────────────────────────────────────────────────────────
 * Un numéro attribué à l'ouverture du formulaire serait consommé par chaque dialogue
 * abandonné, chaque saisie refusée en 422, chaque plan de Ket que l'utilisateur ne
 * confirme pas : la série se remplirait de trous. Ici, seule une note qui s'écrit
 * vraiment prend un numéro.
 *
 * ⚠ ET C'EST LE SEUL ENDROIT, pour toutes les portes : le dialogue, le picker de
 * facturation, Ket, la reprise et le bordereau. Toutes passent par le flush.
 *
 * ── CE QUI EST RESPECTÉ ─────────────────────────────────────────────────────────────
 * Une référence déjà posée (saisie dans le champ, qui reste éditable, ou reprise d'un
 * import) est conservée telle quelle : seule une note SANS référence en reçoit une.
 *
 * `onFlush` (même patron que {@see ConditionDOfficeListener}) : Doctrine a déjà fait
 * l'inventaire des changements, d'où le recalcul après avoir posé la référence.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class NumerotationDesNotesListener
{
    public function __construct(private readonly NumerotationDesNotes $numerotation)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Les références déjà posées sur les notes de CETTE écriture : pas encore en base,
        // elles ne doivent pas pour autant être attribuées une seconde fois.
        $prises = [];
        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof Note && trim((string) $entite->getReference()) !== '' && $entite->getEntreprise()?->getId() !== null) {
                $prises[$entite->getEntreprise()->getId()][] = (string) $entite->getReference();
            }
        }

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if (!$entite instanceof Note || trim((string) $entite->getReference()) !== '') {
                continue;
            }
            $entrepriseId = $entite->getEntreprise()?->getId();
            if ($entrepriseId === null) {
                // Sans cabinet, la note ne s'écrira pas (colonne NOT NULL) : aucun
                // numéro à consommer pour elle.
                continue;
            }

            // L'année de l'horloge de référence (APP_TIMEZONE, posé au démarrage du noyau).
            $annee = (int) (new \DateTimeImmutable())->format('Y');
            $reference = $this->numerotation->prochaine($entrepriseId, $entite->getType(), $annee, $prises[$entrepriseId] ?? []);
            $prises[$entrepriseId][] = $reference;

            $entite->setReference($reference);
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Note::class), $entite);
        }
    }
}
