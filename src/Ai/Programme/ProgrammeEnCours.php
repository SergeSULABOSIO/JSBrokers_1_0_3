<?php

namespace App\Ai\Programme;

use App\Entity\AssistantConversation;
use App\Entity\AssistantProgramme;
use App\Entity\AssistantProgrammeEtape;
use App\Repository\AssistantProgrammeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SOURCE UNIQUE de l'état d'un PROGRAMME dans un fil de conversation — le pendant,
 * à l'échelle de la SÉRIE, de ce que PlanEnAttente est à l'échelle du plan.
 *
 * Deux règles y vivent, et nulle part ailleurs :
 *  1. il n'y a JAMAIS deux programmes en cours dans une même conversation (sinon
 *     l'utilisateur se retrouverait avec deux séries entremêlées, chacune croyant
 *     détenir « l'étape suivante ») ;
 *  2. un programme est TERMINÉ dès que toutes ses étapes sont tranchées — quel
 *     que soit leur sort. Une étape annulée ou impossible ne fige jamais la série :
 *     elle sera nommée dans le rapport final, ce qui est exactement le
 *     comportement demandé (« en cas d'erreur ou omission, KET doit le signaler »).
 */
final class ProgrammeEnCours
{
    /** Préfixe des références lisibles de programme. */
    public const PREFIXE = 'PRG';

    public function __construct(
        private readonly AssistantProgrammeRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Le programme encore en cours de ce fil, ou null.
     *
     * Une conversation TRANSIENTE (jamais persistée, donc sans identifiant) n'a par
     * construction aucun programme : rien n'a pu être rattaché à un fil qui n'existe
     * pas en base. On le dit ici plutôt que de laisser Doctrine lever
     * « Binding entities to query parameters only allowed for entities that have an
     * identifier » — ce qui rendait `app:assistant:smoke` inutilisable, alors même que
     * sa raison d'être est de tester le moteur SANS rien persister.
     */
    public function courant(?AssistantConversation $conversation): ?AssistantProgramme
    {
        if ($conversation === null || $conversation->getId() === null) {
            return null;
        }

        return $this->repository->courantDe($conversation);
    }

    public function aUnProgrammeEnCours(?AssistantConversation $conversation): bool
    {
        return $this->courant($conversation) !== null;
    }

    /**
     * Référence unique et lisible d'un programme : PRG-AAAAMMJJ-XXXX. Le suffixe
     * aléatoire évite toute collision entre deux missions ouvertes le même jour
     * (l'unicité est de toute façon garantie par la contrainte de colonne ; on
     * re-tire simplement tant qu'elle est prise).
     */
    public function genererReference(): string
    {
        $jour = (new \DateTimeImmutable('now'))->format('Ymd');
        for ($essai = 0; $essai < 20; ++$essai) {
            $reference = sprintf('%s-%s-%s', self::PREFIXE, $jour, strtoupper(bin2hex(random_bytes(2))));
            if ($this->repository->findOneBy(['reference' => $reference]) === null) {
                return $reference;
            }
        }

        // Repli : l'horodatage à la microseconde ne peut pas entrer en collision.
        return sprintf('%s-%s-%s', self::PREFIXE, $jour, substr((string) hrtime(true), -6));
    }

    /**
     * Clôt le programme si toutes ses étapes sont tranchées. Renvoie true quand la
     * clôture a effectivement eu lieu (c'est ce moment-là, et lui seul, qui doit
     * déclencher le rapport final).
     */
    public function cloreSiTermine(AssistantProgramme $programme): bool
    {
        if (!$programme->estEnCours()) {
            return false;
        }
        if ($programme->prochaineEtape() !== null || $programme->etapeProposee() !== null) {
            return false;
        }

        $programme->setStatut(AssistantProgramme::STATUT_TERMINE);
        $programme->setClosedAt(new \DateTimeImmutable('now'));
        $this->em->flush();

        return true;
    }

    /**
     * Interruption VOLONTAIRE : l'utilisateur stoppe la série. Les étapes non
     * tranchées sont marquées annulées avec un motif explicite — le rapport final
     * doit pouvoir dire ce qui n'a PAS été fait, et pourquoi.
     */
    public function interrompre(AssistantProgramme $programme, string $motif): void
    {
        foreach ($programme->getEtapes() as $etape) {
            if (!$etape->estTranchee()) {
                $etape->setStatut(AssistantProgrammeEtape::STATUT_ANNULEE);
                $etape->setErreur($motif);
            }
        }
        $programme->setStatut(AssistantProgramme::STATUT_INTERROMPU);
        $programme->setClosedAt(new \DateTimeImmutable('now'));
        $this->em->flush();
    }

    /**
     * L'étape à laquelle appartient un message porteur de plan, ou null. C'est le
     * seul lien dont l'endpoint d'exécution a besoin pour savoir qu'il vient de
     * trancher une étape de programme — le plan, lui, reste dans la meta du
     * message, exactement comme pour un plan isolé.
     */
    public function etapeDuMessage(?AssistantConversation $conversation, int $idMessage): ?AssistantProgrammeEtape
    {
        if ($conversation === null) {
            return null;
        }

        return $this->em->getRepository(AssistantProgrammeEtape::class)
            ->createQueryBuilder('e')
            ->join('e.programme', 'p')
            ->join('e.message', 'm')
            ->andWhere('p.conversation = :conversation')
            ->andWhere('m.id = :idMessage')
            ->setParameter('conversation', $conversation)
            ->setParameter('idMessage', $idMessage)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
