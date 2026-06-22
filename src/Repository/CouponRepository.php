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
}
