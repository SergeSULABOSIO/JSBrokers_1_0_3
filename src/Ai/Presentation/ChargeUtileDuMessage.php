<?php

namespace App\Ai\Presentation;

use App\Ai\Document\DocumentEnAttente;
use App\Ai\Mutation\PlanEnAttente;
use App\Entity\AssistantMessage;
use App\Entity\AssistantTache;

/**
 * @file Ce que le navigateur reçoit après un envoi de message.
 * @description Source UNIQUE de la charge utile d'un échange (question +
 * réponse), reconstruite depuis les entités persistées.
 *
 * POURQUOI DEPUIS LES ENTITÉS, ET NON DEPUIS LES VARIABLES DU TRAITEMENT. Le
 * traitement n'est plus forcément le voisin immédiat de la réponse HTTP : quand
 * il part en tâche de fond, c'est un endpoint d'état — donc une AUTRE requête,
 * dans un autre processus — qui doit rendre exactement la même chose. Une charge
 * utile reconstructible depuis la base est la seule qui puisse être servie deux
 * fois sans diverger.
 *
 * ⚠️ RECONSTRUCTION ET array_filter. La meta du message est écrite à travers un
 * array_filter qui écarte les valeurs vides : `refus: false` et `actions: []`
 * n'y figurent donc PAS. Les restituer (`?? false`, `?? []`) n'est pas une
 * précaution de style — sans cela, le contrat du navigateur change de forme
 * selon le contenu. ChargeUtileDuMessageTest verrouille cette équivalence,
 * clé par clé.
 *
 * CE QUI N'EST PAS UNIFIÉ ICI, ET POURQUOI. Le rendu Twig du chat reconstruit
 * lui aussi chaque bulle après un rechargement de page — mais en HTML, avec des
 * attributs data-*, pas en JSON. Les deux chemins ne partagent pas leur sortie ;
 * ils partagent leur SOURCE, la meta, elle-même écrite par des fabriques
 * communes (PlanEnAttente::planStockable, DocumentEnAttente::planStockable,
 * ClarificationEnAttente::stockable). La source unique est la meta, pas ce
 * tableau.
 */
final class ChargeUtileDuMessage
{
    /**
     * Un échange complet, tel que l'attend le contrôleur Stimulus du chat.
     *
     * $messageAssistant est nul tant que le traitement n'a pas rendu sa réponse :
     * la question, elle, est déjà persistée et affichable.
     *
     * @return array{user: array, assistant: array|null, conversationTitre: string|null}
     */
    public function pour(AssistantMessage $messageUser, ?AssistantMessage $messageAssistant): array
    {
        return [
            'user'              => $this->question($messageUser),
            'assistant'         => $messageAssistant === null ? null : $this->reponse($messageAssistant),
            'conversationTitre' => $messageUser->getConversation()?->libelle(),
        ];
    }

    /**
     * L'accusé de réception : la question est enregistrée, la réponse viendra.
     *
     * Même forme que la charge utile complète — le navigateur affiche sa bulle
     * de la même façon dans les deux régimes, et n'a donc qu'un seul code
     * d'affichage. `tache` lui dit quoi suivre.
     *
     * `user.id` est NUL ici, et ce n'est pas un oubli : le message n'entrera
     * dans le fil qu'au drainage, pour que son identifiant se place juste avant
     * celui de sa réponse (voir AssistantTache). C'est l'identifiant de TÂCHE
     * qui sert de prise au navigateur jusque-là ; l'identifiant de message
     * arrive avec la réponse.
     *
     * @return array{user: array, tache: array, conversationTitre: string|null}
     */
    public function acceptation(AssistantTache $tache): array
    {
        return [
            'user'              => $this->questionEnAttente($tache),
            'tache'             => $this->tache($tache),
            'conversationTitre' => $tache->getConversation()?->libelle(),
        ];
    }

    /**
     * La question telle qu'elle a été acceptée, avant d'entrer dans le fil.
     * Mêmes clés que `question()` : le navigateur ne distingue pas les deux cas.
     */
    private function questionEnAttente(AssistantTache $tache): array
    {
        $repondA = $tache->getRepondA();

        return [
            'id'             => $tache->getMessageUtilisateur()?->getId(),
            'contenu'        => $tache->getContenu(),
            'contexteObjets' => $tache->getContexteObjets(),
            'fichiersJoints' => $tache->getFichiersJoints(),
            'citation'       => $repondA === null ? null : [
                'id'      => $repondA->getId(),
                'role'    => $repondA->getRole(),
                'extrait' => $repondA->extraitCitation(),
            ],
            'createdAt'      => $tache->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
        ];
    }

    /**
     * L'état d'une tâche, tel que le suit le navigateur.
     *
     * AUTOSUFFISANTE : elle porte la question ET, dès qu'elle existe, la réponse.
     * Le scrutin n'a donc rien à recouper — il applique le même code d'affichage
     * que le chemin synchrone, sur les mêmes clés.
     *
     * @return array{id: int|null, statut: string, etape: array|null, user: array, assistant: array|null}
     */
    public function tache(AssistantTache $tache): array
    {
        $messageUtilisateur = $tache->getMessageUtilisateur();
        $messageAssistant = $tache->getMessageAssistant();

        return [
            'id'        => $tache->getId(),
            'statut'    => $tache->getStatut(),
            // Format brut de JournalTokens : le navigateur en dérive le libellé
            // avec verbeEtape()/compteurEtape(), les mêmes fonctions qu'au temps
            // du flux. Aucune traduction entre les deux bouts.
            'etape'     => $tache->getEtape(),
            // Tant que la question n'est pas entrée dans le fil, elle est lue sur
            // la tâche — mêmes clés, le navigateur ne distingue pas les deux cas.
            'user'      => $messageUtilisateur === null
                ? $this->questionEnAttente($tache)
                : $this->question($messageUtilisateur),
            'assistant' => $messageAssistant === null ? null : $this->reponse($messageAssistant),
        ];
    }

    /**
     * @return array{id: int|null, contenu: string|null, contexteObjets: mixed, fichiersJoints: mixed, citation: array|null, createdAt: string|null}
     */
    private function question(AssistantMessage $messageUser): array
    {
        $repondA = $messageUser->getRepondA();

        return [
            'id'             => $messageUser->getId(),
            'contenu'        => $messageUser->getContenu(),
            'contexteObjets' => $messageUser->getContexteObjets(),
            'fichiersJoints' => $messageUser->getFichiersJoints(),
            // Contrat testable de la persistance de la citation (le front
            // affiche déjà sa bulle optimiste sans attendre cette valeur).
            'citation'       => $repondA === null ? null : [
                'id'      => $repondA->getId(),
                'role'    => $repondA->getRole(),
                'extrait' => $repondA->extraitCitation(),
            ],
            'createdAt'      => $messageUser->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @return array{id: int|null, contenu: string|null, refus: bool, actions: array, createdAt: string|null, activite: array|null}
     */
    private function reponse(AssistantMessage $messageAssistant): array
    {
        $meta = $messageAssistant->getMeta() ?? [];

        return [
            'id'        => $messageAssistant->getId(),
            'contenu'   => $messageAssistant->getContenu(),
            // array_filter a écarté `false` de la meta : sans ce défaut, la clé
            // disparaîtrait du contrat dès que la réponse n'est pas un refus.
            'refus'     => (bool) ($meta['refus'] ?? false),
            // Les actions renvoyées portent l'id du message : une action
            // ket-mutation.review sait ainsi vers quel endpoint d'exécution pointer.
            'actions'   => $this->actionsAvecMessage($meta['actions'] ?? [], (int) $messageAssistant->getId()),
            'createdAt' => $messageAssistant->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
            // Même contenu que la clé `activite` de meta : le front rend le
            // récapitulatif en direct, le Twig le rend après rechargement.
            'activite'  => $meta['activite'] ?? null,
        ];
    }

    /**
     * Recopie les actions en injectant l'id du message assistant dans l'action
     * ket-mutation.review (le front en dérive l'URL d'exécution).
     *
     * @param array<int, array> $actions
     */
    private function actionsAvecMessage(array $actions, int $idMessage): array
    {
        return array_map(static function (array $action) use ($idMessage) {
            if (in_array($action['type'] ?? null, [PlanEnAttente::ACTION_REVUE, DocumentEnAttente::ACTION_REVUE], true)) {
                $action['idMessage'] = $idMessage;
            }

            // La spec n'a rien à faire dans le navigateur : elle est volumineuse et
            // seul le serveur la relit (depuis la meta) pour produire. L'envoyer
            // n'apporterait qu'un aller-retour plus lourd et une tentation d'y
            // faire confiance.
            unset($action['spec'], $action['pied']);

            return $action;
        }, $actions);
    }
}
