<?php

namespace App\Repository;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Risque;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Doctrine\Common\Collections\ArrayCollection;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Cotation>
 *
 * @method \Doctrine\ORM\QueryBuilder createQueryBuilder(string $alias, string $indexBy = null)
 * @method Cotation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Cotation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Cotation[]    findAll()
 * @method Cotation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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

    /**
     * Retrouve la cotation liée à une référence de police.
     * La référence de police est portée par les avenants, pas par la cotation elle-même.
     */
    public function findOneByReferencePolice(string $referencePolice): ?Cotation
    {
        return $this->createQueryBuilder('c')
            ->join('c.avenants', 'a')
            ->andWhere('a.referencePolice = :referencePolice')
            ->setParameter('referencePolice', $referencePolice)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * LES COTATIONS D'UNE UNITÉ DE MESURE — socle unique des trois portées.
     *
     * Une condition de partage à seuil compare son seuil à la somme de commission pure
     * d'une UNITÉ : le même risque, le même client, ou toute la production. Dans les trois
     * cas la restriction est la même — même entreprise, même exercice, même BÉNÉFICIAIRE —
     * et seul le critère supplémentaire change. Les trois méthodes publiques ci-dessous
     * n'en sont plus que des façades.
     *
     * LE BÉNÉFICIAIRE PEUT ÊTRE DES DEUX BORDS :
     *  - un Partenaire externe : rattaché à la piste ou au client, il se teste en mémoire
     *    (règle historique, portée par getPartenaire()) ;
     *  - un Invite (agent interne) : il se teste EN SQL, par la condition de partage
     *    rattachée à la piste. C'est du code neuf, on ne lui impose pas de charger tout le
     *    portefeuille de l'entreprise pour en jeter la plus grande part.
     *
     * @param string|null $critere expression DQL supplémentaire (« piste.risque = :cible »)
     */
    private function cotationsDeLUnite(
        $annee,
        ?Entreprise $entreprise,
        Partenaire|Invite|null $beneficiaire,
        ?string $critere = null,
        ?int $cibleId = null,
    ): ArrayCollection {
        $qb = $this->createQueryBuilder('cotation')
            ->leftJoin('cotation.piste', 'piste')
            ->leftJoin('piste.invite', 'invite')
            ->where('invite.entreprise = :ese')
            ->setParameter('ese', $entreprise?->getId())
            ->orderBy('cotation.id', 'ASC');

        if ($critere !== null) {
            $qb->andWhere($critere)->setParameter('cible', $cibleId);
        }

        if ($beneficiaire instanceof Invite) {
            $qb->join('piste.conditionsPartageAgent', 'cpa')
                ->andWhere('cpa.agent = :agent')
                ->setParameter('agent', $beneficiaire)
                ->distinct();
        }

        $resultat = new ArrayCollection([]);
        foreach ($qb->getQuery()->getResult() as $cotation) {
            // Le bénéficiaire agent est déjà tranché en SQL ; reste le partenaire.
            if ($beneficiaire instanceof Invite || $this->estLeMemePartenaire($this->getPartenaire($cotation), $beneficiaire)) {
                if ($this->isSameAnnee($annee, $cotation)) {
                    $resultat->add($cotation);
                }
            }
        }

        return $resultat;
    }

    /**
     * Deux partenaires sont-ils le même ? Comparaison par IDENTIFIANT, jamais `==` :
     * l'égalité lâche compare deux objets champ à champ, et deux proxies Doctrine non
     * initialisés — tous leurs champs à null — se déclarent égaux quel que soit le
     * partenaire qu'ils représentent. Même règle que
     * IndicatorCalculationHelper::isSamePartenaire(), au détail près que `null` signifie
     * ici « pas de partenaire » et non « ne pas filtrer ».
     */
    private function estLeMemePartenaire(?Partenaire $a, Partenaire|Invite|null $b): bool
    {
        if (!$b instanceof Partenaire) {
            return $a === null && $b === null;
        }
        if ($a === null) {
            return false;
        }

        return $a->getId() !== null && $b->getId() !== null
            ? $a->getId() === $b->getId()
            : $a === $b;
    }

    public function loadCotationsWithPartnerRisque($annee, ?Entreprise $entreprise, ?Risque $risque, Partenaire|Invite|null $partenaire)
    {
        return $this->cotationsDeLUnite($annee, $entreprise, $partenaire, 'piste.risque = :cible', $risque?->getId());
    }

    public function loadCotationsWithPartnerClient($annee, ?Entreprise $entreprise, ?Client $client, Partenaire|Invite|null $partenaire)
    {
        return $this->cotationsDeLUnite($annee, $entreprise, $partenaire, 'piste.client = :cible', $client?->getId());
    }

    private function isSameAnnee($annee, ?Cotation $cotation): bool
    {
        //Toute cotation doit avoir une date probable de démarrage de la police.
        //Mais c'est beaucoup plus l'année qui importe plus
        if (count($cotation->getAvenants()) != 0) {
            return $annee == $cotation->getAvenants()[0]->getStartingAt()->format('Y');
        }else{
            return $annee == $cotation->getPiste()->getExercice();
        }
    }

    public function loadCotationsWithPartnerAll($annee, ?Entreprise $entreprise, Partenaire|Invite|null $partenaire)
    {
        return $this->cotationsDeLUnite($annee, $entreprise, $partenaire);
    }

    public function getPartenaire(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                if ($cotation->getPiste()->getPartenaire() !== null) {
                    // dd($cotation->getPiste()->getPartenaires()[0]);
                    return $cotation->getPiste()->getPartenaire();
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
