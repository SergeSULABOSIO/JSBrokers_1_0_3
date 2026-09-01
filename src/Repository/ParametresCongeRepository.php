<?php

namespace App\Repository;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ParametresConge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParametresConge>
 */
class ParametresCongeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParametresConge::class);
    }

    /**
     * Les réglages du cabinet — toujours un objet, jamais null.
     *
     * UN CABINET SANS RÉGLAGES N'EST PAS UN CABINET SANS RÈGLES : c'est un cabinet aux
     * valeurs par défaut. Rendre null obligerait chaque appelant à refaire ce choix, et
     * l'un d'eux finirait par le faire autrement — un contrôle actif ici, inactif là.
     *
     * L'objet rendu n'est PAS persisté s'il n'existait pas : il ne le sera qu'au premier
     * enregistrement du formulaire. On ne crée pas une ligne en base pour répondre à une
     * lecture.
     */
    public function pourEntreprise(Entreprise $entreprise, ?Invite $proprietaire = null): ParametresConge
    {
        $parametres = $this->createQueryBuilder('p')
            ->andWhere('p.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($parametres instanceof ParametresConge) {
            return $parametres;
        }

        $parametres = new ParametresConge();
        $parametres->setEntreprise($entreprise);
        if ($proprietaire !== null) {
            $parametres->setInvite($proprietaire);
        }

        return $parametres;
    }
}
