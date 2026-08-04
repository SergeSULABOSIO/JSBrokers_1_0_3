<?php

namespace App\Service\Workspace;

/**
 * LES LIENS QU'UNE SUPPRESSION NE DOIT JAMAIS REMONTER.
 *
 * Doctrine ne connaît qu'une direction : une cascade `remove` s'applique quel que
 * soit le sens métier de la relation. Or certaines relations to-one RATTACHENT un
 * enfant à un parent bien vivant, et les suivre détruirait ce parent.
 *
 * Le cas fondateur — et pour l'instant le seul — est `Piste::avenantDeBase`. Une
 * opportunité dérivée (renouvellement, prorogation…) pointe la POLICE qu'elle fait
 * évoluer, en `OneToOne(cascade: ['persist','remove'])`. Supprimer l'opportunité
 * emporterait donc la police elle-même, ses propositions, ses échéanciers et ses
 * paiements : exactement l'inverse de l'intention, qui est d'ABANDONNER le projet
 * de renouvellement et de GARDER la police.
 *
 * `AvenantController::deletePisteDerivee` dissociait déjà les deux sens à la main
 * avant de supprimer. Cette connaissance vivait dans ce seul contrôleur : tout
 * autre chemin de suppression — au premier rang desquels les plans d'écriture de
 * l'assistant, qui suppriment par `MutationOperation` générique — retombait dans
 * le piège. La règle est donc énoncée ICI, une fois, et appliquée par le moteur de
 * mutation ET par l'analyse d'impact (qui doit annoncer la portée RÉELLE, pas la
 * portée théorique : promettre la destruction d'une police qui survivra serait un
 * mensonge aussi grave que l'inverse).
 */
final class LiensProteges
{
    /**
     * Nom court d'entité => champs to-one dissociés AVANT la suppression, avec le
     * champ réciproque à neutraliser sur la cible (les deux sens du lien sont
     * indépendants : n'en couper qu'un laisse l'autre entretenir la cascade).
     *
     * @var array<string, array<string, ?string>> entité => [champ => champ réciproque|null]
     */
    private const AVANT_SUPPRESSION = [
        'Piste' => ['avenantDeBase' => 'pisteDeRenouvellement'],
    ];

    /**
     * Champs protégés d'une entité, ou tableau vide si elle n'en a aucun.
     *
     * @return array<string, ?string>
     */
    public static function champs(object $entity): array
    {
        $court = substr(strrchr('\\' . $entity::class, '\\') ?: '', 1);

        return self::AVANT_SUPPRESSION[$court] ?? [];
    }

    /**
     * Coupe les liens protégés de l'entité, DANS LES DEUX SENS, juste avant sa
     * suppression. Sans effet — et sans erreur — sur une entité qui n'en porte pas.
     *
     * @return string[] noms des champs effectivement dissociés (pour le journal)
     */
    public static function dissocier(object $entity): array
    {
        $coupes = [];

        foreach (self::champs($entity) as $champ => $reciproque) {
            $getter = 'get' . ucfirst($champ);
            if (!method_exists($entity, $getter)) {
                continue;
            }
            $cible = $entity->{$getter}();
            if ($cible === null) {
                continue;
            }

            // Sens retour d'abord : c'est lui qui porte la clé étrangère côté cible.
            if ($reciproque !== null) {
                $setterCible = 'set' . ucfirst($reciproque);
                if (method_exists($cible, $setterCible)) {
                    $cible->{$setterCible}(null);
                }
            }

            $setter = 'set' . ucfirst($champ);
            if (method_exists($entity, $setter)) {
                $entity->{$setter}(null);
                $coupes[] = $champ;
            }
        }

        return $coupes;
    }
}
