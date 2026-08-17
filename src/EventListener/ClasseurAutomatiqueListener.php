<?php

namespace App\EventListener;

use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Document;
use App\Service\Document\ClasseurDuClient;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * LE POINT UNIQUE OÙ UN DOCUMENT SE RANGE — au ras de Doctrine, donc partout.
 *
 * POURQUOI ICI, ET NON DANS LES SERVICES QUI ÉCRIVENT. Un document naît aujourd'hui de
 * trois endroits : le formulaire de la rubrique (via le trait CRUD), le sélecteur de
 * pièces jointes ({@see \App\Controller\Admin\DocumentController::attacherApi()}), et les
 * plans d'écriture de Ket ({@see \App\Service\Workspace\WorkspaceMutationService}). Poser
 * la règle dans chacun, c'était trois copies à maintenir d'accord, et une quatrième
 * oubliée le jour où un nouveau chemin apparaît — exactement la façon dont le classeur
 * était resté vide : le champ existait dans le formulaire, et personne ne le remplissait.
 *
 * La demande était que le rangement soit AUTOMATIQUE, et qu'il vaille autant pour
 * l'interface que pour Ket. Un seul endroit satisfait littéralement les deux : le flush.
 * Tout ce qui enregistre un document passe par là, y compris le rattrapage des anciennes
 * données et tout code futur.
 *
 * POURQUOI `onFlush` ET NON `prePersist`. `prePersist` arrive trop tôt pour faire naître
 * une entité de plus : un `Classeur` créé là ne serait pas inséré dans le même flush, et
 * le document partirait avec une clé étrangère vers un enregistrement inexistant.
 * `onFlush` est le moment prévu pour cela — à charge pour nous de calculer les
 * changements à la main, puisque Doctrine a déjà fait le tour des entités.
 *
 * CE QUI N'EST PAS FACTURÉ. Le classeur créé n'est pas compté au métrage de jetons. Ce
 * n'est pas un oubli : l'utilisateur a demandé à enregistrer UN document, il en paie un.
 * Lui facturer en plus le meuble où sa pièce se range serait lui faire payer une décision
 * qu'il n'a pas prise.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ClasseurAutomatiqueListener
{
    public function __construct(
        private readonly ClasseurDuClient $classeurs,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // LES CRÉATIONS ET LES MODIFICATIONS. Les créations sont le cas courant ; les
        // modifications comptent aussi, parce qu'un document peut recevoir son
        // rattachement dans un second temps — une pièce d'abord enregistrée seule, puis
        // reliée à une police. Elle doit alors rejoindre le dossier du client.
        $enJeu = [...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()];

        $documents = array_filter($enJeu, static fn (object $e): bool => $e instanceof Document);
        $clients = array_filter($enJeu, static fn (object $e): bool => $e instanceof Client);

        if ($documents === [] && $clients === []) {
            return;
        }

        $metaClasseur = $em->getClassMetadata(Classeur::class);
        $metaDocument = $em->getClassMetadata(Document::class);

        // ── LE CLASSEUR NAÎT AVEC LE CLIENT, sans attendre sa première pièce.
        //
        // La règle voulue est « TOUT client a son classeur », et non « tout client qui a
        // reçu un document ». La différence se voit : un dossier qui n'apparaît qu'au
        // premier fichier laisse l'utilisateur devant une liste de classeurs incomplète,
        // et lui fait croire que certains clients n'en ont pas droit. Le classeur existe
        // donc dès la création du client, vide — un dossier vide est un dossier prêt.
        //
        // Une MODIFICATION de client passe par le même chemin, ce qui met à jour
        // l'intitulé du classeur quand le client est renommé ({@see ClasseurDuClient::pour()}).
        foreach ($clients as $client) {
            if ($client->getEntreprise() === null) {
                continue;
            }

            $classeur = $this->classeurs->pour($client);
            if ($classeur->getId() === null) {
                $uow->computeChangeSet($metaClasseur, $classeur);
            } else {
                $uow->recomputeSingleEntityChangeSet($metaClasseur, $classeur);
            }
        }

        foreach ($documents as $document) {
            $classeur = $this->classeurs->ranger($document);
            if (!$classeur instanceof Classeur) {
                continue;
            }

            // Le classeur vient d'être persisté EN PLEIN FLUSH : Doctrine avait déjà fait
            // son inventaire et ne le verrait pas. On lui calcule son jeu de changements,
            // sans quoi l'insertion n'aurait tout simplement pas lieu et le document
            // pointerait dans le vide.
            if ($classeur->getId() === null) {
                $uow->computeChangeSet($metaClasseur, $classeur);
            }

            // LE DOCUMENT A CHANGÉ APRÈS SON PROPRE INVENTAIRE, et c'est ici la seule
            // méthode qui convienne aux deux cas. `computeChangeSet` sur un document déjà
            // en file d'insertion refabrique un jeu de changements partiel, que le
            // persisteur ne sait plus lier à son ordre d'insertion : la base répond
            // « nombre de variables liées différent du nombre de jetons » et
            // l'enregistrement entier échoue. `recompute` ADJOINT le champ posé, sans
            // toucher au reste — vrai pour une création comme pour une modification.
            $uow->recomputeSingleEntityChangeSet($metaDocument, $document);
        }
    }
}
