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

    /**
     * LES NOTES DU CABINET, CHRONOLOGIQUES — source des écritures d'ÉMISSION du
     * moteur comptable du courtier (CourtierEcritureComptableService).
     *
     * ⚠ LE PÉRIMÈTRE EST CELUI DE `PaiementRepository::findChronologiqueForEntreprise()`,
     * AU MOT PRÈS : jointure interne sur `note.invite.entreprise`. Ce n'est pas une
     * coïncidence qu'il faut protéger — c'est une CONDITION. Les deux requêtes
     * alimentent les deux moitiés d'une même partie double : l'émission fait naître la
     * créance, l'encaissement la solde. Un périmètre plus large ici ferait naître des
     * créances dont le règlement resterait invisible, et le compte 411 ne se solderait
     * jamais. Élargir l'un OBLIGE à élargir l'autre.
     *
     * Le fetch-join des articles, du bordereau et de la taxe est celui de la
     * ventilation HT / taxe : sans lui, chaque note rouvrirait la base.
     *
     * @return Note[]
     */
    public function findChronologiqueForEntreprise(int $idEntreprise): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.invite', 'i')
            ->leftJoin('n.articles', 'a')->addSelect('a')
            ->leftJoin('n.bordereau', 'b')->addSelect('b')
            ->leftJoin('n.autoritefiscale', 'af')->addSelect('af')
            ->leftJoin('af.taxe', 'tx')->addSelect('tx')
            ->where('i.entreprise = :entrepriseId')
            ->setParameter('entrepriseId', $idEntreprise)
            ->orderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

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
