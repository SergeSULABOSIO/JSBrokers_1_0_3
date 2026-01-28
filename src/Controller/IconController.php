<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class IconController extends AbstractController
{
    public function __construct(private IconCanvasProvider $iconCanvasProvider)
    {
    }

    #[Route('/api/icon/{name}/{size}', name: 'api_get_icon', methods: ['GET'])]
    public function getIcon(string $name, int $size = 24): Response
    {
        $templatePath = $this->iconCanvasProvider->getTemplateForIcon($name);

        if (!$templatePath) {
            return new Response('<!-- Icon not found -->', Response::HTTP_NOT_FOUND);
        }

        return $this->render($templatePath, ['size' => $size . 'px']);
    }
}