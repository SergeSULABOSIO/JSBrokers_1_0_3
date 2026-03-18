<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Article;
use App\Services\Canvas\Provider\Numeric\CalculatedIndicatorsNumericProviderTrait;

class ArticleNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Article $object */
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}