<?php

namespace App\Repository;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Entreprise;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Avenant>
 */
class AvenantRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Avenant::class);
    }

    /**
     * LES RÉFÉRENCES DE POLICE DU CABINET, UNE SEULE FOIS CHACUNE.
     *
     * Une police n'est pas une entité : c'est un GROUPE d'avenants qui partagent la même
     * `referencePolice`. En lister une par avenant proposerait trois fois « POL-2026-14 »
     * à qui n'a qu'une police à désigner — d'où le DISTINCT.
     *
     * Le libellé accompagne la référence : seule, elle ne dit pas de quel dossier il
     * s'agit. On remonte donc au client et au risque par le chemin
     * `avenant → cotation → piste`, en jointure GAUCHE : un avenant dont la chaîne est
     * incomplète doit rester proposable, pas disparaître.
     *
     * @param Client|null $assure quand il est connu, on ne propose QUE ses polices
     * @return array<int, array{reference: string, client: ?string, risque: ?string, assureur: ?string}>
     */
    public function referencesDePolice(Entreprise $entreprise, ?Client $assure = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.referencePolice AS reference', 'c.nom AS client', 'r.code AS risque', 'ass.nom AS assureur')
            ->leftJoin('a.cotation', 'cot')
            ->leftJoin('cot.assureur', 'ass')
            ->leftJoin('cot.piste', 'p')
            ->leftJoin('p.client', 'c')
            ->leftJoin('p.risque', 'r')
            ->where('a.entreprise = :entreprise')
            ->andWhere('a.referencePolice IS NOT NULL')
            ->andWhere("TRIM(a.referencePolice) <> ''")
            ->setParameter('entreprise', $entreprise)
            ->orderBy('a.referencePolice', 'ASC');

        if ($assure !== null) {
            $qb->andWhere('c = :assure')->setParameter('assure', $assure);
        }

        $lignes = $qb->getQuery()->getArrayResult();

        // Le DISTINCT porte sur les quatre colonnes : deux avenants d'une même police dont
        // l'un n'a pas de cotation rendraient deux lignes. On ne garde que la première
        // occurrence de chaque référence — celle qui porte le plus de contexte vient en
        // tête, les colonnes nulles se triant après.
        $parReference = [];
        foreach ($lignes as $ligne) {
            $cle = (string) $ligne['reference'];
            if (!isset($parReference[$cle]) || ($parReference[$cle]['client'] === null && $ligne['client'] !== null)) {
                $parReference[$cle] = $ligne;
            }
        }

        return array_values($parReference);
    }

    //    /**
    //     * @return Avenant[] Returns an array of Avenant objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Avenant
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('avenant')
                ->leftJoin("avenant.cotation", "cotation")
                ->leftJoin("cotation.piste", "piste")
                ->leftJoin("piste.invite", "invite")
                ->where('invite.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', ''.$idEntreprise.'')
                ->orderBy('avenant.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('a')
                ->leftJoin("a.cotation", "c")
                ->leftJoin("c.piste", "p")
                ->where('p.invite = :inviteId')
                ->setParameter('inviteId', ''.$idInvite.'')
                ->orderBy('a.id', 'DESC'),
            $page,
            20,
        );
    }
}
