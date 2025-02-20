<?php

namespace App\Repository;

use App\Entity\Note;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Note::class);
    }

    //    /**
    //     * @return Note[] Returns an array of Note objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('n.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Note
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function findAllNotesDueByInsurerAndClient(?RevenuPourCourtier $revenu): array
    {
        return $this->createQueryBuilder("note")
            //via invite
            ->leftJoin("note.invite", "invite")
            ->leftJoin("note.articles", "article")
            //condition
            ->where("article.idPoste = :idPoste")
            ->setParameter('idPoste', '' . $revenu->getId() . '')
            ->orderBy('note.id', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }
    //

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("note")
                //via invite
                ->leftJoin("note.invite", "invite")
                //condition
                ->where("invite.entreprise = :entrepriseId")
                // ->orWhere("inviteb.entreprise = :entrepriseId")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('note.id', 'DESC'),
            $page,
            20,
        );
    }
}
