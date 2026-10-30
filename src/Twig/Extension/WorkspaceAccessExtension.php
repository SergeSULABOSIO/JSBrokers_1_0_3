<?php

namespace App\Twig\Extension;

use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\CabinetActif;
use App\Service\Workspace\DecisionDeCreation;
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
        private readonly DecisionDeCreation $decisionDeCreation,
        // Le cabinet OUVERT, celui que juge le serveur (mémorisé le temps de la requête).
        private readonly CabinetActif $cabinetActif,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('workspace_can', [$this, 'can']),
            new TwigFunction('workspace_can_read', [$this, 'canRead']),
            new TwigFunction('workspace_can_manage_invites', [$this, 'canManageInvites']),
            new TwigFunction('workspace_est_administrateur', [$this, 'estAdministrateur']),
            new TwigFunction('creation_offerte', [$this, 'creationOfferte']),
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

    /**
     * L'invité administre-t-il TOUT le cabinet (propriétaire ou délégué) ? Lu par la ligne des
     * filtres actifs : « Tout retirer » n'y retire « Mon portefeuille » que pour lui — la règle
     * du chantier 6 (CauseDeListeVide), la même que celle de l'état vide.
     */
    public function estAdministrateur(): bool
    {
        $invite = $this->currentInvite();

        return $invite !== null && $this->resolver->estAdministrateurDuCabinet($invite);
    }

    /**
     * « Peut-on créer cette fiche d'ici ? » — LA décision unique (DecisionDeCreation), pour les
     * boutons de création rendus hors d'une liste : tableau de bord, relevé de compte, analyse
     * d'un bordereau. Un bouton qui mènerait à un refus du serveur n'est pas rendu.
     *
     * @param string      $entite      nom court de l'entité (« Piste », « Feedback »)
     * @param string|null $champParent le champ par lequel le bouton rattache la fiche à son
     *                                 parent (« tache » pour un feedback), null sinon
     */
    public function creationOfferte(string $entite, ?string $champParent = null): bool
    {
        $entreprise = $this->cabinetActif->entreprise();

        return $entreprise !== null
            && $this->decisionDeCreation->pour('App\\Entity\\' . $entite, $entreprise, $champParent)['offert'];
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
