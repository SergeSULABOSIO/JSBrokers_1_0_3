<?php

namespace App\Twig\Extension;

use App\Service\Terminal\DetecteurDeTerminal;
use App\Service\Terminal\TerminalContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @file Expose au gabarit le terminal de la requête.
 * @description Même source que le contrôleur (`TerminalContext`) : le gabarit ne
 * peut donc pas annoncer une disposition que le serveur n'a pas servie — c'est
 * le rôle que joue `ConsoleAccessExtension` pour les droits de la Console.
 *
 * `terminal_cookie()` est là pour la bascule et pour la sonde : le nom du
 * cookie ne doit pas être recopié à la main dans un gabarit.
 */
class TerminalExtension extends AbstractExtension
{
    public function __construct(
        private TerminalContext $terminal,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('terminal_courant', [$this, 'courant']),
            new TwigFunction('terminal_fige', [$this, 'estFige']),
            new TwigFunction('terminal_cookie', [$this, 'nomDuCookie']),
            new TwigFunction('terminal_param', [$this, 'nomDuParametre']),
            new TwigFunction('terminal_duree_cookie', [$this, 'dureeDuCookie']),
            new TwigFunction('terminal_appareil', [$this, 'appareil']),
        ];
    }

    public function courant(): string
    {
        return $this->terminal->courant()->value;
    }

    public function estFige(): bool
    {
        return $this->terminal->estFige();
    }

    public function nomDuCookie(): string
    {
        return DetecteurDeTerminal::COOKIE;
    }

    public function nomDuParametre(): string
    {
        return DetecteurDeTerminal::PARAM;
    }

    public function dureeDuCookie(): int
    {
        return DetecteurDeTerminal::DUREE_COOKIE;
    }

    /**
     * L'appareil réel, abstraction faite du mode demandé — pour proposer le
     * retour au mode conversation là où il a un sens, et nulle part ailleurs.
     */
    public function appareil(): string
    {
        return $this->terminal->appareil()->value;
    }
}
