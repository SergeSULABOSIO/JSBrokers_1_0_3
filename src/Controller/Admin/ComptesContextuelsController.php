<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * LES BADGES DES ONGLETS CONTEXTUELS : une adresse unique pour toutes les rubriques.
 *
 * Chaque rubrique déclare ses collections dans SON contrôleur (getCollectionMap). Plutôt
 * qu'une route de plus dans chacun, celle-ci renvoie au contrôleur de la rubrique, qui
 * compte avec ses propres règles (ControllerUtilsTrait::comptesDesCollections) — gardes
 * comprises : cabinet ouvert, droits de lecture.
 *
 * Le nom de la rubrique vient de l'URL : il n'ouvre que ce qui existe à la fois comme
 * entité ET comme contrôleur doté de la méthode — rien d'autre n'est appelable.
 */
#[IsGranted('IS_AUTHENTICATED')]
class ComptesContextuelsController extends AbstractController
{
    #[Route('/admin/comptes-contextuels/{entite}/{id}', name: 'admin.comptes_contextuels',
        requirements: ['entite' => '[A-Za-z]{1,60}', 'id' => '\d+'], methods: ['GET'])]
    public function __invoke(string $entite, int $id): Response
    {
        $nom = ucfirst($entite);
        $entiteClasse = 'App\\Entity\\' . $nom;
        $controleur = __NAMESPACE__ . '\\' . $nom . 'Controller';
        if (!class_exists($entiteClasse) || !class_exists($controleur) || !method_exists($controleur, 'comptesDesCollections')) {
            throw $this->createNotFoundException();
        }

        return $this->forward($controleur . '::comptesDesCollections', [
            'parentEntityClass' => $entiteClasse,
            'id' => $id,
        ]);
    }
}
