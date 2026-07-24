<?php

namespace App\Ai\Tool;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Parcours\ParcoursBuilder;
use App\Ai\Parcours\ParcoursCatalogue;
use App\Ai\Scope\AiScope;

/**
 * Outil de GUIDAGE : décrit le PARCOURS complet de saisie d'un objet métier —
 * l'entité de base ET tout ce qui gravite autour (composition de la prime,
 * échéancier, rémunération, contrat…), étape par étape, avec les questions à
 * poser et le gabarit exact à recopier dans preparer_operations.
 *
 * Raison d'être : sans lui, Ket découvrait le métier au fil de l'eau et
 * enchaînait des plans successifs — un plan et une validation par entité. Avec
 * lui, elle expose le chemin ENTIER d'emblée, l'utilisateur dit jusqu'où il veut
 * aller et de quelles informations il dispose, et TOUT part dans UN SEUL plan,
 * avec UN SEUL budget et UNE SEULE validation.
 *
 * N'écrit rien. FAIL-CLOSED : les étapes hors des droits de l'invité ne sont pas
 * proposées (elles sont signalées comme indisponibles).
 */
final class ParcoursSaisieTool implements AiToolInterface
{
    public function __construct(
        private readonly ParcoursBuilder $builder,
    ) {
    }

    public function name(): string
    {
        return 'parcours_saisie';
    }

    public function description(): string
    {
        return 'AVANT toute création un peu structurante (une cotation, un client, un contrat, un '
            . 'sinistre…), appelle cet outil : il renvoie le PARCOURS complet — les étapes du métier '
            . 'dans l\'ordre, ce qu\'il faut demander à chaque étape, et le gabarit EXACT à recopier '
            . 'dans preparer_operations. Sujets rédigés : ' . implode(', ', ParcoursCatalogue::slugs())
            . ' ; toute autre entité de la liste reçoit un parcours dérivé de son formulaire. '
            . 'MODE D\'EMPLOI (impératif) : (1) présente le parcours ENTIER en UN SEUL message, avec '
            . 'les questions de chaque étape ; (2) demande à l\'utilisateur JUSQU\'OÙ il veut aller et '
            . 'de quelles informations il dispose DÉJÀ ; (3) une étape sans information est simplement '
            . 'IGNORÉE ; (4) assemble ensuite UN SEUL appel preparer_operations couvrant TOUT ce qu\'il '
            . 'a accepté — jamais un plan par étape. N\'écrit rien.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sujet' => [
                    'type' => 'string',
                    'description' => 'Parcours rédigé (' . implode(', ', ParcoursCatalogue::slugs())
                        . ') OU nom court de l\'entité de départ (' . implode(', ', MutationAllowlist::membres()) . ').',
                ],
            ],
            'required' => ['sujet'],
        ];
    }

    /** Réservé au LLM réel (aucun routage par mots-clés). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $sujet = trim((string) ($args['sujet'] ?? ''));
        if ($sujet === '') {
            return AiToolResult::introuvable('parcours');
        }

        $parcours = $this->builder->construire($sujet, $scope);
        if ($parcours === null) {
            return AiToolResult::horsPerimetre($sujet);
        }

        return AiToolResult::ok($parcours + [
            'catalogue' => ParcoursCatalogue::catalogue(),
            'note'      => 'Présente ce parcours ENTIER en UN SEUL message : la liste numérotée des étapes '
                . '(libellé · rôle · ce que tu dois demander), en signalant celles que tu remplis toi-même '
                . '(groupe « auto »). Termine par UNE question unique : « jusqu’où souhaitez-vous aller, et '
                . 'de quelles informations disposez-vous maintenant ? ». Recueille TOUTES les réponses, puis '
                . 'appelle preparer_operations UNE SEULE FOIS, en recopiant les gabarits des étapes retenues '
                . '(champ « etape » = le libellé de l’étape, pour que l’utilisateur puisse encore en décocher '
                . 'avant d’exécuter). N’enchaîne JAMAIS plusieurs plans à valider l’un après l’autre : une '
                . 'étape sans information est ignorée, et l’utilisateur pourra la reprendre plus tard.',
        ]);
    }
}
