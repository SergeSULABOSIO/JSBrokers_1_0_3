<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\Entreprise;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Document::class);
    }

    //    /**
    //     * @return Document[] Returns an array of Document objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Document
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }


    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("do")
                ->leftJoin("do.piste", "pi")
                ->where('pi.invite = :inviteId')
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('do.id', 'DESC'),
            $page,
            20,
        );
    }

    public function getDossier(string $nom, Entreprise $entreprise): ?Document
    {
        return
            $this->createQueryBuilder("document")
            //via cotation
            ->leftJoin("document.cotation", "cotation")
            ->leftJoin("cotation.piste", "piste")
            ->leftJoin("piste.invite", "invite")
            //via avenant
            ->leftJoin("document.avenant", "avenant")
            ->leftJoin("avenant.cotation", "cotationb")
            ->leftJoin("cotationb.piste", "pisteb")
            ->leftJoin("pisteb.invite", "inviteb")
            //via tache
            ->leftJoin("document.tache", "avenantb")
            ->leftJoin("avenantb.piste", "pistec")
            ->leftJoin("pistec.invite", "invitec")
            //via feedback
            ->leftJoin("document.feedback", "feedback")
            ->leftJoin("feedback.tache", "avenantc")
            ->leftJoin("avenantc.piste", "pisted")
            ->leftJoin("pisted.invite", "invited")
            //via piste
            ->leftJoin("document.piste", "pistee")
            ->leftJoin("pistee.invite", "invitee")
            //via pieces sinistre
            ->leftJoin("document.pieceSinistre", "piece")
            ->leftJoin("piece.invite", "invitef")
            //via offre indemnisation
            ->leftJoin("document.offreIndemnisationSinistre", "offre")
            ->leftJoin("offre.notificationSinistre", "notification")
            ->leftJoin("notification.invite", "inviteg")
            //via paiement
            ->leftJoin("document.paiement", "paiement")
            ->leftJoin("paiement.offreIndemnisationSinistre", "offreb")
            ->leftJoin("offreb.notificationSinistre", "notificationb")
            ->leftJoin("notificationb.invite", "inviteh")
            //via partenaire
            ->leftJoin("document.partenaire", "partenaire")
            //via compte bancaire
            ->leftJoin("document.compteBancaire", "comptebancaire")
            //via client
            ->leftJoin("document.client", "client")
            //via bordereau
            ->leftJoin("document.bordereau", "bordereau")
            ->leftJoin("bordereau.invite", "invitei")
            //via Note
            ->leftJoin("document.paiement", "paiementb")
            ->leftJoin("paiementb.note", "note")
            ->leftJoin("note.invite", "invitej")


            //Condition Où
            ->where('invite.entreprise = :entrepriseId')
            ->orWhere('inviteb.entreprise = :entrepriseId')
            ->orWhere('inviteb.entreprise = :entrepriseId')
            ->orWhere('invitec.entreprise = :entrepriseId')
            ->orWhere('invited.entreprise = :entrepriseId')
            ->orWhere('invitee.entreprise = :entrepriseId')
            ->orWhere('invitef.entreprise = :entrepriseId')
            ->orWhere('inviteg.entreprise = :entrepriseId')
            ->orWhere('inviteh.entreprise = :entrepriseId')
            ->orWhere('partenaire.entreprise = :entrepriseId')
            ->orWhere('comptebancaire.entreprise = :entrepriseId')
            ->orWhere('client.entreprise = :entrepriseId')
            ->orWhere('invitei.entreprise = :entrepriseId')
            ->orWhere('invitej.entreprise = :entrepriseId')

            ->setParameter('entrepriseId', '' . $entreprise->getId() . '')
            ->orderBy('document.id', 'DESC')
            ->getQuery()
            ->getOneOrNullResult();
        return null;
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("document")
                //via cotation
                ->leftJoin("document.cotation", "cotation")
                ->leftJoin("cotation.piste", "piste")
                ->leftJoin("piste.invite", "invite")
                //via avenant
                ->leftJoin("document.avenant", "avenant")
                ->leftJoin("avenant.cotation", "cotationb")
                ->leftJoin("cotationb.piste", "pisteb")
                ->leftJoin("pisteb.invite", "inviteb")
                //via tache
                ->leftJoin("document.tache", "avenantb")
                ->leftJoin("avenantb.piste", "pistec")
                ->leftJoin("pistec.invite", "invitec")
                //via feedback
                ->leftJoin("document.feedback", "feedback")
                ->leftJoin("feedback.tache", "avenantc")
                ->leftJoin("avenantc.piste", "pisted")
                ->leftJoin("pisted.invite", "invited")
                //via piste
                ->leftJoin("document.piste", "pistee")
                ->leftJoin("pistee.invite", "invitee")
                //via pieces sinistre
                ->leftJoin("document.pieceSinistre", "piece")
                ->leftJoin("piece.invite", "invitef")
                //via offre indemnisation
                ->leftJoin("document.offreIndemnisationSinistre", "offre")
                ->leftJoin("offre.notificationSinistre", "notification")
                ->leftJoin("notification.invite", "inviteg")
                //via paiement
                ->leftJoin("document.paiement", "paiement")
                ->leftJoin("paiement.offreIndemnisationSinistre", "offreb")
                ->leftJoin("offreb.notificationSinistre", "notificationb")
                ->leftJoin("notificationb.invite", "inviteh")
                //via partenaire
                ->leftJoin("document.partenaire", "partenaire")
                //via compte bancaire
                ->leftJoin("document.compteBancaire", "comptebancaire")
                //via client
                ->leftJoin("document.client", "client")
                //via bordereau
                ->leftJoin("document.bordereau", "bordereau")
                ->leftJoin("bordereau.invite", "invitei")
                //via Note
                ->leftJoin("document.paiement", "paiementb")
                ->leftJoin("paiementb.note", "note")
                ->leftJoin("note.invite", "invitej")


                //Condition Où
                ->where('invite.entreprise = :entrepriseId')
                ->orWhere('inviteb.entreprise = :entrepriseId')
                ->orWhere('inviteb.entreprise = :entrepriseId')
                ->orWhere('invitec.entreprise = :entrepriseId')
                ->orWhere('invited.entreprise = :entrepriseId')
                ->orWhere('invitee.entreprise = :entrepriseId')
                ->orWhere('invitef.entreprise = :entrepriseId')
                ->orWhere('inviteg.entreprise = :entrepriseId')
                ->orWhere('inviteh.entreprise = :entrepriseId')
                ->orWhere('partenaire.entreprise = :entrepriseId')
                ->orWhere('comptebancaire.entreprise = :entrepriseId')
                ->orWhere('client.entreprise = :entrepriseId')
                ->orWhere('invitei.entreprise = :entrepriseId')
                ->orWhere('invitej.entreprise = :entrepriseId')

                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('document.id', 'DESC'),
            $page,
            20,
        );
    }
}
