<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;
use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Onboarding\OnboardingCompletude;

/**
 * Outil de pilotage : L'ÉTAT DE CONFIGURATION DU CABINET.
 *
 * ── LE DÉTAIL DERRIÈRE LE RAPPEL ────────────────────────────────────────────────────
 * La boussole annonce la dette en une phrase et cite les deux manques les plus lourds ;
 * cet outil rend la liste complète, étape par étape, quand le courtier demande à voir.
 * Il DÉLÈGUE au même service que le voyant du workspace et que le bandeau du tableau de
 * bord : le chiffre que Ket énonce est celui qui est affiché à l'écran, par construction.
 *
 * ── IL NE SE CONTENTE PAS DE NOMMER LA DETTE ────────────────────────────────────────
 * Il porte une action d'interface qui ouvre le guide de démarrage. Dire à quelqu'un ce
 * qui lui manque sans lui tendre l'écran où le régler, c'est lui laisser tout le travail
 * de navigation — et c'est précisément ce que l'assistant existe pour éviter.
 *
 * ── FAIL-CLOSED PAR LA PROPRIÉTÉ, PAS PAR UN DROIT DE LECTURE ───────────────────────
 * Configurer le cabinet n'est pas une entité dont on lit les lignes : c'est une
 * prérogative du propriétaire. Un invité, même largement habilité, se voit répondre
 * « hors périmètre » — il ne pourrait rien faire de la réponse.
 */
final class EtatConfigurationTool implements AiToolInterface
{
    public function __construct(private readonly OnboardingCompletude $completude)
    {
    }

    public function name(): string
    {
        return 'etat_configuration';
    }

    public function description(): string
    {
        return 'État de configuration du cabinet : score de complétude en pourcentage et liste '
            . 'des paramètres encore manquants pour pouvoir travailler de la piste jusqu\'à '
            . 'l\'encaissement des commissions (assureurs, portefeuilles, comptes bancaires, taux '
            . 'de change, conditions de partage, intermédiaires, collaborateurs et leurs droits, '
            . 'classeurs, types de charges, fournisseurs, types de pièces sinistre, jours fériés, '
            . 'paramètres de congé, régimes de travail). Chaque étape porte son poids : bloquante '
            . '(sans elle la production s\'arrête), structurante ou de confort. '
            . 'À appeler quand l\'utilisateur demande où en est la configuration de son cabinet, '
            . 'ce qu\'il lui reste à paramétrer, pourquoi il ne peut pas créer une cotation ou une '
            . 'police, ou quand la boussole signale une configuration incomplète. '
            . 'RÉSERVÉ AU PROPRIÉTAIRE du cabinet. '
            . 'Les catalogues semés d\'office à la création (monnaies, taxes, types de revenu, '
            . 'types de chargement, risques, groupes, types d\'absence) n\'y figurent PAS : ils '
            . 'existent déjà, il n\'y a rien à y faire.';
    }

    public function aiguillage(): string
    {
        return '« où en est la configuration de mon cabinet ? », « qu\'est-ce qu\'il me reste à '
            . 'paramétrer ? », « pourquoi je ne peux pas créer de police ? ».';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'seulement_restantes' => [
                    'type' => 'boolean',
                    'description' => 'Ne rendre que les étapes non faites (défaut : false, tout est rendu).',
                ],
            ],
            'required' => [],
        ];
    }

    /** Chemin simulé : « configuration », « paramétrage », « qu'est-ce qu'il me manque ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match(
            '/\b(configuration du cabinet|etat de (la )?configuration|parametrage|parametres manquants|qu(e|\')est ce qu(e|\')il me manque|reste a configurer|demarrage du cabinet)\b/',
            $normalized
        )) {
            return [];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        if ($scope->invite->isProprietaire() !== true) {
            return AiToolResult::horsPerimetre('Configuration du cabinet');
        }

        $bilan = $this->completude->pour($scope->entreprise);
        $seulementRestantes = (bool) ($args['seulement_restantes'] ?? false);

        $etapes = $seulementRestantes ? $bilan['restantes'] : $bilan['etapes'];

        // On ne renvoie au modèle que ce dont il a besoin pour parler : ni les canevas,
        // ni les aperçus de l'existant. Le quota se mesure en tokens d'ENTRÉE, et une
        // liste de noms d'assureurs déjà saisis n'aiderait pas à dire ce qui manque.
        $lignes = array_map(static fn (array $etape): array => [
            'etape' => $etape['libelle'],
            'rubrique' => $etape['bloc'],
            'fait' => $etape['fait'],
            'enregistres' => $etape['nombre'],
            'importance' => match ($etape['poids']) {
                3 => 'bloquant',
                2 => 'structurant',
                default => 'confort',
            },
        ], $etapes);

        return AiToolResult::ok(
            [
                'score' => $bilan['score'],
                'complet' => $bilan['complet'],
                'restantes' => count($bilan['restantes']),
                'etapes' => array_values($lignes),
                'note' => $bilan['complet']
                    ? 'Le cabinet est entièrement configuré : ne suggère aucun paramétrage.'
                    : 'Cite les étapes BLOQUANTES en premier — ce sont elles qui empêchent de '
                        . 'travailler. Le guide de démarrage s\'ouvre par le bouton proposé : '
                        . 'chaque étape s\'y règle sans quitter l\'écran.',
            ],
            // Aucun bouton quand il n'y a rien à régler : proposer d'ouvrir un guide vide
            // ferait douter l'utilisateur de ce qu'on vient de lui annoncer.
            $bilan['complet'] ? null : [
                'type' => TypeAction::OUVRIR_RUBRIQUE->value,
                'entite' => 'Onboarding',
            ],
        );
    }
}
