<?php

namespace App\Repository;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Risque;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Doctrine\Common\Collections\ArrayCollection;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Cotation>
 */
class CotationRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security,
    ) {
        parent::__construct($registry, Cotation::class);
    }

    //    /**
    //     * @return Cotation[] Returns an array of Cotation objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Cotation
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function loadBoundCotationsWithPartnerRisque($annee, ?Entreprise $entreprise, ?Risque $risque, ?Partenaire $partenaire)
    {
        $data = $this->createQueryBuilder('cotation')
            ->leftJoin("cotation.piste", "piste")
            ->leftJoin("piste.invite", "invite")
            // ->leftJoin("piste.risque", "risque")
            ->Where('invite.entreprise = :ese')
            ->andWhere('piste.risque = :risque')
            ->setParameter('ese', $entreprise->getId())
            ->setParameter('risque', $risque->getId())
            ->orderBy('cotation.id', 'ASC')
            // ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $resultat = new ArrayCollection([]);
        foreach ($data as $cotation) {
            if (count($cotation->getAvenants()) != 0 && $this->getPartenaire($cotation) == $partenaire) {
                if ($this->isSameAnnee($annee, $cotation)) {
                    $resultat->add($cotation);
                }
            }
        }
        return $resultat;
    }

    public function loadBoundCotationsWithPartnerClient($annee, ?Entreprise $entreprise, ?Client $client, ?Partenaire $partenaire)
    {
        $data = $this->createQueryBuilder('cotation')
            ->leftJoin("cotation.piste", "piste")
            ->leftJoin("piste.invite", "invite")
            // ->leftJoin("piste.client", "client")
            ->Where('invite.entreprise = :ese')
            ->andWhere('piste.client = :client')
            ->setParameter('ese', $entreprise->getId())
            ->setParameter('client', $client->getId())
            ->orderBy('cotation.id', 'ASC')
            // ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $resultat = new ArrayCollection([]);
        foreach ($data as $cotation) {
            if (count($cotation->getAvenants()) != 0 && $this->getPartenaire($cotation) == $partenaire) {
                if ($this->isSameAnnee($annee, $cotation)) {
                    $resultat->add($cotation);
                }
            }
        }
        return $resultat;
    }

    private function isSameAnnee($annee, ?Cotation $cotation): bool
    {
        return $annee == $cotation->getAvenants()[0]->getStartingAt()->format('Y');
    }

    public function loadBoundCotationsWithPartnerAll($annee, ?Entreprise $entreprise, ?Partenaire $partenaire)
    {
        $data = $this->createQueryBuilder('cotation')
            ->leftJoin("cotation.piste", "piste")
            ->leftJoin("piste.invite", "invite")
            // ->leftJoin("piste.client", "client")
            ->Where('invite.entreprise = :ese')
            // ->andWhere('piste.risque = :risque')
            ->setParameter('ese', $entreprise->getId())
            // ->setParameter('client', $client->getId())
            ->orderBy('cotation.id', 'ASC')
            // ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $resultat = new ArrayCollection([]);
        foreach ($data as $cotation) {
            if (count($cotation->getAvenants()) != 0 && $this->getPartenaire($cotation) == $partenaire) {
                if ($this->isSameAnnee($annee, $cotation)) {
                    $resultat->add($cotation);
                }
            }
        }
        return $resultat;
    }

    public function getPartenaire(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                if (count($cotation->getPiste()->getPartenaires()) >= 1) {
                    // dd($cotation->getPiste()->getPartenaires()[0]);
                    return $cotation->getPiste()->getPartenaires()[0];
                } else if (count($cotation->getPiste()->getClient()->getPartenaires()) != 0) {
                    return $cotation->getPiste()->getClient()->getPartenaires()[0];
                }
            }
        }
        return null;
    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("co")
                ->leftJoin("co.piste", "pi")
                ->where('pi.invite = :inviteId')
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('co.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("cotation")
                ->leftJoin("cotation.piste", "piste")
                ->leftJoin("piste.invite", "invite")
                ->where('invite.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('cotation.id', 'DESC'),
            $page,
            20,
        );
    }
}
