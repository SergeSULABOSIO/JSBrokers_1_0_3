<?php

namespace App\Ai\Document;

use App\Ai\Action\TypeAction;
use App\Ai\Mutation\PlanEnAttente;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SOURCE UNIQUE de l'état d'un plan de DOCUMENT : en attente de décision, produit,
 * ou annulé. Classe sœur de {@see PlanEnAttente}, dont elle reprend la mécanique —
 * l'état vit dans la meta du message assistant qui a présenté le plan.
 *
 * POURQUOI UNE CLASSE À PART plutôt qu'un discriminant dans PlanEnAttente. Le plan
 * d'écriture est relu par le chemin de code le plus délicat de l'application
 * (MutationPlan::fromArray, filtrage d'étendue, mot de passe, transaction,
 * journal). Y glisser une seconde nature pour un objet qui n'écrit AUCUNE donnée
 * métier polluerait ce chemin sans rien gagner : un document ne se filtre pas par
 * étapes, n'a pas d'impact de cascade et n'exige aucun mot de passe.
 *
 * MAIS LE VERROU, LUI, EST COMMUN — {@see aUneDecisionEnAttente()}. Les deux natures
 * font apparaître une barre de décision dans le même fil ; en laisser deux
 * s'empiler imposerait à l'utilisateur de trancher en série, ce que le verrou
 * historique interdit précisément.
 */
final class DocumentEnAttente
{
    /** Directive UI qui fait apparaître la barre « Valider et produire ». */
    public const ACTION_REVUE = TypeAction::DOCUMENT_A_VALIDER->value;

    /** Clés portées par la meta du message qui présente le plan. */
    public const CLE_PLAN = 'documentPlan';
    public const CLE_PRODUIT = 'documentPlanExecuted';
    public const CLE_ANNULE = 'documentPlanCancelled';

    /** Descriptif du document RÉELLEMENT produit (bouton de téléchargement, F5). */
    public const CLE_RESULTAT = 'documentGenere';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function porteUnPlan(array $meta): bool
    {
        return ($meta[self::CLE_PLAN] ?? null) !== null;
    }

    /** @param array<string, mixed> $meta */
    public static function estProduit(array $meta): bool
    {
        return self::porteUnPlan($meta) && ($meta[self::CLE_PRODUIT] ?? false) === true;
    }

    /** @param array<string, mixed> $meta */
    public static function estAnnule(array $meta): bool
    {
        return self::porteUnPlan($meta) && ($meta[self::CLE_ANNULE] ?? false) === true;
    }

    /** @param array<string, mixed> $meta */
    public static function estEnAttente(array $meta): bool
    {
        return self::porteUnPlan($meta) && !self::estProduit($meta) && !self::estAnnule($meta);
    }

    /**
     * Ce qu'il faut STOCKER côté serveur pour pouvoir produire plus tard : la spec
     * intégrale, le format retenu, le budget et l'aperçu autoritaire.
     *
     * La SPEC est le point capital. Elle est figée ici, à l'instant où le budget est
     * annoncé ; la production ne fera plus que la rendre. C'est ce qui garantit que
     * le document livré est exactement celui qui a été chiffré — sans un seul appel
     * de plus au modèle.
     *
     * @param array<int, array> $actions
     */
    public static function planStockable(array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::ACTION_REVUE && isset($action['spec'])) {
                return [
                    'spec'    => $action['spec'],
                    'format'  => $action['format'] ?? null,
                    'budget'  => $action['budget'] ?? null,
                    'apercu'  => $action['apercu'] ?? [],
                    'pied'    => $action['pied'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * Ne conserve que la PREMIÈRE directive de revue de document d'une réponse : un
     * message ne stocke qu'un plan, une seconde barre serait orpheline.
     *
     * @param array<int, array> $actions
     *
     * @return array<int, array>
     */
    public static function limiterAUnSeulPlan(array $actions): array
    {
        $vu = false;
        $retenues = [];
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::ACTION_REVUE) {
                if ($vu) {
                    continue;
                }
                $vu = true;
            }
            $retenues[] = $action;
        }

        return $retenues;
    }

    /** Le dernier message portant un plan de document encore à trancher, ou null. */
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

    /** Variante statique du verrou, sans EM. */
    public static function aUnPlanEnAttente(?AssistantConversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }

        foreach ($conversation->getMessages() as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT
                && self::estEnAttente($message->getMeta() ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * LE VERROU COMMUN : une décision — d'écriture OU de document — attend-elle
     * dans ce fil ?
     *
     * C'est cette question, et non « un plan d'écriture attend-il ? », que doivent
     * poser le garde-fou anti-plan fantôme et les outils qui préparent une barre de
     * décision. Sans elle, un vrai plan de document déclencherait l'avertissement
     * « aucun plan n'est en attente » — le garde-fou se retournerait contre le cas
     * qu'il est censé protéger.
     */
    public static function aUneDecisionEnAttente(?AssistantConversation $conversation): bool
    {
        return PlanEnAttente::aUnPlanEnAttente($conversation) || self::aUnPlanEnAttente($conversation);
    }

    /**
     * Annule le plan de document en attente (l'utilisateur en demande un autre).
     * Flush immédiat, pour la même raison que l'annulation d'un plan d'écriture :
     * la décision doit survivre même si la suite du tour échoue.
     */
    public function annulerLePlanEnAttente(?AssistantConversation $conversation): ?string
    {
        $message = $this->messageEnAttente($conversation);
        if ($message === null) {
            return null;
        }

        $meta = $message->getMeta() ?? [];
        $meta[self::CLE_ANNULE] = true;
        $message->setMeta($meta);
        $this->em->flush();

        return self::resume($meta[self::CLE_PLAN] ?? []);
    }

    /** Résumé lisible d'un plan stocké — de quoi on parle, sans tout réafficher. */
    public static function resume(array $plan): string
    {
        $titre = trim((string) (($plan['spec'] ?? [])['titre'] ?? ''));
        $format = DocumentFormat::depuis($plan['format'] ?? null);
        $cout = (int) (($plan['budget'] ?? [])['coutEstime'] ?? 0);

        return sprintf(
            '« %s » en %s, %d token%s',
            $titre !== '' ? $titre : 'document sans titre',
            $format->libelle(),
            $cout,
            $cout > 1 ? 's' : '',
        );
    }
}
