<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Article;

class ArticleNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Article $object */

        return [
            'montantArticle' => [
                'description' => 'Montant de l\'article',
                'value' => ($object->montantArticle ?? 0) * 100, // x100 selon ta convention
            ],
            'pourcentageNote' => [
                'description' => 'Poids dans la Note',
                'value' => ($object->pourcentageNote ?? 0),
            ]
        ];
    }
}