<?php

namespace App\Ai\Scope;

use App\Entity\AssistantConversation;
use App\Entity\Entreprise;
use App\Entity\Invite;

/**
 * Périmètre d'exécution d'une requête à l'assistant IA : l'entreprise active,
 * l'invité qui pose la question, et la CONVERSATION en cours. TOUT accès aux
 * données (outils) doit être vérifié contre ce scope — jamais contre le texte
 * du prompt.
 *
 * La conversation est portée jusqu'aux outils parce que certains ont besoin de
 * l'ÉTAT du fil, pas seulement des droits : le verrou qui interdit de préparer
 * un second plan d'écriture tant que le premier attend une décision de
 * l'utilisateur (cf. App\Ai\Mutation\PlanEnAttente). Elle est facultative — un
 * appel d'outil hors conversation (test, exécution différée) reste valide, et
 * le verrou est alors simplement inopérant.
 */
final class AiScope
{
    public function __construct(
        public readonly Entreprise $entreprise,
        public readonly Invite $invite,
        public readonly ?AssistantConversation $conversation = null,
    ) {
    }
}
