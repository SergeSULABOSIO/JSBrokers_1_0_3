<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;

/**
 * « J'AI BESOIN DE MES OUTILS » — le seul outil de la trousse minimale.
 *
 * ── POURQUOI IL EXISTE ──────────────────────────────────────────────────────────
 *
 * Un message sur trois n'appelle aucun outil. Beaucoup sont des acquiescements
 * (« ok », « merci », « très bien ») auxquels Ket répond en une phrase — mais qui
 * emportaient jusqu'ici les 64 Ko de déclarations de la trousse de lecture, payés
 * pour rien à chaque fois.
 *
 * La trousse `AUCUN` ne déclare que cet outil-ci. Ket peut donc répondre, et si elle
 * s'aperçoit qu'elle avait besoin de données, elle l'appelle : le tour suivant repart
 * avec la trousse normale. Un aiguillage trop serré coûte alors UN tour de recours ;
 * sans cette porte, il coûterait une réponse « je ne peux pas » sur une demande
 * parfaitement légitime — et c'est la seule erreur qu'on ne peut pas se permettre.
 *
 * ── CE QU'IL NE FAIT PAS ────────────────────────────────────────────────────────
 *
 * Il ne lit rien, n'écrit rien et n'ouvre rien. Il n'a donc AUCUN droit à vérifier au
 * delà du scope, et c'est précisément ce qui le rend sûr comme unique porte de sortie :
 * l'élargissement qu'il déclenche se fait côté serveur, par le sélecteur de trousse, et
 * la trousse ainsi rendue repasse par tous les filtres habituels — droits, périmètre,
 * outils coupés en console.
 *
 * ── LE COMPTEUR QU'IL PORTE ─────────────────────────────────────────────────────
 *
 * Chaque appel est un aveu que l'aiguillage s'est trompé. C'est l'indicateur de
 * non-régression du lot : « réponses "je ne peux pas" dues à une trousse trop étroite ».
 * S'il monte, c'est le déclencheur qu'il faut resserrer, pas cette porte qu'il faut
 * fermer.
 */
final class RecoursOutilsTool implements AiToolInterface
{
    public function name(): string
    {
        return 'reprendre_mes_outils';
    }

    public function description(): string
    {
        return 'À appeler UNIQUEMENT si répondre exige des données du cabinet et qu\'aucun outil '
            . 'ne t\'est déclaré pour les obtenir. Le tour suivant te rendra ta trousse complète. '
            . 'N\'appelle pas cet outil pour une réponse que le fil contient déjà.';
    }

    public function aiguillage(): string
    {
        return 'seulement si la réponse exige des données et qu\'aucun autre outil n\'est déclaré.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'besoin' => [
                    'type' => 'string',
                    // CE QU'IL LUI FAUT, EN CLAIR. Non pour agir dessus — le serveur rend la
                    // trousse entière quoi qu'il arrive — mais pour que le journal dise QUELLES
                    // demandes l'aiguillage a mal jugées. Sans cette phrase, on saurait qu'il
                    // s'est trompé, jamais sur quoi.
                    'description' => 'En une phrase, la donnée qui te manque.',
                ],
            ],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        // Le moteur simulé ne doit JAMAIS le choisir : il n'est pas une réponse à une
        // question, mais une porte de sortie que seul le vrai modèle peut vouloir pousser.
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        return AiToolResult::ok([
            'trousse_rendue' => true,
            'besoin'         => trim((string) ($args['besoin'] ?? '')),
            'consigne'       => 'Tes outils te sont rendus. Reformule ton appel au tour suivant.',
        ]);
    }
}
