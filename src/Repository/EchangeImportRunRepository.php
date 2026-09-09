<?php

namespace App\Repository;

use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EchangeImportRun>
 *
 * @method EchangeImportRun|null find($id, $lockMode = null, $lockVersion = null)
 * @method EchangeImportRun|null findOneBy(array $criteria, array $orderBy = null)
 * @method EchangeImportRun[]    findAll()
 * @method EchangeImportRun[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EchangeImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EchangeImportRun::class);
    }

    /**
     * Le contrôle en attente de décision de CET invité, s'il y en a un.
     *
     * Scopé à l'invité et non au seul cabinet : le rapport porte le détail d'un fichier
     * qu'un collègue a déposé, avec ses erreurs et ses données. Deux personnes peuvent
     * préparer un import en parallèle sans se voir l'une l'autre.
     */
    public function enAttentePour(Entreprise $entreprise, Invite $invite): ?EchangeImportRun
    {
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.entreprise = :entreprise')
            ->andWhere('r.invite = :invite')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.expireLe > :maintenant')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('invite', $invite)
            ->setParameter('statut', EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION)
            ->setParameter('maintenant', new \DateTimeImmutable('now'))
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $runs[0] ?? null;
    }

    /**
     * LE TRAVAIL EN COURS DE CET INVITÉ — contrôle ou écriture, s'il y en a un.
     *
     * ⚠ SANS CETTE MÉTHODE, UN IMPORT DEVENAIT INVISIBLE DÈS QU'ON QUITTAIT LA PAGE.
     * L'écran ne connaissait que les contrôles EN ATTENTE DE DÉCISION : un travail qui
     * avançait encore n'apparaissait nulle part. L'utilisateur qui rafraîchissait, changeait
     * d'onglet ou revenait le lendemain retrouvait un écran vierge, sans aucun moyen de
     * savoir si son portefeuille était en train d'être repris — et redéposait, par doute.
     *
     * C'est la contrepartie du travail par paliers : puisqu'il ne vit plus dans une
     * requête, il faut savoir le retrouver.
     */
    public function travailEnCoursPour(Entreprise $entreprise, Invite $invite): ?EchangeImportRun
    {
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.entreprise = :entreprise')
            ->andWhere('r.invite = :invite')
            ->andWhere('r.statut IN (:statuts)')
            ->andWhere('r.expireLe > :maintenant')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('invite', $invite)
            ->setParameter('statuts', [EchangeImportRun::STATUT_CONTROLE, EchangeImportRun::STATUT_EN_COURS])
            ->setParameter('maintenant', new \DateTimeImmutable('now'))
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $runs[0] ?? null;
    }

    /**
     * PREND LE VERROU DE TRAVAIL. Vrai si ce processus-ci l'a obtenu.
     *
     * ⚠ POURQUOI UN VERROU ALORS QU'UN SEUL POUSSEUR EST PRÉVU. Parce que « un seul
     * pousseur » est une décision d'exploitation, pas une propriété du code : deux onglets
     * ouverts sur la rubrique, un double-clic, deux workers démarrés pour absorber une
     * charge, et deux paliers partent sur la même fenêtre de lignes. Chacun créerait « son »
     * client dans sa propre transaction, et l'idempotence n'y pourrait rien — elle ne voit
     * que ce qui est COMMITÉ. On aurait fabriqué le doublon que toute cette reprise existe
     * pour empêcher.
     *
     * ⚠ L'ATOMICITÉ VIENT DE L'UPDATE LUI-MÊME, comme pour les conversations de l'assistant
     * ({@see \App\Ai\Traitement\VerrouDeConversation}) : MariaDB pose un verrou de ligne
     * pour l'exécuter, donc de deux exécutions concurrentes une seule peut voir la
     * condition satisfaite. Il n'y a pas de fenêtre entre le test et la prise.
     *
     * ⚠ ET IL PÉRIME. Un processus tué net — déploiement, mémoire épuisée, onglet fermé —
     * ne relâche rien : sans péremption, le contrôle resterait gelé pour toujours et
     * l'utilisateur n'aurait aucun moyen de s'en sortir.
     */
    public function prendreLeTravail(int $idRun, ?\DateTimeImmutable $maintenant = null): bool
    {
        $maintenant ??= new \DateTimeImmutable('now');
        $peremption = $maintenant->sub(
            new \DateInterval('PT' . EchangeImportRun::TRAVAIL_PEREMPTION_SECONDES . 'S'),
        );

        $lignes = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE echange_import_run
                SET travail_depuis = :maintenant
              WHERE id = :id
                AND (travail_depuis IS NULL OR travail_depuis < :peremption)',
            [
                'maintenant' => $maintenant->format('Y-m-d H:i:s'),
                'id' => $idRun,
                'peremption' => $peremption->format('Y-m-d H:i:s'),
            ],
        );

        return $lignes === 1;
    }

    /**
     * Relâche le verrou. Toujours dans un `finally`.
     *
     * Un verrou oublié gèle le contrôle jusqu'à sa péremption, et l'utilisateur ne
     * comprendrait pas pourquoi son import ne repart plus.
     */
    public function relacherLeTravail(int $idRun): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE echange_import_run SET travail_depuis = NULL WHERE id = :id',
            ['id' => $idRun],
        );
    }

    /**
     * Contrôles périmés, à purger avec les fichiers qu'ils désignent.
     *
     * @return EchangeImportRun[]
     */
    public function expires(?\DateTimeImmutable $maintenant = null): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.expireLe <= :maintenant')
            ->andWhere('r.statut NOT IN (:definitifs)')
            ->setParameter('maintenant', $maintenant ?? new \DateTimeImmutable('now'))
            ->setParameter('definitifs', [EchangeImportRun::STATUT_TERMINE])
            ->getQuery()
            ->getResult();
    }
}
