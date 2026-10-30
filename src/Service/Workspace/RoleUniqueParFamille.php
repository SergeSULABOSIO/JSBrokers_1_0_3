<?php

namespace App\Service\Workspace;

use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN SEUL RÔLE PAR FAMILLE ET PAR INVITÉ (Serge, 2026-10-30).
 *
 * Les droits d'un invité sont l'UNION de ses rôles d'une famille
 * (WorkspaceAccessResolver::allowedLevels) : un second rôle Finance rendait sans effet le
 * retrait d'un droit dans le premier, sans que personne ne le voie. Le second est donc refusé,
 * avec ce qu'il faut faire à la place ; le « + » de l'onglet de la fiche invité cède la place
 * à la même phrase (`ajoutImpossible`).
 */
final class RoleUniqueParFamille
{
    /** @var array<class-string, array{famille: string, collection: string}> */
    public const FAMILLES = [
        RolesEnFinance::class => ['famille' => 'Finance', 'collection' => 'rolesEnFinance'],
        RolesEnMarketing::class => ['famille' => 'Marketing', 'collection' => 'rolesEnMarketing'],
        RolesEnProduction::class => ['famille' => 'Production', 'collection' => 'rolesEnProduction'],
        RolesEnSinistre::class => ['famille' => 'Sinistre', 'collection' => 'rolesEnSinistre'],
        RolesEnAdministration::class => ['famille' => 'Administration', 'collection' => 'rolesEnAdministration'],
    ];

    /** Le refus d'un rôle qui doublerait celui de son invité ; null s'il est le seul de sa famille. */
    public static function refus(EntityManagerInterface $em, object $role): ?string
    {
        $famille = self::FAMILLES[$em->getClassMetadata($role::class)->getName()] ?? null;
        $invite = $famille !== null ? $role->getInvite() : null;
        if ($invite === null || $invite->getId() === null) {
            return null;
        }
        $requete = $em->createQueryBuilder()->select('COUNT(r.id)')->from($role::class, 'r')
            ->where('r.invite = :invite')->setParameter('invite', $invite);
        if ($role->getId() !== null) {
            $requete->andWhere('r.id <> :id')->setParameter('id', $role->getId());
        }

        return (int) $requete->getQuery()->getSingleScalarResult() > 0 ? self::phrase($famille['famille']) : null;
    }

    /** Le motif qui remplace le « + » d'une collection de rôles déjà pourvue (fiche invité). */
    public static function motifSiDejaPose(Invite $invite, string $collection): ?string
    {
        foreach (self::FAMILLES as $famille) {
            if ($famille['collection'] === $collection) {
                return $invite->{'get' . ucfirst($collection)}()->isEmpty() ? null : self::phrase($famille['famille']);
            }
        }

        return null;
    }

    private static function phrase(string $famille): string
    {
        return "Cet invité a déjà un rôle $famille : modifiez-le plutôt que d'en créer un second.";
    }
}
