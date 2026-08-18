<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Entity\Risque;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Piste>
 */
class PisteRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Piste::class);
    }

    //    /**
    //     * @return Piste[] Returns an array of Piste objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Piste
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("p")
                ->where("p.invite = :inviteId")
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('p.id', 'DESC'),
            $page,
            20,
        );
    }

    /**
     * Retourne la piste d'exercice antérieur la plus récente pour un même couple
     * client + risque au sein d'une entreprise. Sert à reconduire le partage
     * partenaire lors de la création d'une piste d'exercice via import bordereau.
     */
    public function findLatestPrevious(Client $client, ?Risque $risque, Entreprise $entreprise, int $exercice): ?Piste
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.client = :client')
            ->andWhere('p.entreprise = :entreprise')
            ->andWhere('p.exercice < :exercice')
            ->setParameter('client', $client)
            ->setParameter('entreprise', $entreprise)
            ->setParameter('exercice', $exercice)
            ->orderBy('p.exercice', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1);

        if ($risque !== null) {
            $qb->andWhere('p.risque = :risque')->setParameter('risque', $risque);
        } else {
            $qb->andWhere('p.risque IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("p")
                ->leftJoin("p.invite", "i")
                ->where("i.entreprise = :entrepriseId")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('p.id', 'DESC'),
            $page,
            20,
        );
    }

    /**
     * Ce client figurait-il au portefeuille de la SOCIETE sur cet exercice ?
     *
     * « Au portefeuille » = au moins une affaire SOUSCRITE (portant un avenant). Une
     * proposition restee sans suite n'a jamais mis le client au portefeuille — la
     * compter reviendrait a refuser une retrocommission pour un devis perdu.
     *
     * Perimetre = toute l'entreprise, pas seulement l'agent ni le gestionnaire : une
     * ligne qu'un collegue couvrait deja n'est pas neuve.
     */
    public function clientCouvertEnExercice(Entreprise $entreprise, Client $client, int $exercice): bool
    {
        return $this->existePisteSouscrite($entreprise, $client, null, $exercice);
    }

    /** Ce couple client x risque etait-il couvert par la societe sur cet exercice ? */
    public function ligneCouverteEnExercice(Entreprise $entreprise, Client $client, Risque $risque, int $exercice): bool
    {
        return $this->existePisteSouscrite($entreprise, $client, $risque, $exercice);
    }

    /**
     * Socle unique des deux questions ci-dessus : EXISTS sur les avenants, jamais NOT IN
     * (qui se comporte mal des qu'un identifiant est nul), et SELECT 1 avec une limite —
     * on cherche une preuve d'existence, pas un decompte.
     */
    private function existePisteSouscrite(Entreprise $entreprise, Client $client, ?Risque $risque, int $exercice): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('1')
            ->join('p.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->andWhere('p.client = :client')
            ->andWhere('p.exercice = :exercice')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Cotation c_e
                                JOIN c_e.avenants a_e
                                WHERE c_e.piste = p)')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('client', $client)
            ->setParameter('exercice', $exercice)
            ->setMaxResults(1);

        if ($risque !== null) {
            $qb->andWhere('p.risque = :risque')->setParameter('risque', $risque);
        }

        return $qb->getQuery()->getResult() !== [];
    }
}
