<?php

namespace App\Twig\Extension;

use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @file Expose au gabarit les tests d'accès de l'espace de travail.
 * @description `workspace_can_read('Client')`, `workspace_can('Note', 1)` et
 * `workspace_can_manage_invites()` — utilisés pour masquer, dans la navigation et le
 * tableau de bord, ce qui est hors du périmètre de l'invité connecté. S'appuient sur
 * le même WorkspaceAccessResolver que le blocage serveur (DRY) : l'UI et le contrôle
 * d'accès ne peuvent pas diverger. Le masquage est un confort d'affichage ; la
 * sécurité reste assurée côté serveur.
 */
class WorkspaceAccessExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly WorkspaceAccessResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('workspace_can', [$this, 'can']),
            new TwigFunction('workspace_can_read', [$this, 'canRead']),
            new TwigFunction('workspace_can_manage_invites', [$this, 'canManageInvites']),
        ];
    }

    public function can(string $entityShortName, int $level): bool
    {
        $invite = $this->currentInvite();

        return $invite !== null && $this->resolver->can($invite, $entityShortName, $level);
    }

    public function canRead(string $entityShortName): bool
    {
        return $this->can($entityShortName, Invite::ACCESS_LECTURE);
    }

    public function canManageInvites(): bool
    {
        $invite = $this->currentInvite();

        return $invite !== null && $this->resolver->canManageInvites($invite);
    }

    private function currentInvite(): ?Invite
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return null;
        }

        return $this->resolver->resolveConnectedInvite($user);
    }
}
