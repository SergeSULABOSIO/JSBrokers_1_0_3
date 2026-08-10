<?php

namespace App\Ai\Trousse;

use App\Ai\AiRequest;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;

/**
 * Choisit la TROUSSE d'un message — côté SERVEUR, sans rien demander au modèle.
 *
 * POURQUOI PLUS D'APPEL DE ROUTAGE. Un aiguilleur qui interroge le modèle est un
 * TROISIÈME appel, et la règle n'en tolère que deux : planifier, puis rédiger.
 * Le choix des outils appartient de toute façon à la planification elle-même —
 * demander d'abord « de quels outils vas-tu avoir besoin ? », puis les lui donner,
 * c'était poser deux fois la même question.
 *
 * CE QUE COÛTE UNE ERREUR, ET POURQUOI ON PENCHE TOUJOURS DU MÊME CÔTÉ. Une
 * sélection trop LARGE coûte des tokens sur un seul appel. Une sélection trop
 * ÉTROITE prive l'utilisateur d'une capacité et lui fait entendre « je ne peux
 * pas » — le refus que le prompt interdit précisément à Ket de formuler. Les deux
 * prix ne sont pas du même ordre : dans le doute, on élargit.
 *
 * D'où une règle simple et lisible : on ouvre l'écriture dès qu'un indice la
 * suggère, et on ne la ferme que sur une demande manifestement de consultation.
 */
final class SelecteurDeTrousse
{
    /**
     * Verbes et tournures par lesquels un courtier demande d'AGIR sur ses données.
     *
     * Cette liste ne prétend pas être exhaustive — aucune ne le serait. Elle n'a
     * pas à l'être : ce qu'elle rate part en trousse de lecture, et une demande
     * d'écriture mal aiguillée se rattrape par un nouveau message, pas par un
     * troisième appel.
     */
    private const VERBES_ACTION = '/(cr[ée]e|cr[ée]er|ajoute|ajouter|enregistr|saisi|sauvegard|rempli|'
        . 'modifi|corrig|rectifi|change|mets? à jour|supprim|efface|renouvel|reconduis|reconduir|'
        . 'prorog|prolong|annul|r[ée]sili|marque|signale|affecte|attribue|valide|valider|souscri|'
        . 'fais-le|fais le|vas.?y|essaie|essaye|refais|r[ée]essaie|continue|poursui|'
        // FORMULAIRE ET ÉDITION, en mots NUS. La tournure exacte « ouvre le formulaire »
        // était trop étroite d'un cheveu : « ouvre-moi par exemple le formulaire d'édition
        // pour Olea » ne la déclenchait pas, la trousse de lecture partait sans
        // ouvrir_dialogue, et Ket a ouvert la RUBRIQUE des partenaires — une liste, là où
        // l'utilisateur demandait un formulaire (incident du 2026-08-10). Un message qui
        // parle de formulaire ou d'édition demande à saisir : c'est le côté du doute où
        // l'on penche, ici comme partout dans ce fichier.
        . 'formulaire|[ée]dit|'
        . 'donne moi le plan|donne-moi le plan|pareil|par o[uù]|'
        // Le VOCABULAIRE DU MÉTIER, ajouté après mesure : « j'ai une offre venant de
        // SFA. Que faire ? » ne contient aucun verbe d'action, et annonce pourtant
        // une saisie. Ces trois mots — offre, cotation, proposition — ouvraient les
        // seuls faux négatifs du corpus.
        . 'offre|cotation|proposition|devis|accord|mission|que faire|comment (faire|proc[ée]der)|'
        . 'r[ée]ponds|traite|prends en charge)/iu';

    public function __construct(
        private readonly ProgrammeEnCours $programmeEnCours,
        // Source unique de l'appartenance d'un outil à l'écriture — la même que
        // celle qui décide des déclarations envoyées au fournisseur.
        private readonly TrousseCatalogue $catalogue,
    ) {
    }

    public function trousseDe(AiRequest $requete): Trousse
    {
        $conversation = $requete->scope->conversation;

        // Un plan attend une décision, ou une série est en cours : la suite est
        // forcément une écriture. Rien à deviner.
        if (PlanEnAttente::aUnPlanEnAttente($conversation)) {
            return Trousse::ECRITURE;
        }
        if ($this->programmeEnCours->courant($conversation) !== null) {
            return Trousse::ECRITURE;
        }
        // Une pièce jointe dans le fil sert presque toujours à saisir quelque chose.
        if ($conversation !== null && \count($conversation->getFichiers()) > 0) {
            return Trousse::ECRITURE;
        }

        // SIGNAL STRUCTUREL, et non lexical : le tour précédent a utilisé un outil
        // d'écriture. Une saisie engagée se poursuit — l'utilisateur répond à une
        // question, fournit un montant, dit « le taux est de 15 % ». Aucun verbe
        // d'action là-dedans, et pourtant l'écriture continue. Mesuré : ce signal
        // n'ouvre aucun faux positif de plus, il est donc gratuit.
        if ($this->dernierTourAEcrit($conversation)) {
            return Trousse::ECRITURE;
        }

        // L'intention vit souvent dans le FIL et non dans la bulle (« vas y »,
        // « essaie encore ») : on lit donc les derniers échanges, pas le seul
        // dernier message.
        $recent = '';
        foreach (array_slice($requete->messages, -3) as $message) {
            $recent .= ' ' . (string) ($message['content'] ?? '');
        }

        return preg_match(self::VERBES_ACTION, $recent) === 1 ? Trousse::ECRITURE : Trousse::LECTURE;
    }

    /**
     * Le dernier tour de l'assistant s'est-il conclu par un outil d'ÉCRITURE ?
     *
     * L'appartenance est lue dans le catalogue (marqueur AiToolEcriture), jamais
     * dans une liste recopiée ici : ajouter un outil d'écriture suffit à ce que la
     * continuité de saisie le prenne en compte.
     */
    private function dernierTourAEcrit(?AssistantConversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }

        $dernier = null;
        foreach ($conversation->getMessages() as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT) {
                $dernier = $message;
            }
        }
        if ($dernier === null) {
            return false;
        }

        $outil = ($dernier->getMeta() ?? [])['tool'] ?? null;

        return is_string($outil) && $this->catalogue->estOutilDEcriture($outil);
    }
}
