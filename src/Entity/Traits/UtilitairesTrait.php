<?php

namespace App\Entity\Traits;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Entreprise;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

trait UtilitairesTrait
{
    private function getEntreprise(): Entreprise
    {
        /** @var Invite $invite */
        $invite = $this->getInvite();
        return $invite->getEntreprise();
    }

    private function getInvite(): Invite
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        return $invite;
    }
    
    /**
     * Déduit le nom de l'entité à partir du nom du contrôleur.
     * Exemple: PieceSinistreController -> PieceSinistre
     * @return string
     */
    private function getEntityName($objectOrClass): string
    {
        $shortClassName = (new \ReflectionClass($objectOrClass))->getShortName();
        return str_replace('Controller', '', $shortClassName);
    }

    /**
     * Déduit le nom racine du serveur à partir du nom du contrôleur.
     * Exemple: PieceSinistreController -> piecesinistre
     * @return string
     */
    private function getServerRootName($className): string
    {
        return strtolower($this->getEntityName($className));
    }
}