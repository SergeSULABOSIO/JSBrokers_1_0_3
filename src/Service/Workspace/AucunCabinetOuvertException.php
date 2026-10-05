<?php

namespace App\Service\Workspace;

/**
 * AUCUN CABINET N'EST OUVERT POUR CET UTILISATEUR.
 *
 * Levée par {@see \App\Controller\Admin\ControllerUtilsTrait::getInvite()} quand le
 * gardien {@see CabinetActif} ne trouve plus d'`Invite` pour le couple (utilisateur
 * courant, cabinet ouvert) — typiquement après une révocation, puisque révoquer
 * consiste à supprimer la ligne.
 *
 * Ce n'est PAS une erreur d'accès : l'utilisateur n'a rien tenté d'interdit, son droit
 * a simplement disparu pendant qu'il travaillait. D'où une redirection vers le choix
 * d'espace plutôt qu'un 403, qui lui laisserait croire à une faute de sa part.
 *
 * Elle existe comme type distinct pour une raison précise : c'est le type, et non le
 * message, que {@see CabinetFermeListener} reconnaît. Un `AccessDeniedException` eût
 * été confondu avec les refus de périmètre, qui, eux, doivent rester des 403.
 */
class AucunCabinetOuvertException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Aucun espace de travail n'est ouvert : votre accès à ce cabinet n'existe plus.",
        );
    }
}
