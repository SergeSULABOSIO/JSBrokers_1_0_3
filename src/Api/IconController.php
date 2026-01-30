<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/api')]
class IconController extends AbstractController
{
    public function __construct(private IconCanvasProvider $iconCanvasProvider)
    {
    }

    #[Route('/icon/{name}/{size}', name: 'api_get_icon', methods: ['GET'])]
    public function getIcon(string $name, int $size = 24): Response
    {
        $alias = $name;
        // On ne reconstruit l'alias que pour les actions, qui sont les seules à utiliser le format 'prefix:value'.
        // Par exemple, 'action-save' devient 'action:save'.
        // Les autres alias comme 'client' ou 'compte-bancaire' ne sont pas affectés.
        if (str_starts_with($name, 'action-')) {
            $alias = str_replace('-', ':', $name);
        }

        // Résout l'alias (ex: 'assureur') en son vrai nom (ex: 'wpf:security-checked')
        $realIconName = $this->iconCanvasProvider->resolveIconName($alias);

        // Si aucun alias n'a été trouvé, on suppose que le nom fourni est déjà le vrai nom.
        if (!$realIconName) {
            $realIconName = $name; // On utilise le nom d'origine (ex: 'compte-bancaire')
        }

        // On rend un template générique qui utilise ux_icon avec le nom résolu.
        return $this->render('segments/icones/_generic_icon.html.twig', [
            'icon_name' => $realIconName, 
            'size' => $size . 'px'
        ]);
    }
}