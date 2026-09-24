<?php

namespace App\Repository;

use App\Entity\KetReglageJournal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KetReglageJournal>
 */
class KetReglageJournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KetReglageJournal::class);
    }

    /**
     * Les derniers changements, du plus récent au plus ancien.
     *
     * Plafonné : l'historique se consulte pour comprendre ce qui vient de se passer,
     * pas pour auditer trois ans. Un écran qui charge tout finit par ne plus être
     * ouvert du tout.
     *
     * @return KetReglageJournal[]
     */
    public function derniers(int $limite = 50): array
    {
        return $this->createQueryBuilder('j')
            ->orderBy('j.effectueLe', 'DESC')
            ->addOrderBy('j.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Le dernier changement de chaque élément, indexé par élément — ce qu'affiche la
     * fiche de détail (« coupé le 12/09 par S. Sula »).
     *
     * UNE SEULE REQUÊTE pour toute la liste : interroger le journal outil par outil
     * ferait cinquante-deux allers-retours pour une page qui en affiche cinquante-deux.
     *
     * @return array<string, KetReglageJournal>
     */
    public function dernierParElement(): array
    {
        $derniers = [];

        foreach ($this->createQueryBuilder('j')->orderBy('j.effectueLe', 'ASC')->addOrderBy('j.id', 'ASC')->getQuery()->getResult() as $ligne) {
            // On écrase au fil du parcours ascendant : il reste le plus récent.
            $derniers[$ligne->getElement()] = $ligne;
        }

        return $derniers;
    }
}
