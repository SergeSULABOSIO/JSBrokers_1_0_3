<?php

namespace App\Repository;

use App\Entity\Invite;
use App\Entity\MouvementConge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementConge>
 */
class MouvementCongeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementConge::class);
    }

    /**
     * Totaux du journal d'un agent sur un exercice, GROUPÉS PAR NATURE.
     *
     * Une seule requête pour tout le compteur. On ne somme pas ici : on rapporte les
     * chiffres bruts, et c'est CalculateurSolde — source unique — qui décide de ce que
     * chaque nature devient dans l'acquis. Faire l'arbitrage en SQL le dupliquerait.
     *
     * @return array<string, float> nature => somme signée des quantités
     */
    public function totauxParNature(Invite $agent, int $exercice): array
    {
        $lignes = $this->createQueryBuilder('m')
            ->select('m.nature AS nature, COALESCE(SUM(m.quantite), 0) AS total')
            ->andWhere('m.agent = :agent')
            ->andWhere('m.exercice = :exercice')
            ->setParameter('agent', $agent)
            ->setParameter('exercice', $exercice)
            ->groupBy('m.nature')
            ->getQuery()
            ->getScalarResult();

        $totaux = [];
        foreach ($lignes as $ligne) {
            $totaux[(string) $ligne['nature']] = (float) $ligne['total'];
        }

        return $totaux;
    }

    /**
     * Le journal d'un agent sur un exercice, du plus récent au plus ancien.
     *
     * @return MouvementConge[]
     */
    public function journalDe(Invite $agent, int $exercice, int $limite = 200): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.agent = :agent')
            ->andWhere('m.exercice = :exercice')
            ->setParameter('agent', $agent)
            ->setParameter('exercice', $exercice)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Existe-t-il déjà un mouvement de cette nature pour cet agent et cet exercice ?
     *
     * C'est la garde d'IDEMPOTENCE du provisionnement et de toute reprise : une dotation
     * semée deux fois doublerait silencieusement le droit de l'agent, et rien dans
     * l'écran ne le signalerait.
     */
    public function existePour(Invite $agent, int $exercice, string $nature): bool
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.agent = :agent')
            ->andWhere('m.exercice = :exercice')
            ->andWhere('m.nature = :nature')
            ->setParameter('agent', $agent)
            ->setParameter('exercice', $exercice)
            ->setParameter('nature', $nature)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Le mouvement déjà écrit pour cette demande et cette nature, s'il existe.
     *
     * Garde d'idempotence de l'abonné de transition : une double approbation (deux
     * onglets, un rejeu de message) ne doit pas décompter les jours deux fois.
     */
    public function pourDemandeEtNature(int $idDemande, string $nature): ?MouvementConge
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.demande = :demande')
            ->andWhere('m.nature = :nature')
            ->setParameter('demande', $idDemande)
            ->setParameter('nature', $nature)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
