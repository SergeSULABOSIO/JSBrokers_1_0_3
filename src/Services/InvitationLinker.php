<?php

namespace App\Services;

use App\Entity\Utilisateur;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rattache les invitations « en attente » (créées avant que la personne n'ait un compte)
 * au compte utilisateur correspondant, dès que celui-ci existe.
 *
 * Une invitation en attente est identifiée par son email (`Invite::email`) et l'absence
 * d'utilisateur lié (`utilisateur IS NULL`). Une fois rattachée, l'email stocké est vidé :
 * la source de vérité devient le compte utilisateur.
 *
 * Conséquence directe : dès sa première connexion, la personne voit dans sa liste
 * d'entreprises toutes celles auxquelles elle avait été invitée.
 */
class InvitationLinker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InviteRepository $inviteRepository,
    ) {}

    /**
     * Rattache à l'utilisateur toutes les invitations en attente portant son email.
     *
     * @return int Le nombre d'invitations rattachées.
     */
    public function linkPendingInvitations(Utilisateur $user): int
    {
        $email = $user->getEmail();
        if (!$email) {
            return 0;
        }

        $pending = $this->inviteRepository->findBy([
            'email' => $email,
            'utilisateur' => null,
        ]);

        if (!$pending) {
            return 0;
        }

        foreach ($pending as $invite) {
            $invite->setUtilisateur($user);
            $invite->setEmail(null);
        }
        $this->em->flush();

        return count($pending);
    }
}
