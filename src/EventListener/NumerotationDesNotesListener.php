<?php

namespace App\EventListener;

use App\Entity\Note;
use App\Service\Note\NumerotationDesNotes;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * LE NUMÉRO DÉFINITIF NAÎT AVEC LA VALIDATION ; UN BROUILLON N'A QU'UNE RÉFÉRENCE PROVISOIRE.
 *
 * ── CE QUI SE PASSE À L'ÉCRITURE ────────────────────────────────────────────────────
 *  • Un BROUILLON qui s'écrit sans référence reçoit `BROUILLON-XXXXXXXX` : il ne
 *    consomme aucun numéro, et sa suppression ne laisse aucun trou.
 *  • Une note qui DEVIENT validée — créée validée (picker de facturation) ou passée de
 *    brouillon à validée (geste « Valider », Ket) — reçoit le numéro suivant de sa
 *    série et sa date d'émission, qui est l'instant même : l'année de la série est celle
 *    de l'émission (règle OHADA, décision du 2026-10-15).
 *
 * ⚠ UNE NOTE DÉJÀ ÉMISE GARDE SON NUMÉRO. Celle dont la date d'émission est déjà posée
 * — l'existant repris (Version20261015170000), une pièce historique — n'est pas
 * renumérotée : un numéro émis se conserve, même hors du format de la série.
 *
 * ⚠ C'EST LE SEUL ENDROIT, pour toutes les portes : toutes passent par le flush. Mais
 * `onFlush` précède la transaction de Doctrine : sans transaction englobante, un échec
 * de l'écriture perdrait le numéro. Les portes métier valident donc par
 * {@see \App\Service\Note\EmissionDeNote::valider}, qui l'enveloppe.
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
        $meta = $em->getClassMetadata(Note::class);

        // Références provisoires posées dans CETTE écriture, pas encore en base.
        $prises = [];

        foreach ($uow->getScheduledEntityInsertions() as $note) {
            if (!$note instanceof Note || ($entrepriseId = $note->getEntreprise()?->getId()) === null) {
                // Sans cabinet, la note ne s'écrira pas (colonne NOT NULL).
                continue;
            }
            if ($note->isValidated() === true && $note->getEmiseLe() === null) {
                $this->emettre($note, $entrepriseId);
            } elseif (trim((string) $note->getReference()) === '') {
                $reference = $this->numerotation->provisoire($entrepriseId, NumerotationDesNotes::PREFIXE_BROUILLON, $prises[$entrepriseId] ?? []);
                $prises[$entrepriseId][] = $reference;
                $note->setReference($reference);
            } else {
                continue;
            }
            $uow->recomputeSingleEntityChangeSet($meta, $note);
        }

        foreach ($uow->getScheduledEntityUpdates() as $note) {
            if (!$note instanceof Note || $note->isValidated() !== true || $note->getEmiseLe() !== null) {
                continue;
            }
            $changement = $uow->getEntityChangeSet($note)['validated'] ?? null;
            if ($changement === null || $changement[0] === true) {
                continue;
            }
            $this->emettre($note, (int) $note->getEntreprise()->getId());
            $uow->recomputeSingleEntityChangeSet($meta, $note);
        }
    }

    /** Le numéro suivant de la série, et la date d'émission : l'instant de la validation. */
    private function emettre(Note $note, int $entrepriseId): void
    {
        // L'horloge de référence (APP_TIMEZONE, posé au démarrage du noyau).
        $annee = (int) (new \DateTimeImmutable())->format('Y');
        $note->setReference($this->numerotation->prochaine($entrepriseId, $note->getType(), $annee));
        // LA DATE APRÈS LE NUMÉRO : prise une fois le verrou de la série obtenu, elle ne
        // peut précéder celle de la note qui a pris le numéro d'avant.
        $note->setEmiseLe(new \DateTimeImmutable());
    }
}
