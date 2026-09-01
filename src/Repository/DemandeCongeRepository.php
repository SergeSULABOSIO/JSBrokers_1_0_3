<?php

namespace App\Repository;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeConge>
 */
class DemandeCongeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeConge::class);
    }

    /**
     * Demandes ACTIVES de l'agent qui chevauchent la période — CTRL-02.
     *
     * « Actives » = soumises ou approuvées : une demande refusée ou annulée ne bloque
     * rien. Le test de chevauchement est celui de deux intervalles fermés
     * (debut <= finAutre ET fin >= debutAutre) ; l'écrire dans l'autre sens laisse
     * passer les inclusions.
     *
     * @return DemandeConge[]
     */
    public function chevauchements(
        Invite $agent,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
        ?int $exclureId = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.agent = :agent')
            ->andWhere('d.statut IN (:actifs)')
            ->andWhere('d.dateDebut <= :fin')
            ->andWhere('d.dateFin >= :debut')
            ->setParameter('agent', $agent)
            ->setParameter('actifs', [DemandeConge::STATUT_SOUMISE, DemandeConge::STATUT_APPROUVEE])
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('d.dateDebut', 'ASC');

        if ($exclureId !== null) {
            $qb->andWhere('d.id != :exclu')->setParameter('exclu', $exclureId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Jours ENGAGÉS d'un agent sur un exercice : le total des demandes soumises et non
     * encore décidées, sur des types décomptés.
     *
     * Sans cette notion, un agent peut poser deux fois les mêmes jours en enchaînant
     * deux demandes avant toute décision — c'est le scénario 3 de la recette.
     */
    public function joursEngages(Invite $agent, int $exercice): float
    {
        // L'exercice se traduit en INTERVALLE DE DATES, jamais en YEAR(d.dateDebut) :
        // aucune extension DQL n'est enregistrée dans ce projet, et un appel de fonction
        // sur la colonne empêcherait de toute façon l'usage de son index.
        [$debut, $fin] = self::bornesDeLExercice($exercice);

        $total = $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.nbJours), 0)')
            ->join('d.typeAbsence', 't')
            ->andWhere('d.agent = :agent')
            ->andWhere('d.statut = :soumise')
            ->andWhere('t.decompte = true')
            ->andWhere('d.dateDebut >= :debut')
            ->andWhere('d.dateDebut <= :fin')
            ->setParameter('agent', $agent)
            ->setParameter('soumise', DemandeConge::STATUT_SOUMISE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $total;
    }

    /**
     * Premier et dernier jour d'un exercice (année civile). Source unique : le même
     * couple de bornes doit servir à toutes les requêtes datées du module, sinon deux
     * chiffres censés être le même finissent par diverger d'un jour.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function bornesDeLExercice(int $exercice): array
    {
        return [
            new \DateTimeImmutable(sprintf('%d-01-01', $exercice)),
            new \DateTimeImmutable(sprintf('%d-12-31', $exercice)),
        ];
    }

    /**
     * La file d'attente du valideur : les demandes soumises du cabinet, la plus proche
     * en premier — on décide d'abord ce qui commence bientôt.
     *
     * @return DemandeConge[]
     */
    public function fileDAttente(Entreprise $entreprise, int $limite = 100): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.entreprise = :entreprise')
            ->andWhere('d.statut = :soumise')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('soumise', DemandeConge::STATUT_SOUMISE)
            ->orderBy('d.dateDebut', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Absences APPROUVÉES du cabinet chevauchant la période, tous agents confondus.
     * Alimente le bloc « contexte » du mail de soumission (qui est déjà absent ?) et la
     * photo d'ensemble de l'assistant.
     *
     * @return DemandeConge[]
     */
    public function absencesApprouveesSurPeriode(
        Entreprise $entreprise,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
        ?Invite $exclureAgent = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.entreprise = :entreprise')
            ->andWhere('d.statut = :approuvee')
            ->andWhere('d.dateDebut <= :fin')
            ->andWhere('d.dateFin >= :debut')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('approuvee', DemandeConge::STATUT_APPROUVEE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('d.dateDebut', 'ASC');

        if ($exclureAgent !== null) {
            $qb->andWhere('d.agent != :moi')->setParameter('moi', $exclureAgent);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Demandes d'un agent, éventuellement filtrées par statut, la plus récente d'abord.
     *
     * @param string[] $statuts
     * @return DemandeConge[]
     */
    public function pourAgent(Invite $agent, array $statuts = [], int $limite = 50): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.agent = :agent')
            ->setParameter('agent', $agent)
            ->orderBy('d.dateDebut', 'DESC')
            ->setMaxResults($limite);

        if ($statuts !== []) {
            $qb->andWhere('d.statut IN (:statuts)')->setParameter('statuts', $statuts);
        }

        return $qb->getQuery()->getResult();
    }
}
