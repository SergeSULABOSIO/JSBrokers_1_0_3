<?php
namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Symfony\Component\HttpFoundation\Request;

/**
 * @trait ControllerUtilsTrait
 * @description Fournit des méthodes utilitaires pour les contrôleurs, notamment pour la validation d'accès et la déduction de noms.
 */
trait ControllerUtilsTrait
{
    /**
     * Valide l'accès à un espace de travail en se basant sur idEntreprise et idInvite.
     *
     * Cette méthode vérifie que l'entreprise et l'invité existent et que l'invité
     * appartient bien à l'entreprise spécifiée. Elle retourne les entités validées.
     *
     * @param Request $request La requête HTTP actuelle.
     * @return array{entreprise: Entreprise, invite: Invite} Un tableau contenant les entités validées.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException Si l'entreprise n'est pas trouvée.
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException Si l'accès est refusé.
     */
    private function validateWorkspaceAccess(Request $request): array
    {
        $idEntreprise = $request->query->get('idEntreprise');
        $idInvite = $request->query->get('idInvite');

        if (!$idEntreprise) {
            $entreprise = $this->getEntreprise();
        } else {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
        }
        if (!$entreprise) {
            throw $this->createNotFoundException("L'entreprise n'a pas été trouvée pour générer le formulaire.");
        }

        if (!$idInvite) {
            $invite = $this->getInvite();
        } else {
            $invite = $this->inviteRepository->find($idInvite);
        }
        if (!$invite || $invite->getEntreprise()->getId() !== $entreprise->getId()) {
            throw $this->createAccessDeniedException("Vous n'avez pas les droits pour générer ce formulaire.");
        }

        return ['entreprise' => $entreprise, 'invite' => $invite];
    }

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
        if (!$user) {
            throw $this->createAccessDeniedException("Utilisateur non authentifié.");
        }
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        if (!$invite) {
            throw $this->createNotFoundException("Aucun invité trouvé pour l'utilisateur actuel.");
        }
        return $invite;
    }

    private function getEntityName(object|string $objectOrClass): string
    {
        $shortClassName = (new \ReflectionClass($objectOrClass))->getShortName();
        return str_replace('Controller', '', $shortClassName);
    }

    private function getServerRootName(object|string $objectOrClass): string
    {
        return strtolower($this->getEntityName($objectOrClass));
    }

    /**
     * Extrait les options d'un widget de collection depuis le canevas de formulaire.
     *
     * @param array $entityFormCanvas Le tableau de configuration du formulaire.
     * @param string $collectionFieldName Le 'field_code' du widget de collection à trouver.
     * @return array Les options trouvées, ou un tableau vide.
     */
    private function getCollectionOptionsFromCanvas(array $entityFormCanvas, string $collectionFieldName): array
    {
        foreach (($entityFormCanvas['form_layout'] ?? []) as $row) {
            foreach (($row['colonnes'] ?? []) as $col) {
                foreach (($col['champs'] ?? []) as $field) {
                    if (is_array($field) && ($field['widget'] ?? null) === 'collection' && ($field['field_code'] ?? null) === $collectionFieldName) {
                        return $field['options'] ?? [];
                    }
                }
            }
        }
        return [];
    }
}