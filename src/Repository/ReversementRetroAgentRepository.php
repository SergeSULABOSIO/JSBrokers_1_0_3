<?php

namespace App\Repository;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Tranche;
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
     * Le bénéficiaire (agent OU partenaire), l'avenant et le compte bancaire sont joints :
     * le service comptable lit le nom du bénéficiaire, la référence de police et le compte
     * de trésorerie de CHAQUE ligne — sans quoi trois requêtes par reversement s'allumeraient
     * au parcours.
     *
     * ⚠ TOUTES CES JOINTURES SONT EXTERNES, et il ne faut pas les « optimiser ». Un
     * reversement n'a qu'UN bénéficiaire — donc l'autre relation est nulle — et, depuis que
     * la maille du fait est la tranche, il peut n'avoir aucun avenant. Une jointure interne
     * écarterait la ligne SANS RIEN DIRE : un décaissement réel se retrouverait sans
     * écriture comptable, ce qu'aucune erreur ne signale.
     *
     * C'est arrivé : la jointure sur l'agent est restée interne le temps d'un lot, et le
     * versement d'un partenaire ne produisait aucune écriture.
     *
     * @return ReversementRetroAgent[]
     */
    public function findChronologiqueForEntreprise(int $idEntreprise): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('r.partenaire', 'p')->addSelect('p')
            ->leftJoin('r.avenant', 'av')->addSelect('av')
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
     * Le versé PAR TRANCHE, pour un lot de tranches.
     *
     * La prime et la commission se paient par tranche : c'est à cette maille que
     * l'intermédiaire est rémunéré, et c'est donc à cette maille que la colonne
     * « rétro reversée » d'une tranche devient EXACTE au lieu d'être indérivable.
     *
     * S'AJOUTE à `totauxParAvenant()` sans le remplacer : le rapport de production raisonne
     * par AFFAIRE et continue de s'appuyer sur elle. Chaque reversement portant les DEUX
     * liens, les deux lectures voient le même argent sans jamais le compter deux fois.
     *
     * Les lignes ANTÉRIEURES à ce lot n'ont pas de tranche : elles n'apparaissent donc pas
     * ici. C'était déjà le cas — le versé n'était alors attribuable à aucune tranche — et
     * rien n'est perdu ; seule la précision nouvelle leur manque.
     *
     * @param Tranche[] $tranches
     *
     * @return array<int, float> identifiant de tranche => total versé
     */
    public function totauxParTranche(Invite $agent, array $tranches): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (Tranche $t) => $t->getId(),
            $tranches,
        )));
        if ($ids === [] || $agent->getId() === null) {
            return [];
        }

        $lignes = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.tranche) AS trancheId', 'SUM(r.montant) AS total')
            ->where('r.agent = :agent')
            ->andWhere('r.tranche IN (:tranches)')
            ->setParameter('agent', $agent)
            ->setParameter('tranches', $ids)
            ->groupBy('r.tranche')
            ->getQuery()
            ->getScalarResult();

        $totaux = [];
        foreach ($lignes as $ligne) {
            $totaux[(int) $ligne['trancheId']] = round((float) $ligne['total'], 2);
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
            ->leftJoin('r.avenant', 'av')->addSelect('av')
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
     * LES LIGNES DES VIREMENTS D'UNE PAGE — montant et lot, en UNE requête.
     *
     * Depuis que la rubrique replie chaque lot sur son porteur, une ligne à l'écran doit
     * annoncer le total du VIREMENT et le nombre d'échéances qu'il règle : les frères de
     * lot ne sont plus affichés, mais leur argent l'est. Même parade au N+1 que
     * `comptesDeJustificatifs` — les lignes de la page ET leurs frères, d'un coup, le
     * regroupement se faisant ensuite en mémoire.
     *
     * @param int[]    $ids        identifiants des lignes de la page
     * @param string[] $references références de lot présentes dans la page
     *
     * @return array<int, array{id: int, lot: ?string, montant: float}>
     */
    public function lignesDeLots(array $ids, array $references): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $references = array_values(array_filter(array_map('strval', $references), static fn (string $r) => $r !== ''));
        if ($ids === [] && $references === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('r')
            ->select('r.id AS id', 'r.lotReference AS lot', 'r.montant AS montant');

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
            static fn (array $l) => [
                'id' => (int) $l['id'],
                'lot' => $l['lot'],
                'montant' => (float) $l['montant'],
            ],
            $qb->getQuery()->getScalarResult(),
        );
    }

    /**
     * A-T-ON DÉJÀ VERSÉ QUELQUE CHOSE À CE BÉNÉFICIAIRE SUR CETTE AFFAIRE ?
     *
     * C'est la question qui SCELLE un rattachement : dès qu'un virement est parti, la
     * condition de partage ne peut plus être détachée — donc le bénéficiaire ne peut plus
     * changer. On ne réécrit pas l'histoire d'un décaissement comptabilisé.
     *
     * UNE requête, et un total plutôt qu'un booléen : le refus doit pouvoir DIRE combien a
     * déjà été reçu. « Alice a déjà reçu 154,19 USD » se comprend ; « opération impossible »
     * laisse chercher.
     *
     * ── LES DEUX CHEMINS COMPTENT, ET C'EST UN CORRECTIF ────────────────────────────
     * Cette requête ne remontait QUE par l'avenant, en jointure INTERNE. Depuis que le
     * versement se rattache à une TRANCHE et que l'avenant est devenu facultatif, un
     * reversement porté par la seule échéance était INVISIBLE ici — et le rattachement
     * qu'il aurait dû sceller se laissait détacher. Un décaissement parti, et la raison
     * qui le justifie effacée derrière lui : le refus ne se déclenchait simplement pas.
     *
     * On remonte donc par les DEUX liens, en jointures externes : la tranche dit QUAND,
     * l'avenant dit SUR QUOI, et l'un ou l'autre suffit à rattacher le versement à
     * l'affaire. Charger les avenants pour les passer en IN(...) coûterait une requête de
     * plus et plafonnerait sur les grosses affaires.
     *
     * ── UNE SEULE MÉTHODE POUR LES DEUX FAMILLES ───────────────────────────────────
     * Un agent et un partenaire se règlent par le même enregistrement depuis que le
     * partenaire envoie sa note de débit. La règle de scellement est donc la même, et la
     * dédoubler aurait fait diverger les deux camps au premier ajustement.
     */
    public function totalVersePourBeneficiaireSurPiste(Invite|Partenaire $beneficiaire, Piste $piste): float
    {
        if ($beneficiaire->getId() === null || $piste->getId() === null) {
            return 0.0;
        }

        $colonne = $beneficiaire instanceof Invite ? 'agent' : 'partenaire';

        $total = $this->createQueryBuilder('r')
            ->select('SUM(r.montant)')
            ->leftJoin('r.avenant', 'av')
            ->leftJoin('av.cotation', 'c_av')
            ->leftJoin('r.tranche', 'tr')
            ->leftJoin('tr.cotation', 'c_tr')
            ->where("r.{$colonne} = :beneficiaire")
            ->andWhere('c_av.piste = :piste OR c_tr.piste = :piste')
            ->setParameter('beneficiaire', $beneficiaire)
            ->setParameter('piste', $piste)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $total, 2);
    }

    /**
     * L'ancien nom, conservé pour les appelants qui ne connaissent qu'un agent.
     *
     * @deprecated Préférer totalVersePourBeneficiaireSurPiste() : la règle vaut pour les
     *             deux familles depuis que le partenaire se règle par reversement.
     */
    public function totalVersePourAgentSurPiste(Invite $agent, Piste $piste): float
    {
        return $this->totalVersePourBeneficiaireSurPiste($agent, $piste);
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
