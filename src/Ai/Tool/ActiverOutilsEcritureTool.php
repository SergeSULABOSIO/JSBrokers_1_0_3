<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Trousse;

/**
 * L'ESCALADE : le filet qui empêche qu'un aiguillage raté devienne un refus.
 *
 * Quand un message a été routé en LECTURE, les outils d'écriture ne sont pas
 * déclarés. Si l'utilisateur demande malgré tout une création ou une correction, le
 * modèle n'a rien à appeler — et il répondrait « je ne peux pas », en contradiction
 * frontale avec la règle qui lui interdit précisément de le dire. Cet outil lui donne
 * la seule réponse honnête : demander les outils qui manquent.
 *
 * Le moteur bascule alors la trousse pour les tours SUIVANTS du même message. Le coût
 * est d'un tour — et d'un tour léger, puisqu'il se paie au tarif de la trousse de
 * lecture. À comparer aux ~20 000 tokens économisés sur chaque tour de chaque message
 * de consultation.
 *
 * Il n'est déclaré QUE hors trousse d'écriture : une fois les outils d'écriture
 * présents, le réclamer n'aurait aucun sens.
 */
final class ActiverOutilsEcritureTool implements AiToolInterface
{
    /** Clé lue par le moteur dans la réponse pour basculer la trousse. */
    public const CLE_TROUSSE = 'trousse';

    public function name(): string
    {
        return 'activer_outils_ecriture';
    }

    public function description(): string
    {
        return "Met à ta disposition les outils d'ÉCRITURE (création, modification, suppression, "
            . "ouverture de formulaire, parcours de saisie), qui ne sont pas déclarés dans ce tour-ci. "
            . "Appelle-le dès que la demande suppose d'enregistrer ou de modifier quelque chose et que "
            . "tu ne trouves pas l'outil correspondant : ils seront disponibles au tour suivant, et tu "
            . 'pourras alors faire ce qui est demandé. Aucun effet sur les données.';
    }

    public function aiguillage(): string
    {
        return 'La demande suppose de créer, modifier, supprimer ou enregistrer quelque chose, mais '
            . 'aucun outil de ce genre ne figure dans la liste ci-dessus. Appelle-moi AU LIEU de '
            . 'répondre que tu ne peux pas : tu le peux, il te manque seulement les outils, et je te '
            . 'les donne. Ne préviens pas, n\'explique pas — appelle, puis agis au tour suivant.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    /**
     * N'exécute rien : il DÉCLARE un besoin. La bascule appartient au moteur, seul à
     * savoir ce qu'il enverra au tour suivant.
     */
    public function execute(array $args, AiScope $scope): AiToolResult
    {
        return AiToolResult::ok([
            self::CLE_TROUSSE => Trousse::ECRITURE->value,
            'note' => 'Les outils d\'écriture sont désormais à ta disposition. Poursuis MAINTENANT ce '
                . 'que l\'utilisateur a demandé en appelant l\'outil approprié — ne lui annonce pas que '
                . 'tu vas le faire, fais-le.',
        ]);
    }
}
