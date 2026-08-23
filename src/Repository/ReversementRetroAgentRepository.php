<?php

namespace App\Repository;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReversementRetroAgent>
 */
class ReversementRetroAgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReversementRetroAgent::class);
    }

    /**
     * Tous les reversements d'une entreprise, du plus ancien au plus récent — la forme
     * qu'attend la génération des écritures comptables (même contrat que
     * PaiementRepository et DepenseCourtierRepository).
     *
     * L'agent, l'avenant et le compte bancaire sont joints : le service comptable lit le
     * nom du bénéficiaire, la référence de police et le compte de trésorerie de CHAQUE
     * ligne — sans quoi trois requêtes par reversement s'allumeraient au parcours.
     *
     * @return ReversementRetroAgent[]
     */
    public function findChronologiqueForEntreprise(int $idEntreprise): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.agent', 'a')->addSelect('a')
            ->join('r.avenant', 'av')->addSelect('av')
            ->leftJoin('r.compteBancaire', 'cb')->addSelect('cb')
            ->where('r.entreprise = :entrepriseId')
            ->setParameter('entrepriseId', $idEntreprise)
            ->orderBy('r.paidAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les reversements déjà versés à un agent sur un lot d'avenants — lecture UNIQUE qui
     * alimente le « payé » et le « solde » de chaque ligne du rapport de production.
     *
     * Interroger avenant par avenant coûterait une requête par ligne du rapport ; on lit
     * donc tout le lot d'un coup et on regroupe en mémoire.
     *
     * @param Avenant[] $avenants
     *
     * @return array<int, float> identifiant d'avenant => total versé
     */
    public function totauxParAvenant(Invite $agent, array $avenants): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (Avenant $a) => $a->getId(),
            $avenants,
        )));
        if ($ids === [] || $agent->getId() === null) {
            return [];
        }

        $lignes = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.avenant) AS avenantId', 'SUM(r.montant) AS total')
            ->where('r.agent = :agent')
            ->andWhere('r.avenant IN (:avenants)')
            ->setParameter('agent', $agent)
            ->setParameter('avenants', $ids)
            ->groupBy('r.avenant')
            ->getQuery()
            ->getScalarResult();

        $totaux = [];
        foreach ($lignes as $ligne) {
            $totaux[(int) $ligne['avenantId']] = round((float) $ligne['total'], 2);
        }

        return $totaux;
    }

    /**
     * TOUS les reversements d'un agent, du plus récent au plus ancien.
     *
     * Alimente le volet « Versements enregistrés » du rapport de production, qui les
     * regroupe ensuite PAR VIREMENT (cf. LotDeVersement). On joint ici les relations
     * to-ONE dont la ligne a besoin — l'avenant pour la police, le compte pour la
     * trésorerie — et rien de plus : joindre en même temps la collection `documents`
     * multiplierait chaque reversement par son nombre de pièces (produit cartésien),
     * et le total du lot serait faux. Le compte des pièces se lit séparément.
     *
     * @return ReversementRetroAgent[]
     */
    public function findPourAgent(Invite $agent, Entreprise $entreprise): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.avenant', 'av')->addSelect('av')
            ->leftJoin('r.compteBancaire', 'cb')->addSelect('cb')
            ->where('r.agent = :agent')
            ->andWhere('r.entreprise = :entreprise')
            ->setParameter('agent', $agent)
            ->setParameter('entreprise', $entreprise)
            ->orderBy('r.paidAt', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
    /**
     * LES PIÈCES DE CHAQUE VIREMENT, POUR UNE PAGE ENTIÈRE — en UNE requête.
     *
     * La colonne « Justificatif » compte les pièces du VIREMENT et non de la ligne : un
     * bordereau couvre tout un lot. Calculer cela ligne à ligne rallumerait une requête par
     * ligne, et deux pour un lot — le N+1 déjà combattu ailleurs dans ce projet. On lit donc
     * d'un coup les reversements de la page ET leurs frères de lot, avec leur compte de
     * documents ; le regroupement par lot se fait ensuite en mémoire.
     *
     * @param int[]    $ids        identifiants des lignes de la page
     * @param string[] $references références de lot présentes dans la page
     *
     * @return array<int, array{id: int, lot: ?string, nb: int}>
     */
    public function comptesDeJustificatifs(array $ids, array $references): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $references = array_values(array_filter(array_map('strval', $references), static fn (string $r) => $r !== ''));
        if ($ids === [] && $references === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('r')
            ->select('r.id AS id', 'r.lotReference AS lot', 'COUNT(d.id) AS nb')
            ->leftJoin(\App\Entity\Document::class, 'd', 'WITH', 'd.reversementRetroAgent = r.id')
            ->groupBy('r.id')
            ->addGroupBy('r.lotReference');

        // Les lignes de la page, ET les frères de lot qui n'y figurent pas : c'est l'un
        // d'eux qui porte peut-être le bordereau.
        $ou = [];
        if ($ids !== []) {
            $ou[] = 'r.id IN (:ids)';
            $qb->setParameter('ids', $ids);
        }
        if ($references !== []) {
            $ou[] = 'r.lotReference IN (:refs)';
            $qb->setParameter('refs', $references);
        }
        $qb->where(implode(' OR ', $ou));

        return array_map(
            static fn (array $l) => ['id' => (int) $l['id'], 'lot' => $l['lot'], 'nb' => (int) $l['nb']],
            $qb->getQuery()->getScalarResult(),
        );
    }
    /**
     * A-T-ON DÉJÀ VERSÉ QUELQUE CHOSE À CET AGENT SUR CETTE AFFAIRE ?
     *
     * C'est la question qui SCELLE un rattachement : dès qu'un virement est parti, la
     * condition de partage ne peut plus être détachée — donc l'agent bénéficiaire ne peut
     * plus changer. On ne réécrit pas l'histoire d'un décaissement comptabilisé.
     *
     * UNE requête, et un total plutôt qu'un booléen : le refus doit pouvoir DIRE combien a
     * déjà été reçu. « Alice a déjà reçu 154,19 USD » se comprend ; « opération impossible »
     * laisse chercher.
     *
     * On remonte par la cotation : un reversement porte l'avenant, l'avenant sa cotation,
     * la cotation sa piste. Charger les avenants pour les passer en IN(...) coûterait une
     * requête de plus et plafonnerait sur les grosses affaires.
     */
    public function totalVersePourAgentSurPiste(Invite $agent, Piste $piste): float
    {
        if ($agent->getId() === null || $piste->getId() === null) {
            return 0.0;
        }

        $total = $this->createQueryBuilder('r')
            ->select('SUM(r.montant)')
            ->join('r.avenant', 'av')
            ->join('av.cotation', 'c')
            ->where('r.agent = :agent')
            ->andWhere('c.piste = :piste')
            ->setParameter('agent', $agent)
            ->setParameter('piste', $piste)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $total, 2);
    }
    /**
     * Total déjà reversé à un agent dans l'entreprise, tous avenants confondus.
     * Une seule agrégation SQL : c'est la colonne « Rétrocom. payée » de la rubrique
     * Invités, appelée une fois par ligne de liste.
     */
    public function totalVersePourAgent(Invite $agent, Entreprise $entreprise): float
    {
        $total = $this->createQueryBuilder('r')
            ->select('SUM(r.montant)')
            ->where('r.agent = :agent')
            ->andWhere('r.entreprise = :entreprise')
            ->setParameter('agent', $agent)
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $total, 2);
    }
}
