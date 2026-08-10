<?php

namespace App\Ai\Trousse;

use App\Ai\AiRequest;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;

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
        . 'ouvre le formulaire|donne moi le plan|donne-moi le plan|pareil|par o[uù] commencer)/iu';

    public function __construct(
        private readonly ProgrammeEnCours $programmeEnCours,
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

        // L'intention vit souvent dans le FIL et non dans la bulle (« vas y »,
        // « essaie encore ») : on lit donc les derniers échanges, pas le seul
        // dernier message.
        $recent = '';
        foreach (array_slice($requete->messages, -3) as $message) {
            $recent .= ' ' . (string) ($message['content'] ?? '');
        }

        return preg_match(self::VERBES_ACTION, $recent) === 1 ? Trousse::ECRITURE : Trousse::LECTURE;
    }
}
