<?php

namespace App\Repository;

use App\Entity\DemandeConge;
use App\Entity\HistoriqueDemande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HistoriqueDemande>
 */
class HistoriqueDemandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HistoriqueDemande::class);
    }

    /**
     * Le fil d'une demande, du plus ancien au plus récent : un historique se lit dans
     * l'ordre où il s'est produit.
     *
     * @return HistoriqueDemande[]
     */
    public function filDe(DemandeConge $demande): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.demande = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('h.createdAt', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le dernier statut TRACÉ pour cette demande, ou null si rien ne l'est encore.
     *
     * C'est la question que se pose l'abonné de transition : « l'historique est-il à jour
     * de ce que porte la demande ? ». Comparer à l'état réel de la demande suffit à
     * savoir s'il reste une trace à écrire — quel que soit le chemin qui l'a modifiée.
     */
    public function dernierStatutTrace(DemandeConge $demande): ?string
    {
        $ligne = $this->createQueryBuilder('h')
            ->select('h.statutApres')
            ->andWhere('h.demande = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $ligne['statutApres'] ?? null;
    }
}
