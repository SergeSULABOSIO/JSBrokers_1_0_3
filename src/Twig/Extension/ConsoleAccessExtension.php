<?php

namespace App\Twig\Extension;

use App\Entity\Utilisateur;
use App\Service\Console\ConsoleAccessResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @file Expose au gabarit le test d'accès aux rubriques de la Console.
 * @description `console_can_access('console.xxx.index')` indique si le collaborateur
 * courant peut atteindre cette route — utilisé pour masquer dans la navigation les
 * rubriques hors de son département. S'appuie sur le même ConsoleAccessResolver que
 * le blocage serveur (DRY) : nav et contrôle d'accès ne peuvent pas diverger.
 */
class ConsoleAccessExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        private ConsoleAccessResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('console_can_access', [$this, 'canAccess']),
        ];
    }

    public function canAccess(string $routeName): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return false;
        }

        return $this->resolver->canAccessRoute($user, $routeName);
    }
}
