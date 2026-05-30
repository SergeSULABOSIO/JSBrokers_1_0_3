<?php

namespace App\Services;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;

class DashboardDataProvider
{
    private int $annee;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->annee = (int) date('Y');
    }

    public function getPaiementsTotaux(Entreprise $entreprise): float
    {
        $result = $this->em->createQuery(
            'SELECT SUM(p.montant) FROM App\Entity\Paiement p
             WHERE p.entreprise = :e
               AND p.paidAt >= :debut AND p.paidAt <= :fin'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', new \DateTimeImmutable("01/01/{$this->annee} 00:00"))
        ->setParameter('fin',   new \DateTimeImmutable("12/31/{$this->annee} 23:59"))
        ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    public function getPoliciesActives(Entreprise $entreprise): int
    {
        $result = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Avenant a
             WHERE a.entreprise = :e
               AND a.renewalStatus = :status'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('status', Avenant::RENEWAL_STATUS_RUNNING)
        ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function getRenouvellements30j(Entreprise $entreprise): array
    {
        return $this->em->createQuery(
            'SELECT a, c, ass FROM App\Entity\Avenant a
             JOIN a.cotation c
             JOIN c.assureur ass
             WHERE a.entreprise = :e
               AND a.endingAt BETWEEN :debut AND :fin
             ORDER BY a.endingAt ASC'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', new \DateTimeImmutable('now'))
        ->setParameter('fin',   new \DateTimeImmutable('+30 days'))
        ->setMaxResults(3)
        ->getResult();
    }

    public function getProductionParMois(Entreprise $entreprise): array
    {
        $rows = $this->em->createQuery(
            'SELECT MONTH(p.paidAt) as mois, SUM(p.montant) as total
             FROM App\Entity\Paiement p
             WHERE p.entreprise = :e
               AND YEAR(p.paidAt) = :annee
             GROUP BY mois
             ORDER BY mois ASC'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('annee', $this->annee)
        ->getResult();

        $labels = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
        $data   = array_fill(0, 12, 0);
        foreach ($rows as $row) {
            $data[(int)$row['mois'] - 1] = (float)$row['total'];
        }

        return ['labels' => $labels, 'data' => $data];
    }

    public function getTopAssureurs(Entreprise $entreprise): array
    {
        return $this->em->createQuery(
            'SELECT ass.nom, COUNT(a.id) as nbPolices
             FROM App\Entity\Avenant a
             JOIN a.cotation c
             JOIN c.assureur ass
             WHERE a.entreprise = :e
             GROUP BY ass.id
             ORDER BY nbPolices DESC'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults(3)
        ->getResult();
    }

    public function getNbRenouvellements30j(Entreprise $entreprise): int
    {
        $result = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Avenant a
             WHERE a.entreprise = :e
               AND a.endingAt BETWEEN :debut AND :fin'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', new \DateTimeImmutable('now'))
        ->setParameter('fin',   new \DateTimeImmutable('+30 days'))
        ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
