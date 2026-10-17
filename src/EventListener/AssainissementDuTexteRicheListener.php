<?php

namespace App\EventListener;

use App\Service\TexteRiche\ChampsEnTexteRiche;
use App\Service\TexteRiche\TexteRiche;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * LE TEXTE RICHE EST ASSAINI À L'ÉCRITURE — par toutes les portes.
 *
 * Écran, Ket, import, workflows : tous finissent par un flush. C'est donc ici, et non dans
 * un formulaire, que le HTML d'un champ riche (ChampsEnTexteRiche) est réduit à ce que la
 * barre d'outils de Quill produit. Avant ce listener, le contenu d'un <textarea> était
 * stocké tel quel — `<img onerror=…>` compris — et réinjecté en HTML à l'affichage.
 *
 * Seule une valeur RICHE (TexteRiche::estRiche : un bloc de Quill en tête) est assainie :
 * un texte simple écrit par Ket ou un import reste intact — toutes ses restitutions le
 * traitent déjà comme du texte. L'existant, lui, est assaini à la LECTURE (DOMPurify).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AssainissementDuTexteRicheListener
{
    public function __construct(private readonly TexteRiche $texteRiche)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entite) {
            $champs = ChampsEnTexteRiche::de($entite);
            if ($champs === []) {
                continue;
            }

            $meta = $em->getClassMetadata($entite::class);
            $modifie = false;
            foreach ($champs as $champ) {
                $valeur = $meta->getFieldValue($entite, $champ);
                if (!TexteRiche::estRiche($valeur)) {
                    continue;
                }
                $assaini = $this->texteRiche->assainir($valeur);
                if ($assaini !== $valeur) {
                    $meta->setFieldValue($entite, $champ, $assaini);
                    $modifie = true;
                }
            }

            if ($modifie) {
                $uow->recomputeSingleEntityChangeSet($meta, $entite);
            }
        }
    }
}
