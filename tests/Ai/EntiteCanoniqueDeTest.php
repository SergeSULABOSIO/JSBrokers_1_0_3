<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\EntiteCanonique;
use App\Ai\Tool\EntiteLexique;
use App\Repository\InviteRepository;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Fabrique d'EntiteCanonique pour les tests unitaires.
 *
 * Même parti pris que {@see ResolveurDeTest} : le service et son lexique sont
 * `final`, on ne les double pas — on les CONSTRUIT. C'est d'ailleurs ce qu'on
 * veut : la table « libellé de l'écran => nom court » est DÉRIVÉE de la carte de
 * permissions (constantes pures), si bien qu'un test qui la construit vraiment
 * exerce la table RÉELLE, celle qui servira en production. Seul le dépôt d'invités
 * est simulé : libellesEntites() ne le touche jamais.
 */
trait EntiteCanoniqueDeTest
{
    private function entiteCanonique(): EntiteCanonique
    {
        return new EntiteCanonique(new EntiteLexique(
            new WorkspaceAccessResolver($this->createMock(InviteRepository::class)),
        ));
    }
}
