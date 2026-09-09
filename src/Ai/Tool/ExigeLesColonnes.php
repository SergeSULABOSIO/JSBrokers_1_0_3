<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;

/**
 * @file Un outil qui n'a de sens que devant une interface à colonnes.
 * @description Trois outils de Ket ne produisent pas une réponse mais un
 * MOUVEMENT DE L'ÉCRAN : ouvrir la rubrique d'une entité, fermer un onglet,
 * afficher une fiche dans la colonne de visualisation. Tous trois visent les
 * colonnes 2, 3 et 4 de l'espace de travail.
 *
 * Sur téléphone et tablette, ces colonnes n'existent pas : la surface servie est
 * la conversation, en plein écran (cf. App\Service\Terminal\Terminal::modeKet()).
 *
 * ── POURQUOI RETIRER L'OUTIL, ET PAS SEULEMENT IGNORER SON EFFET ───────────
 * Laisser l'outil déclaré et neutraliser son effet côté navigateur produirait le
 * pire des deux mondes : Ket annoncerait « j'ouvre la liste de vos clients », et
 * rien ne se passerait. L'utilisateur attendrait un écran qui n'arrive jamais,
 * en concluant que l'application est cassée. En ne DÉCLARANT pas l'outil, le
 * modèle ne peut pas le promettre : il répond avec ce qu'il a — le tableau, le
 * chiffre, la liste écrite dans le fil.
 *
 * Le navigateur garde tout de même son refus explicite (le chat affiche un
 * message nommant la cause) : c'est le filet, pour la version de la page restée
 * ouverte pendant un déploiement.
 *
 * Effet de bord bienvenu : trois déclarations d'outil en moins à CHAQUE tour de
 * function calling, donc autant de tokens d'entrée épargnés sur le quota par
 * minute (cf. AiToolConditionnel, qui existe précisément pour cette raison).
 *
 * ⚠ CE N'EST PAS UNE GARDE DE SÉCURITÉ. Le terminal est déduit d'un `User-Agent`
 * et d'un cookie, tous deux à la main du client. Les gardes de périmètre restent
 * où elles sont, dans `execute()`.
 */
trait ExigeLesColonnes
{
    public function estDisponible(AiScope $scope): bool
    {
        return !$scope->terminal->modeKet();
    }
}
