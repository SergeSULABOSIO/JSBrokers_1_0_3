<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil d'ACTION UI : FERME un ou plusieurs onglets de rubrique du workspace —
 * « ferme la rubrique Monnaie », « ferme Clients et Tranches », « ferme tous
 * les onglets ».
 *
 * POURQUOI IL EXISTE (incident du 2026-08-10). « Ferme la rubrique Monnaie et
 * Clients stp » : aucun outil ne savait fermer quoi que ce soit. Le modèle a
 * fait ce qu'il pouvait — il a ouvert le TABLEAU DE BORD, qui ne ferme rien —
 * puis a annoncé « les rubriques ont été fermées. Vous êtes revenu sur le
 * tableau de bord ». L'utilisateur a répondu « elles ne sont pas fermées ! » et
 * a reçu la MÊME affirmation, reformulée. Deux tours, deux promesses, zéro
 * onglet fermé.
 *
 * La leçon est celle du registre TypeAction : une capacité qui manque se comble
 * par du code. Aucune consigne de prompt n'aurait fermé cet onglet — au mieux
 * elle aurait appris à Ket à dire qu'elle ne savait pas le faire.
 *
 * PLUSIEURS RUBRIQUES D'UN COUP, ET C'EST VOULU. L'utilisateur en nomme
 * naturellement deux ou trois dans la même phrase, et le message ne dispose que
 * d'UN tour d'outils : les traiter une par une aurait reproduit la panne sous
 * une autre forme.
 *
 * PAS DE GARDE DE PÉRIMÈTRE, ET C'EST DÉLIBÉRÉ. Fermer un onglet ne divulgue
 * rien et ne modifie aucune donnée : c'est le geste le plus inoffensif de
 * l'interface. Refuser de fermer la rubrique d'une entité qu'on n'a pas le
 * droit de LIRE serait absurde — cet onglet ne serait pas ouvert.
 */
final class FermerRubriqueTool implements AiToolInterface
{
    /** Mot-clé de la fermeture totale, accepté partout où une entité est attendue. */
    public const TOUTES = 'Toutes';

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntiteLexique $lexique,
    ) {
    }

    public function name(): string
    {
        return 'fermer_rubrique';
    }

    public function description(): string
    {
        return "Ferme dans l'espace de travail un ou plusieurs ONGLETS de rubrique déjà ouverts "
            . '(« ferme la rubrique Monnaie », « ferme Clients et Tranches », « ferme tout »). '
            . 'Le paramètre « entites » accepte PLUSIEURS noms courts d\'un coup — nomme-les TOUS '
            . 'dans un seul appel quand l\'utilisateur en cite plusieurs, tu n\'auras pas de second '
            . 'tour. « Toutes » ferme l\'ensemble des onglets ouverts. C\'est le SEUL outil qui '
            . 'ferme quelque chose : ouvrir le tableau de bord ne ferme AUCUN onglet, et l\'annoncer '
            . 'serait faux.';
    }

    public function aiguillage(): string
    {
        return '« ferme la rubrique X », « ferme X et Y », « ferme cet onglet », « ferme tout » : fermer des '
            . 'onglets déjà ouverts. N\'utilise JAMAIS ouvrir_rubrique pour cela — ouvrir le tableau de bord '
            . 'ne ferme rien, et affirmer le contraire est un mensonge que l\'utilisateur voit à l\'écran.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entites' => [
                    'type' => 'array',
                    'description' => "Noms courts des rubriques à fermer (ex. [\"Client\", \"Monnaie\"]). "
                        . 'Cite-les TOUTES en un seul appel. « ' . self::TOUTES . ' » ferme tous les '
                        . 'onglets ouverts, « TableauDeBord » ferme celui du tableau de bord.',
                    'items' => [
                        'type' => 'string',
                        'enum' => $this->valeursAcceptees(),
                    ],
                ],
            ],
            'required' => ['entites'],
        ];
    }

    /** Chemin simulé : « ferme la rubrique X » — une seule entité, la phrase n'en porte qu'une. */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(ferme[rsz]?|fermer|quitte[rsz]?)\b/', $normalized)) {
            return null;
        }
        if (preg_match('/\b(tout|toutes|tous)\b/', $normalized)) {
            return ['entites' => [self::TOUTES]];
        }

        $shortName = $this->lexique->matchEntite($normalized);

        return $shortName === null ? null : ['entites' => [$shortName]];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $acceptees = $this->valeursAcceptees();
        $labels = $this->accessResolver->libellesEntites();

        $retenues = [];
        $ignorees = [];
        foreach ((array) ($args['entites'] ?? []) as $demandee) {
            $demandee = trim((string) $demandee);
            if ($demandee === '') {
                continue;
            }
            if (in_array($demandee, $acceptees, true)) {
                $retenues[$demandee] = true;
                continue;
            }
            $ignorees[] = $demandee;
        }
        $retenues = array_keys($retenues);

        if ($retenues === []) {
            return AiToolResult::introuvable(implode(', ', $ignorees));
        }

        // « Toutes » absorbe le reste : demander « ferme tout et aussi les clients »
        // ne doit pas produire deux ordres qui se chevauchent.
        if (in_array(self::TOUTES, $retenues, true)) {
            $retenues = [self::TOUTES];
        }

        $libelles = array_map(
            static fn (string $e): string => match ($e) {
                self::TOUTES   => 'toutes les rubriques ouvertes',
                'TableauDeBord' => 'Tableau de bord',
                default        => $labels[$e] ?? $e,
            },
            $retenues,
        );

        return AiToolResult::ok(
            array_filter([
                'entites'  => $retenues,
                'libelles' => $libelles,
                'ignorees' => $ignorees !== [] ? $ignorees : null,
                // NE PROMETS PAS CE QUE TU NE SAIS PAS. Le serveur ignore quels onglets
                // sont ouverts dans le navigateur : il DEMANDE la fermeture, il ne la
                // constate pas. Un onglet qui n'était pas ouvert n'a rien à fermer, et
                // annoncer « fermé » dans ce cas relancerait exactement l'incident.
                'note'     => 'La fermeture est demandée à l’espace de travail pour : '
                    . implode(', ', $libelles) . '. Annonce-la au PASSÉ et sans détour '
                    . '(« C’est fermé. »), sans énumérer d’onglet que l’utilisateur n’a pas '
                    . 'nommé et SANS prétendre l’avoir ramené au tableau de bord : fermer '
                    . 'un onglet n’en ouvre aucun autre.',
            ], static fn ($v) => $v !== null),
            uiAction: [
                'type'    => TypeAction::FERMER_RUBRIQUE->value,
                'entites' => $retenues,
            ],
        );
    }

    /** @return list<string> */
    private function valeursAcceptees(): array
    {
        return array_merge([self::TOUTES, 'TableauDeBord'], $this->lexique->nomsCourts());
    }
}
