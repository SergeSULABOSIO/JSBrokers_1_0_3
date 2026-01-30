<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/icon", name: 'icon.')]
class IconController extends AbstractController
{
    public function __construct(private IconCanvasProvider $iconCanvasProvider)
    {
    }

    #[Route('/api/get-icon', name: 'api_get_icon', methods: ['GET'])]
    public function getIcon(Request $request): Response
    {
        $alias = $request->query->get('name');
        $size = $request->query->get('size', 24);

        // Sécurité : si le nom de l'icône est manquant, on retourne une erreur.
        if (!$alias) {
            return new Response('<!-- Icon name missing -->', Response::HTTP_BAD_REQUEST);
        }

        // Résout l'alias (ex: 'assureur') en son vrai nom (ex: 'wpf:security-checked')
        $realIconName = $this->iconCanvasProvider->resolveIconName($alias);

        // Si aucun alias n'a été trouvé, on suppose que le nom fourni est déjà le vrai nom.
        // Cela permet de gérer à la fois les alias ('action:save') et les noms directs ('bi:trash').
        if (!$realIconName) {
            $realIconName = $alias;
        }

        // On rend un template générique qui utilise ux_icon avec le nom résolu.
        return $this->render('segments/icones/_generic_icon.html.twig', [
            'icon_name' => $realIconName, 
            'size' => (int)$size . 'px'
        ]);
    }
}