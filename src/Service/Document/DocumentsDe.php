<?php

namespace App\Service\Document;

use App\Entity\Document;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES DOCUMENTS D'UN OBJET, quel que soit le mécanisme qui les y rattache.
 *
 * POURQUOI CE SERVICE PLUTÔT QU'UNE COLLECTION SUR CHAQUE ENTITÉ. Quinze entités
 * portent une vraie collection Doctrine `documents` (ou `preuves`) ; les soixante-deux
 * autres n'en ont pas, et leur en donner une aurait voulu dire soixante-deux colonnes
 * de plus sur la table `document` — une par entité, à refaire à chaque entité nouvelle.
 * Le rattachement universel (`cibleType`/`cibleId`) supprime cette limite, mais il ne
 * peut pas s'exprimer en OneToMany : Doctrine ne sait pas mapper une relation dont la
 * cible n'est connue qu'à l'exécution.
 *
 * Ce service est donc l'accesseur que la collection aurait été. Pour l'appelant, il n'y
 * a qu'une question — « quels fichiers accompagnent cet objet ? » — et une réponse,
 * peu importe par quel chemin le rattachement a été écrit.
 *
 * DEUX SOURCES, JAMAIS DEUX FOIS LE MÊME DOCUMENT : PieceSourceRattachement n'écrit le
 * couple universel que là où aucune relation typée n'existe, donc les deux ensembles
 * sont disjoints par construction. La déduplication par identifiant reste là comme
 * filet, pour qu'une donnée héritée d'un import ou d'une correction manuelle ne
 * produise pas une ligne en double à l'écran.
 */
final class DocumentsDe
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Les documents rattachés à cet objet, relations typées ET rattachement universel.
     *
     * @return list<Document>
     */
    public function pour(object $entity): array
    {
        $documents = [];
        foreach ([...$this->collectionTypee($entity), ...$this->universels($entity)] as $document) {
            $documents[(int) $document->getId()] = $document;
        }

        return array_values($documents);
    }

    /** Y a-t-il au moins un fichier derrière cet objet ? (sans les charger tous) */
    public function auMoinsUn(object $entity): bool
    {
        return $this->pour($entity) !== [];
    }

    /**
     * La collection Doctrine de l'entité, quand elle en a une.
     *
     * Les deux noms possibles sont ceux que l'entité déclare réellement : `documents`
     * partout, sauf Paiement et PaiementPrime où la collection s'appelle `preuves` —
     * un document y sert de PREUVE, pas de pièce du dossier, et le formulaire le dit
     * ainsi à l'utilisateur. Chercher les deux évite de trahir ce vocabulaire.
     *
     * @return iterable<Document>
     */
    private function collectionTypee(object $entity): iterable
    {
        foreach (['getDocuments', 'getPreuves'] as $getter) {
            if (!method_exists($entity, $getter)) {
                continue;
            }
            $valeur = $entity->{$getter}();
            if ($valeur instanceof Collection) {
                return $valeur->toArray();
            }
            if (is_array($valeur)) {
                return $valeur;
            }
        }

        return [];
    }

    /**
     * Les documents que le couple universel désigne.
     *
     * Le type est le nom COURT de la classe réelle : une entité chargée par Doctrine
     * peut arriver ici sous forme de proxy, dont le nom court est celui d'une
     * sous-classe générée. `getRealClass` est la seule façon fiable de retrouver le
     * nom sous lequel le rattachement a été écrit.
     *
     * @return list<Document>
     */
    private function universels(object $entity): array
    {
        if (!method_exists($entity, 'getId') || $entity->getId() === null) {
            return [];
        }

        $classe = $this->em->getClassMetadata($entity::class)->getName();

        return $this->em->getRepository(Document::class)->findBy([
            'cibleType' => (new \ReflectionClass($classe))->getShortName(),
            'cibleId'   => (int) $entity->getId(),
        ]);
    }
}
