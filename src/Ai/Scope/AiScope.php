<?php

namespace App\Ai\Scope;

use App\Entity\AssistantConversation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Terminal\Terminal;

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
        /**
         * Le terminal depuis lequel la question a été posée.
         *
         * ⚠ CE N'EST PAS UN DROIT, C'EST UNE CAPACITÉ D'AFFICHAGE. Le reste de ce
         * scope dit ce que l'invité a le droit de voir ; ce champ dit seulement ce
         * que son appareil est capable de montrer. Un téléphone n'a pas
         * d'interface à colonnes : les outils qui ouvrent une rubrique ou une
         * fiche n'y ont donc aucun sens, et `AiToolConditionnel::estDisponible()`
         * s'en sert pour ne pas les déclarer au modèle — qui ne peut alors pas
         * promettre un écran qui n'arrivera jamais.
         *
         * Aucune garde de sécurité ne doit dépendre de ce champ : il est déduit
         * d'un `User-Agent` et d'un cookie, tous deux modifiables par le client.
         *
         * Facultatif, et `ORDINATEUR` par défaut : c'est le mode qui n'enlève
         * rien, donc le comportement d'avant pour tous les appels existants
         * (tests, imports, ligne de commande).
         */
        public readonly Terminal $terminal = Terminal::ORDINATEUR,
    ) {
    }
}
