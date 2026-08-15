<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantTache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantTache>
 */
class AssistantTacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantTache::class);
    }

    /**
     * La prochaine question à traiter dans cette conversation, ou null.
     *
     * `id ASC` EST la règle métier : l'ordre d'acceptation est l'ordre où
     * l'utilisateur a tapé, et le respecter est ce qui rend une rafale
     * indiscernable d'une série d'envois séquentiels.
     */
    public function prochaineEnAttente(int $idConversation): ?AssistantTache
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.conversation = :conv')
            ->andWhere('t.statut = :statut')
            ->setParameter('conv', $idConversation)
            ->setParameter('statut', AssistantTache::STATUT_EN_ATTENTE)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les tâches de la conversation qui ont encore quelque chose à dire :
     * celles qui restent ouvertes, et celles qui se sont conclues depuis le
     * dernier point de synchronisation du navigateur.
     *
     * @return list<AssistantTache>
     */
    public function suivies(AssistantConversation $conversation, ?int $depuisId = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.conversation = :conv')
            ->setParameter('conv', $conversation)
            ->orderBy('t.id', 'ASC');

        if ($depuisId !== null) {
            $qb->andWhere('t.id > :depuis OR t.statut IN (:ouverts)')
                ->setParameter('depuis', $depuisId)
                ->setParameter('ouverts', AssistantTache::STATUTS_OUVERTS);
        } else {
            $qb->andWhere('t.statut IN (:ouverts)')
                ->setParameter('ouverts', AssistantTache::STATUTS_OUVERTS);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Cette conversation a-t-elle une question non encore répondue ?
     *
     * Fail-closed des routes de DÉCISION (exécuter un plan, produire un
     * document, trancher une étape de programme) : tant qu'un traitement court,
     * le moteur est peut-être en train de construire son contexte, et une
     * écriture concurrente lui ferait lire un fil qui n'existe déjà plus. C'est
     * exactement ce que le verrou de session garantissait quand tout se passait
     * dans le même processus — on ne fait que le rendre explicite.
     */
    public function aUnTraitementOuvert(AssistantConversation $conversation): bool
    {
        return 0 < (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.conversation = :conv')
            ->andWhere('t.statut IN (:ouverts)')
            ->setParameter('conv', $conversation)
            ->setParameter('ouverts', AssistantTache::STATUTS_OUVERTS)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Note où en est le moteur, SANS passer par l'EntityManager.
     *
     * ⚠️ L'écriture est volontairement en DBAL direct. Cette méthode est appelée
     * depuis la closure abonnée à JournalTokens, c'est-à-dire à un instant choisi
     * par le moteur, au beau milieu de la construction du message assistant. Un
     * $em->flush() y viderait l'UnitOfWork à un moment arbitraire et persisterait
     * un graphe à moitié construit — un changement de comportement invisible et
     * diabolique à diagnostiquer. Une ligne minuscule, six fois par message, à
     * comparer aux trois appels réseau de vingt secondes : le coût est du bruit.
     */
    public function noterEtape(int $idTache, array $etape): void
    {
        $this->getEntityManager()->getConnection()->update(
            'assistant_tache',
            ['etape' => json_encode($etape, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)],
            ['id' => $idTache],
        );
    }
}
