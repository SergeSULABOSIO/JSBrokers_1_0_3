<?php

namespace App\EventSubscriber;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

/**
 * L'ÉQUIVALENT APPLICATIF D'UN `ON DELETE CASCADE`, pour le rattachement universel.
 *
 * POURQUOI IL FAUT DU CODE ICI. Les quinze relations typées de `Document` sont de
 * vraies clés étrangères : supprimer un avenant emporte ses documents, la base s'en
 * charge. Le couple `cibleType`/`cibleId`, lui, ne peut pas porter de contrainte —
 * aucune clé étrangère ne sait pointer soixante-dix tables à la fois. Sans cet
 * abonné, supprimer une tranche laisserait derrière elle un document rattaché à un
 * identifiant qui ne désigne plus rien : invisible dans les listes, mais bien présent
 * en base, et son binaire avec lui.
 *
 * ⚠️ `preRemove`, ET NON `postRemove` — c'est une question de MOMENT, pas de goût.
 * `preRemove` est déclenché par l'appel à `remove()`, donc AVANT le flush : les
 * documents qu'on programme ici partent dans la même unité de travail et la même
 * transaction que leur parent. Si celle-ci est annulée, rien n'est perdu. Sur
 * `postRemove`, le DELETE du parent est déjà exécuté et la boucle de suppression est
 * close : les documents programmés à ce moment-là ne seraient jamais écrits.
 *
 * Une suppression en CASCADE passe elle aussi par `remove()` — supprimer un client
 * emporte ses avenants, et cet abonné voit chacun d'eux.
 *
 * On passe par l'EntityManager plutôt que par une requête DQL de masse, précisément
 * parce que Vich écoute lui aussi ces événements : c'est `remove()` qui déclenche
 * l'effacement du BINAIRE sur le disque. Un `DELETE` direct laisserait les fichiers.
 */
#[AsDoctrineListener(event: Events::preRemove)]
class DocumentsOrphelinsSubscriber
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function preRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        // Un Document supprimé ne peut pas être le parent universel d'un autre : sans
        // cette sortie, on ouvrirait une récursion pour rien à chaque suppression de
        // fichier — c'est-à-dire dans le cas le plus fréquent.
        if ($entity instanceof Document) {
            return;
        }
        if (!method_exists($entity, 'getId') || $entity->getId() === null) {
            return;
        }

        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $cibleType = (new \ReflectionClass($em->getClassMetadata($entity::class)->getName()))->getShortName();
        $orphelins = $em->getRepository(Document::class)->findBy([
            'cibleType' => $cibleType,
            'cibleId'   => (int) $entity->getId(),
        ]);
        if ($orphelins === []) {
            return;
        }

        foreach ($orphelins as $document) {
            $em->remove($document);
        }

        // Trace délibérée : la suppression d'un fichier est irréversible, et elle a
        // lieu ici par ricochet — l'utilisateur a demandé à supprimer une tranche, pas
        // ses pièces. Si la question se pose un jour, le journal y répond.
        $this->logger->info('Documents rattachés supprimés avec leur objet.', [
            'cibleType' => $cibleType,
            'cibleId'   => $entity->getId(),
            'documents' => count($orphelins),
        ]);
    }
}
