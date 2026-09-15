<?php

namespace App\Repository;

use App\Entity\ErreurApplicative;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<ErreurApplicative>
 */
class ErreurApplicativeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, ErreurApplicative::class);
    }

    /**
     * Applique les filtres communs à un QueryBuilder. Réutilisé par la liste
     * paginée ET par les compteurs de tête, pour que les chiffres annoncés
     * décrivent EXACTEMENT ce que la liste montre — sinon on prend une décision
     * sur un total qui ne correspond à rien de visible.
     *
     * @param array{cote?:?string, branche?:?string, statut?:?string, q?:?string} $filtres
     */
    private function appliquerFiltres(QueryBuilder $qb, array $filtres): QueryBuilder
    {
        if (!empty($filtres['cote'])) {
            $qb->andWhere('e.cote = :cote')->setParameter('cote', $filtres['cote']);
        }
        if (!empty($filtres['branche'])) {
            $qb->andWhere('e.branche = :branche')->setParameter('branche', $filtres['branche']);
        }
        if (!empty($filtres['statut'])) {
            $qb->andWhere('e.statut = :statut')->setParameter('statut', $filtres['statut']);
        }
        if (!empty($filtres['q'])) {
            $qb->andWhere('e.message LIKE :q OR e.type LIKE :q OR e.fichier LIKE :q')
                ->setParameter('q', '%' . $filtres['q'] . '%');
        }

        return $qb;
    }

    /**
     * La liste, triée par dernière occurrence.
     *
     * Et non par nombre d'occurrences : une erreur massive mais ancienne, déjà
     * corrigée en réalité, occuperait indéfiniment la première ligne. Ce qui
     * vient de se produire passe devant ; l'ampleur se lit dans la colonne.
     *
     * @param array{cote?:?string, branche?:?string, statut?:?string, q?:?string} $filtres
     */
    public function paginateFiltered(array $filtres, int $page): PaginationInterface
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('e'), $filtres)
            ->orderBy('e.derniereOccurrenceAt', 'DESC');

        return $this->paginator->paginate($qb, $page, 20);
    }

    /**
     * Les compteurs de tête de page.
     *
     * @param array{cote?:?string, branche?:?string, statut?:?string, q?:?string} $filtres
     *
     * @return array{defauts:int, ouverts:int, occurrences:int, occurrences24h:int}
     */
    public function totaux(array $filtres): array
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('e'), $filtres)
            ->select('COUNT(e.id) AS defauts', 'COALESCE(SUM(e.nombreOccurrences), 0) AS occurrences');

        /** @var array{defauts:int|string, occurrences:int|string} $ligne */
        $ligne = $qb->getQuery()->getSingleResult();

        $ouverts = (int) $this->appliquerFiltres($this->createQueryBuilder('e'), $filtres)
            ->select('COUNT(e.id)')
            ->andWhere('e.statut IN (:ouverts)')
            ->setParameter('ouverts', [ErreurApplicative::STATUT_NOUVELLE, ErreurApplicative::STATUT_EN_COURS])
            ->getQuery()
            ->getSingleScalarResult();

        // Les défauts qui se sont manifestés dans les dernières 24 h : c'est ce
        // qui est VIVANT. Le reste est de l'histoire.
        $recents = (int) $this->appliquerFiltres($this->createQueryBuilder('e'), $filtres)
            ->select('COUNT(e.id)')
            ->andWhere('e.derniereOccurrenceAt >= :depuis')
            ->setParameter('depuis', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'defauts' => (int) $ligne['defauts'],
            'ouverts' => $ouverts,
            'occurrences' => (int) $ligne['occurrences'],
            'occurrences24h' => $recents,
        ];
    }

    /**
     * Les défauts ouverts les plus récents — pour le bloc du tableau de bord,
     * qui doit tenir en quelques lignes et montrer ce qui appelle une action.
     *
     * @return list<ErreurApplicative>
     */
    public function ouvertsRecents(int $limite = 5): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.statut IN (:ouverts)')
            ->setParameter('ouverts', [ErreurApplicative::STATUT_NOUVELLE, ErreurApplicative::STATUT_EN_COURS])
            ->orderBy('e.derniereOccurrenceAt', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les défauts tranchés (résolus ou ignorés) qui ne se sont plus
     * manifestés depuis un certain temps.
     *
     * Ce qui est OUVERT n'est jamais purgé, quel que soit son âge : une erreur
     * que personne n'a regardée depuis un an reste une erreur que personne n'a
     * regardée. La faire disparaître ne la corrigerait pas, cela effacerait
     * seulement la preuve qu'elle existe.
     *
     * @return int le nombre de lignes supprimées
     */
    public function purgerTranchesAvant(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.statut IN (:tranches)')
            ->andWhere('e.derniereOccurrenceAt < :limite')
            ->setParameter('tranches', [ErreurApplicative::STATUT_RESOLUE, ErreurApplicative::STATUT_IGNOREE])
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }

    /** Ce que la purge SUPPRIMERAIT, sans rien supprimer. */
    public function compterPurgeables(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.statut IN (:tranches)')
            ->andWhere('e.derniereOccurrenceAt < :limite')
            ->setParameter('tranches', [ErreurApplicative::STATUT_RESOLUE, ErreurApplicative::STATUT_IGNOREE])
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
