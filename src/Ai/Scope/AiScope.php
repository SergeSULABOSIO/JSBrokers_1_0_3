<?php

namespace App\Ai\Scope;

use App\Entity\Entreprise;
use App\Entity\Invite;

/**
 * Périmètre d'exécution d'une requête à l'assistant IA : l'entreprise active et
 * l'invité qui pose la question. TOUT accès aux données (outils) doit être
 * vérifié contre ce scope — jamais contre le texte du prompt.
 */
final class AiScope
{
    public function __construct(
        public readonly Entreprise $entreprise,
        public readonly Invite $invite,
    ) {
    }
}
