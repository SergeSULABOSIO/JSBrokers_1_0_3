<?php

namespace App\Ai\Comprehension;

use App\Ai\Action\TypeAction;
use App\Entity\AssistantConversation;

/**
 * SOURCE UNIQUE de l'état d'une CLARIFICATION dans un fil — le pendant, pour la
 * compréhension, de ce que PlanEnAttente est pour l'écriture.
 *
 * Trois règles y vivent, et nulle part ailleurs :
 *
 *  1. GARDE ANTI-BOUCLE. Si le tour précédent était déjà une clarification, le
 *     comprenant est court-circuité. Sans elle, un utilisateur dont la précision
 *     resterait imparfaite se verrait reposer la question indéfiniment — un
 *     dialogue de sourds bien pire que la mécompréhension d'origine.
 *
 *  2. GRATUITÉ VÉRIFIÉE. Confirmer une intention ne consomme pas de jeton : c'est
 *     NOTRE compréhension qu'on corrige, pas une demande neuve. Mais le drapeau
 *     vient du navigateur : sans contrôle, il serait un contournement de facturation
 *     forgeable à la main. La gratuité n'est donc accordée que si le fil PORTE
 *     réellement une clarification en attente — fail-closed, comme partout ailleurs.
 *
 *  3. SURVIE AU RECHARGEMENT. La barre de décision est reconstruite depuis la meta,
 *     exactement comme celles d'un plan ou d'un document.
 */
final class ClarificationEnAttente
{
    /** Type de l'action d'interface (barre « Oui, c'est bien ça » / « Non, je précise »). */
    public const ACTION = TypeAction::CLARIFICATION->value;

    /** Clé de la meta du message assistant qui porte la clarification. */
    public const CLE_META = 'clarification';

    /**
     * L'action d'interface d'une demande à clarifier.
     *
     * `intention` repart telle quelle au serveur quand l'utilisateur confirme : c'est
     * ce qui lui évite de retaper sa demande, et ce qui garantit que le cycle suivant
     * planifie sur le texte EXACT qu'il vient d'approuver.
     *
     * @return array<string, mixed>
     */
    public static function action(DemandeComprise $comprise): array
    {
        return [
            'type'          => self::ACTION,
            'intention'     => $comprise->intention,
            'questions'     => $comprise->questions,
        ];
    }

    /**
     * La clarification à stocker en meta, extraite des actions du tour — ou null.
     *
     * @param array<int, array> $actions
     *
     * @return array<string, mixed>|null
     */
    public static function stockable(array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::ACTION) {
                return [
                    'intention' => (string) ($action['intention'] ?? ''),
                    'questions' => array_values((array) ($action['questions'] ?? [])),
                ];
            }
        }

        return null;
    }

    /**
     * Le dernier tour de l'assistant était-il une demande de clarification ?
     *
     * Un seul test pour les trois règles ci-dessus : c'est ce qui garantit qu'on ne
     * peut pas court-circuiter le comprenant sans, du même coup, être en droit de ne
     * pas facturer — et réciproquement.
     */
    public static function enAttente(?AssistantConversation $conversation): bool
    {
        $dernier = $conversation?->dernierMessageAssistant();

        return is_array(($dernier?->getMeta() ?? [])[self::CLE_META] ?? null);
    }
}
