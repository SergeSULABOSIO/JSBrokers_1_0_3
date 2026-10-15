<?php

namespace App\EventListener;

use App\Entity\Article;
use App\Entity\Note;
use App\Service\Note\NoteIntangible;
use App\Service\Note\NoteIntangibleException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * LE DERNIER REMPART : aucune écriture ne modifie, n'ampute ni ne supprime une note
 * intangible (règle OHADA, décision du 2026-10-15 ; {@see NoteIntangible}).
 *
 * L'écran et Ket refusent AVANT, avec un message qui explique. Ce gardien-ci est pour
 * les portes qui passent par le flush sans avoir été vues : il ne laisse passer que ce
 * qui appartient à l'émission même —
 *  • la VALIDATION (brouillon → validée) et ce qu'elle pose : numéro, date d'émission,
 *    montants figés ;
 *  • le FIGEAGE d'une note qui ne l'était pas (l'existant, `app:notes:figer`) ;
 *  • la date d'ENVOI (`sentAt`), information de suivi sans effet comptable ;
 *  • l'horodatage technique `updatedAt`.
 * Tout le reste lève {@see NoteIntangibleException} : rien n'est écrit, et les portes la
 * rendent en refus lisible.
 *
 * ⚠ CE QU'IL NE VOIT PAS : un UPDATE en masse (DQL, SQL) ne passe pas par le flush. Les
 * détachements de la suppression en chaîne sont donc contrôlés par SuppressionEnCascade.
 *
 * Une note de REPRISE (figée, non validée) se supprime ENTIÈRE pour défaire un import
 * erroné (décision du 2026-10-16) ; elle ne perd pas une ligne pour autant.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class IntangibiliteDesNotesListener
{
    /** Ce qui change toujours sans toucher la pièce. */
    private const TOUJOURS_PERMIS = ['updatedAt', 'sentAt'];
    /** Ce que pose le figeage d'une note qui ne l'était pas encore. */
    private const POSES_PAR_LE_FIGEAGE = ['figeeLe', 'montantHtFige', 'montantTtcFige', 'libelleTaxeFige', 'tauxTaxeFige'];
    private const POSES_SUR_UNE_LIGNE = ['montantHtFige', 'montantTtcFige', 'updatedAt'];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if ($entite instanceof Note) {
                $this->controlerLaNote($entite, $uow->getEntityChangeSet($entite));
            } elseif ($entite instanceof Article) {
                $this->controlerLaLigne($entite, $uow->getEntityChangeSet($entite), $uow);
            }
        }

        // Les associations MULTIPLES (les comptes bancaires imprimés sur la note) ne
        // paraissent pas dans le changeset de la note : on les regarde à part.
        // ⚠ LES SEULES QUE LA NOTE POSSÈDE (plusieurs-à-plusieurs, côté propriétaire). Ses
        // paiements, ses documents, ses lignes sont des collections INVERSES : Doctrine les
        // liste aussi quand on y ajoute, mais encaisser une note émise reste permis, et une
        // ligne ajoutée est contrôlée à son insertion.
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $note = $collection->getOwner();
            $association = $collection->getMapping();
            if ($note instanceof Note && $association->isManyToMany() && $association->isOwningSide()
                && !$uow->isScheduledForInsert($note) && $this->etaitIntangible($note, $uow)) {
                throw new NoteIntangibleException(NoteIntangible::motif($note));
            }
        }

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            // Une ligne AJOUTÉE à une note intangible déjà écrite. (Une note créée validée,
            // avec ses lignes, dans la même écriture — le picker — est neuve : permise.)
            if ($entite instanceof Article && $entite->getNote()?->getId() !== null
                && !$uow->isScheduledForInsert($entite->getNote()) && $this->etaitIntangible($entite->getNote(), $uow)) {
                throw new NoteIntangibleException(NoteIntangible::motif($entite->getNote(), true));
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            if ($entite instanceof Note) {
                // Une note VALIDÉE jamais ; une note de reprise, entière, oui.
                if ($entite->isValidated() === true) {
                    throw new NoteIntangibleException(NoteIntangible::motif($entite));
                }
            } elseif ($entite instanceof Article && ($note = $entite->getNote()) !== null && $this->etaitIntangible($note, $uow)
                // Une ligne qui part AVEC sa note de reprise (suppression entière) : permise.
                && !($note->isValidated() !== true && $uow->isScheduledForDelete($note))) {
                throw new NoteIntangibleException(NoteIntangible::motif($note, true));
            }
        }
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changements */
    private function controlerLaNote(Note $note, array $changements): void
    {
        $etaitValidee = (array_key_exists('validated', $changements) ? $changements['validated'][0] : $note->isValidated()) === true;
        $etaitFigee = (array_key_exists('figeeLe', $changements) ? $changements['figeeLe'][0] : $note->getFigeeLe()) !== null;
        if (!$etaitValidee && !$etaitFigee) {
            return; // un brouillon : tout lui est permis, y compris sa validation
        }

        $permis = $etaitFigee ? self::TOUJOURS_PERMIS : array_merge(self::TOUJOURS_PERMIS, self::POSES_PAR_LE_FIGEAGE);
        $interdits = array_diff(array_keys($changements), $permis);
        if ($interdits !== []) {
            throw new NoteIntangibleException(NoteIntangible::motif($note) . sprintf(' (champs : %s)', implode(', ', $interdits)));
        }
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changements */
    private function controlerLaLigne(Article $ligne, array $changements, UnitOfWork $uow): void
    {
        // LA NOTE D'AVANT ET CELLE D'APRÈS : déplacer une ligne hors d'une note intangible
        // l'ampute, la rattacher à une note intangible la grossit.
        $avant = array_key_exists('note', $changements) ? $changements['note'][0] : $ligne->getNote();
        $apres = array_key_exists('note', $changements) ? $changements['note'][1] : null;
        if ($apres instanceof Note && $apres !== $avant && !$uow->isScheduledForInsert($apres) && $this->etaitIntangible($apres, $uow)) {
            throw new NoteIntangibleException(NoteIntangible::motif($apres, true));
        }
        if (!$avant instanceof Note || !NoteIntangible::estIntangible($avant)) {
            return;
        }
        // Le figeage de la ligne, quand sa note se valide ou se fige dans la même écriture.
        $figeage = array_diff(array_keys($changements), self::POSES_SUR_UNE_LIGNE) === []
            && ($changements['montantTtcFige'][0] ?? null) === null;
        if (!$figeage) {
            throw new NoteIntangibleException(NoteIntangible::motif($avant, true));
        }
    }

    /** L'état AVANT cette écriture : une note qui devient validée ici n'était pas intangible. */
    private function etaitIntangible(Note $note, UnitOfWork $uow): bool
    {
        $changements = $uow->getEntityChangeSet($note);
        $validee = (array_key_exists('validated', $changements) ? $changements['validated'][0] : $note->isValidated()) === true;
        $figee = (array_key_exists('figeeLe', $changements) ? $changements['figeeLe'][0] : $note->getFigeeLe()) !== null;

        return $validee || $figee;
    }
}
