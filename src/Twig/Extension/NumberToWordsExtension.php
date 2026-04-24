<?php

namespace App\Twig\Extension;

use NumberToWords\NumberToWords;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NumberToWordsExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('to_words', [$this, 'convertToWords']),
        ];
    }

    /**
     * Convertit un nombre en sa représentation textuelle en français.
     *
     * @param float|int $number Le nombre à convertir.
     * @param string $currency Le code de la devise (ex: 'USD', 'EUR').
     * @return string
     */
    public function convertToWords($number, string $currency): string
    {
        $numberToWords = new NumberToWords();
        $converter = $numberToWords->getCurrencyTransformer('fr');
        return ucfirst($converter->toWords(intval($number * 100), $currency));
    }
}