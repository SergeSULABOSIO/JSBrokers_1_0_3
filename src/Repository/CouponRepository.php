<?php

namespace App\Repository;

use App\Entity\Coupon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Coupon>
 */
class CouponRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, Coupon::class);
    }

    /** Retrouve un coupon par son code (normalisé en majuscules). */
    public function findOneByCode(string $code): ?Coupon
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /** Liste paginée de tous les coupons, plus récents d'abord. */
    public function paginateAll(int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('c')->orderBy('c.id', 'DESC'),
            $page,
            20,
        );
    }

    /**
     * Coupons mis en avant sur la vitrine publique et utilisables maintenant :
     * actifs, visibles, dans leur période de validité et sous leur limite d'usage.
     * Triés par valeur de remise décroissante (la plus avantageuse d'abord).
     *
     * @return Coupon[]
     */
    public function findVisiblesPourVitrine(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.actif = true')
            ->andWhere('c.visiblePublic = true')
            ->andWhere('c.dateDebut <= :now')
            ->andWhere('c.dateFin >= :now')
            ->andWhere('c.usageLimit IS NULL OR c.usageCount < c.usageLimit')
            ->setParameter('now', $now)
            ->orderBy('c.valeur', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
