<?php

namespace App\Services\Note;

use App\Entity\Entreprise;
use App\Entity\Note;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Source de vérité du RECOUVREMENT des commissions facturées aux assureurs.
 *
 * Distinguer les deux stades, jamais les confondre :
 *  - commission EXIGIBLE : la prime est payée, la commission peut être facturée
 *    — c'est TranchePaiementService, axes {prime: payee, commission: impayee} ;
 *  - commission FACTURÉE non encaissée : une note de débit adressée à l'assureur
 *    a été émise et son solde reste dû — c'est ce service.
 *
 * Comme pour les tranches, le solde d'une note n'est JAMAIS stocké : il est dérivé
 * à la volée par NoteIndicatorStrategy (montantTotal − montantPaye, avec un second
 * chemin pour les notes issues d'un bordereau). Le filtrage se fait donc en mémoire,
 * après une requête bornée en SQL : type + destinataire + validation.
 *
 * PÉRIMÈTRE : la Note n'est pas rattachable à un portefeuille (absente de
 * PortefeuilleScope::PATHS — une note de débit agrège les commissions de plusieurs
 * clients, souvent de plusieurs gestionnaires). Ce suivi est donc AU NIVEAU DU
 * CABINET, et ses consommateurs doivent l'annoncer comme tel pour ne pas laisser
 * croire à un chiffre de portefeuille.
 */
class NoteRecouvrementService
{
    /** Garde-fou du chemin in-memory : seuil de journalisation, le traitement continue. */
    private const MAX_NOTES_EN_MEMOIRE = 5000;

    /** En deçà, un solde relève de l'arrondi comptable et non d'une créance. */
    private const SEUIL_SOLDE = 0.005;

    private LoggerInterface $logger;

    public function __construct(
        private readonly CanvasBuilder $canvasBuilder,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Notes de débit adressées aux assureurs dont le solde reste dû, les plus
     * anciennes d'abord (l'ancienneté est l'argument de relance).
     *
     * @return array{items: Note[], totaux: array{nb: int, totalFacture: float, totalEncaisse: float, totalSolde: float}, totalItems: int, currentPage: int, totalPages: int}
     */
    public function lister(Entreprise $entreprise, int $page = 1, int $limit = 20): array
    {
        $impayees = $this->impayees($entreprise);
        $total = count($impayees);

        $totaux = ['nb' => $total, 'totalFacture' => 0.0, 'totalEncaisse' => 0.0, 'totalSolde' => 0.0];
        foreach ($impayees as $note) {
            $totaux['totalFacture'] += (float) ($note->montantTotal ?? 0);
            $totaux['totalEncaisse'] += (float) ($note->montantPaye ?? 0);
            $totaux['totalSolde'] += max(0.0, (float) ($note->solde ?? 0));
        }
        $totaux = array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $totaux);

        return [
            'items' => array_slice($impayees, ($page - 1) * $limit, $limit),
            'totaux' => $totaux,
            'totalItems' => $total,
            'currentPage' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * Ancienneté de la créance en jours : depuis l'envoi à l'assureur, à défaut
     * depuis l'émission. `null` si aucune des deux dates n'est exploitable.
     */
    public function joursAnciennete(Note $note, ?\DateTimeImmutable $reference = null): ?int
    {
        $depuis = $note->getSentAt() ?? $note->getCreatedAt();
        if (!$depuis instanceof \DateTimeInterface) {
            return null;
        }

        $reference ??= new \DateTimeImmutable('today');
        $depuis = \DateTimeImmutable::createFromInterface($depuis)->setTime(0, 0);

        return max(0, (int) $depuis->diff($reference)->format('%r%a'));
    }

    /**
     * Notes de débit assureur encore dues, triées par ancienneté décroissante
     * (ensemble complet, non paginé).
     *
     * @return Note[]
     */
    private function impayees(Entreprise $entreprise): array
    {
        // Requête bornée : seules les notes de débit VALIDÉES et adressées à un
        // assureur constituent une créance de commission. Une note non validée est
        // encore un brouillon — la facturer serait prématuré.
        $notes = $this->em->getRepository(Note::class)->createQueryBuilder('n')
            ->andWhere('n.entreprise = :entreprise')
            ->andWhere('n.type = :type')
            ->andWhere('n.addressedTo = :destinataire')
            ->andWhere('n.validated = true')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('type', Note::TYPE_NOTE_DE_DEBIT)
            ->setParameter('destinataire', Note::TO_ASSUREUR)
            ->getQuery()
            ->getResult();

        $this->chargerIndicateurs($notes);

        $impayees = array_values(array_filter(
            $notes,
            static fn (Note $n): bool => (float) ($n->solde ?? 0) > self::SEUIL_SOLDE
        ));

        // Tri en PHP plutôt qu'en DQL : la date d'ancienneté est un repli
        // (sentAt à défaut createdAt) que l'ORDER BY de Doctrine n'exprime pas
        // sans SELECT HIDDEN — et l'ensemble est de toute façon déjà en mémoire.
        usort($impayees, static function (Note $a, Note $b): int {
            $cle = static fn (Note $n): int => ($n->getSentAt() ?? $n->getCreatedAt())?->getTimestamp() ?? PHP_INT_MAX;

            return $cle($a) <=> $cle($b) ?: $a->getId() <=> $b->getId();
        });

        return $impayees;
    }

    /**
     * Hydrate les valeurs calculées d'un lot de notes (montantTotal, montantPaye,
     * solde, statutPaiement…). Même mécanique que TranchePaiementService : le
     * préchargement batch évite le N+1 sur les articles et les paiements.
     *
     * @param Note[] $notes
     */
    private function chargerIndicateurs(array $notes): void
    {
        if (count($notes) > self::MAX_NOTES_EN_MEMOIRE) {
            $this->logger->warning('[NoteRecouvrement] Volume de notes inhabituel pour le filtrage en mémoire.', [
                'nb' => count($notes),
                'seuil' => self::MAX_NOTES_EN_MEMOIRE,
            ]);
        }

        $this->canvasBuilder->batchPreloadForCollection($notes);
        foreach ($notes as $note) {
            $this->canvasBuilder->loadAllCalculatedValues($note);
        }
    }
}
