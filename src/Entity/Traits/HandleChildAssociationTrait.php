<?php
namespace App\Entity\Traits;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait HandleChildAssociationTrait
{
    /**
     * Doit être implémentée par le contrôleur qui utilise ce Trait.
     * * Cette fonction est un "contrat". Elle oblige le contrôleur à déclarer
     * quels sont les parents possibles pour l'entité qu'il gère.
     * * @return array La carte d'association, ex: ['pieceSinistre' => PieceSinistre::class]
     */
    abstract protected function getParentAssociationMap(): array;

    /**
     * La fonction principale de notre "boîte à outils".
     * Elle parcourt les données envoyées par le client, cherche une clé qui
     * correspond à un parent connu, et effectue la liaison.
     *
     * @param object $childEntity L'entité enfant (ex: un objet Document)
     * @param array $data Les données reçues de la requête (ex: $_POST)
     * @param EntityManagerInterface $em Le gestionnaire d'entités
     */
    protected function associateParent(object $childEntity, array $data, EntityManagerInterface $em): void
    {
        // On récupère la "notice d'instructions" fournie par le contrôleur
        $parentMap = $this->getParentAssociationMap();

        // On parcourt la notice
        foreach ($parentMap as $key => $class) {
            // Si on trouve une clé correspondante dans les données envoyées par le client...
            // (ex: si la clé 'pieceSinistre' existe dans les données)
            if (isset($data[$key])) {
                $parentId = $data[$key];
                
                // On récupère une référence au parent sans le charger complètement de la BDD
                $parent = $em->getReference($class, $parentId);
                
                // On "devine" le nom du setter (ex: 'pieceSinistre' -> 'setPieceSinistre')
                $setterMethod = 'set' . ucfirst($key);
                
                // Si le parent existe et que l'entité enfant a bien la méthode pour le lier...
                if ($parent && method_exists($childEntity, $setterMethod)) {
                    // On appelle la méthode pour faire la liaison (ex: $document->setPieceSinistre($parent))
                    $childEntity->$setterMethod($parent);
                    // On a trouvé le parent, notre travail est terminé, on arrête la boucle.
                    return;
                }
            }
        }
        
        // Optionnel mais recommandé : si on arrive ici, c'est qu'aucun parent n'a été trouvé.
        // On peut lever une exception pour un débogage plus facile.
        // throw new BadRequestHttpException('Aucun parent valide n\'a été fourni pour l\'entité enfant.');
    }
}