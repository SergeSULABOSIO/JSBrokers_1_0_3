<?php

namespace App\Ai\Mutation;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SOURCE UNIQUE de l'état d'un plan d'écriture présenté par Ket : en attente de
 * décision, validé/exécuté, ou annulé. L'état vit dans la meta du message
 * assistant qui a présenté le plan ; jusqu'ici la même règle était réécrite
 * à trois endroits (prompt système, barre de décision, endpoints d'exécution).
 *
 * Sert surtout de VERROU : tant qu'un plan attend une décision, Ket ne peut pas
 * en préparer un second (elle imposerait à l'utilisateur d'empiler des
 * validations). L'unique échappatoire est de REMPLACER le plan en attente —
 * ce qui l'annule d'abord : il n'y a jamais plus d'un plan en attente à la fois.
 */
final class PlanEnAttente
{
    /** Directive UI qui fait apparaître la barre de décision d'un plan. */
    public const ACTION_REVUE = 'ket-mutation.review';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function porteUnPlan(array $meta): bool
    {
        return ($meta['mutationPlan'] ?? null) !== null;
    }

    /** @param array<string, mixed> $meta */
    public static function estExecute(array $meta): bool
    {
        return self::porteUnPlan($meta) && ($meta['mutationPlanExecuted'] ?? false) === true;
    }

    /** @param array<string, mixed> $meta */
    public static function estAnnule(array $meta): bool
    {
        return self::porteUnPlan($meta) && ($meta['mutationPlanCancelled'] ?? false) === true;
    }

    /** Plan présenté dont l'utilisateur n'a encore ni validé ni annulé l'exécution. */
    public static function estEnAttente(array $meta): bool
    {
        return self::porteUnPlan($meta) && !self::estExecute($meta) && !self::estAnnule($meta);
    }

    /**
     * Ne conserve que la PREMIÈRE directive de revue de plan d'une réponse. Le
     * verrou de conversation ne voit que les tours PRÉCÉDENTS : si le moteur
     * appelle deux fois l'outil de préparation dans le MÊME tour, il faut encore
     * garantir un seul plan par message — un message ne stocke qu'un plan, la
     * seconde barre de décision serait orpheline. Les autres actions passent
     * inchangées.
     *
     * @param array<int, array> $actions
     *
     * @return array<int, array>
     */
    public static function limiterAUnSeulPlan(array $actions): array
    {
        $planVu = false;
        $retenues = [];
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::ACTION_REVUE) {
                if ($planVu) {
                    continue;
                }
                $planVu = true;
            }
            $retenues[] = $action;
        }

        return $retenues;
    }

    /**
     * Le DERNIER message de la conversation qui porte un plan encore en attente
     * de décision, ou null. (Il ne peut y en avoir qu'un : la préparation d'un
     * nouveau plan est verrouillée tant que celui-ci n'est pas tranché.)
     */
    public function messageEnAttente(?AssistantConversation $conversation): ?AssistantMessage
    {
        if ($conversation === null) {
            return null;
        }

        $enAttente = null;
        foreach ($conversation->getMessages() as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT
                && self::estEnAttente($message->getMeta() ?? [])) {
                $enAttente = $message;
            }
        }

        return $enAttente;
    }

    /**
     * Annule le plan en attente d'une conversation (décision implicite de
     * l'utilisateur qui demande un plan DIFFÉRENT). Même effet que le bouton
     * « Annuler » : la barre de décision devient un feedback permanent, et rien
     * n'a été écrit. Renvoie le libellé de ce qui a été annulé, ou null.
     *
     * Le flush est IMMÉDIAT et voulu : l'annulation doit survivre même si la suite
     * du tour échoue (moteur en erreur, réponse perdue). Sans quoi l'utilisateur
     * pourrait se retrouver avec l'ancienne barre encore active alors que Ket la
     * croit annulée — exactement les deux plans concurrents qu'on veut interdire.
     */
    public function annulerLePlanEnAttente(?AssistantConversation $conversation): ?string
    {
        $message = $this->messageEnAttente($conversation);
        if ($message === null) {
            return null;
        }

        $meta = $message->getMeta() ?? [];
        $meta['mutationPlanCancelled'] = true;
        $message->setMeta($meta);
        $this->em->flush();

        return $this->resume($meta['mutationPlan'] ?? []);
    }

    /**
     * Résumé lisible d'un plan stocké (« 3 opérations, 180 tokens »), pour dire à
     * l'utilisateur de QUOI on parle sans lui réafficher tout le tableau.
     *
     * @param array<string, mixed> $mutationPlan
     */
    public function resume(array $mutationPlan): string
    {
        $operations = is_array($mutationPlan['plan'] ?? null) ? count($mutationPlan['plan']) : 0;
        $cout = (int) ($mutationPlan['budget']['coutEstime'] ?? 0);

        return sprintf(
            '%d opération%s, %d token%s',
            $operations,
            $operations > 1 ? 's' : '',
            $cout,
            $cout > 1 ? 's' : '',
        );
    }
}
