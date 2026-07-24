<?php

namespace App\Twig\Extension;

use App\Util\Pourcentage;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Unifie l'AFFICHAGE des pourcentages dans les templates via le VO Pourcentage
 * (source unique, cf. App\Util\Pourcentage) et le formate selon la LANGUE ACTIVE
 * (LocaleSwitcher : locale de l'utilisateur, posée par UserLocaleListener, défaut
 * « fr »). Fini les « number_format(..., '.', ' ') » vs « , » codés en dur qui
 * divergeaient entre console et workspace : le séparateur décimal, le séparateur
 * de milliers et le signe % suivent désormais la locale (fr → « 16,00 % »,
 * en → « 16.00% »).
 *
 * Usage :
 *   {{ 16|pourcentage }}               => valeur DÉJÀ en pour-cent (16 → « 16,00 % » en fr)
 *   {{ 0.16|pourcentage('fraction') }} => valeur en fraction (0.16 → « 16,00 % » en fr)
 *   {{ taux|pourcentage('pourcent', 0) }} => sans décimale
 */
class PourcentageExtension extends AbstractExtension
{
    public function __construct(private readonly LocaleSwitcher $localeSwitcher)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('pourcentage', [$this, 'pourcentage']),
        ];
    }

    /**
     * @param int|float|string|null $valeur Nombre en pour-cent (défaut) ou fraction (selon $depuis).
     * @param string                $depuis 'pourcent' (défaut) ou 'fraction'.
     * @param int                   $scale  Nombre de décimales.
     * @param string|null           $locale Force une locale ; sinon la langue active.
     */
    public function pourcentage(int|float|string|null $valeur, string $depuis = 'pourcent', int $scale = 2, ?string $locale = null): string
    {
        $p = $depuis === 'fraction'
            ? Pourcentage::fromFraction((float) $valeur)
            : Pourcentage::fromPourcent($valeur);

        $locale ??= $this->localeSwitcher->getLocale() ?: 'fr';

        // Style PERCENT : séparateurs ET signe % positionnés selon la locale
        // (le NumberFormatter attend une FRACTION, d'où fraction()).
        if (class_exists(\NumberFormatter::class)) {
            $fmt = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $scale);
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $scale);
            $rendu = $fmt->format($p->fraction());
            if ($rendu !== false) {
                return $rendu;
            }
        }

        // Repli sans intl : format FR par défaut du VO.
        return $p->format($scale);
    }
}
