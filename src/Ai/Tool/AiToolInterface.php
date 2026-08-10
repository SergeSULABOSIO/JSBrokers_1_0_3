<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;

/**
 * Outil de données de l'assistant IA. Deux contrats en un :
 *  - le contrat « LLM » (name/description/schema) : c'est ce qu'un futur bridge
 *    Symfony AI exposera au modèle comme tool-calling (schema = JSON-Schema des
 *    arguments) ;
 *  - le contrat « simulé » (match) : extraction d'arguments par mots-clés,
 *    utilisée uniquement par SimulatedAiEngine en attendant le vrai modèle.
 *
 * RÈGLE DE SÉCURITÉ NON NÉGOCIABLE : execute() DOIT vérifier lui-même le droit
 * de lecture de l'invité (WorkspaceAccessResolver::canRead, fail-closed) et le
 * scoping entreprise. La sécurité vit dans l'outil, jamais dans le prompt : le
 * jour où un LLM réel décide des appels, le périmètre reste garanti.
 */
interface AiToolInterface
{
    /** Identifiant technique de l'outil (snake_case) — tracé dans les meta des messages. */
    public function name(): string;

    /** Description destinée au modèle (à quoi sert l'outil, quand l'appeler). */
    public function description(): string;

    /**
     * RÈGLE D'AIGUILLAGE : « dans telle situation, appelle-moi ». Une ou deux
     * phrases, à la deuxième personne, reprenant les mots que l'utilisateur emploie
     * réellement. Chaîne vide quand la description suffit.
     *
     * Ces règles vivaient en prose dans le prompt système — 107 mentions d'outils
     * en dur, portant sur 30 des 33 outils. Dès lors que les déclarations envoyées
     * varient selon la trousse, cette prose devenait mensongère : elle nommait des
     * outils absents du tour, et le modèle finissait par « appeler » un outil
     * inexistant ou par recopier un plan sans bouton. La section d'aiguillage est
     * donc GÉNÉRÉE à partir des outils RÉELLEMENT déclarés — c'est ici, et nulle
     * part ailleurs, que chaque règle vit.
     *
     * CONTRAINTE : ne nomme jamais un outil d'une AUTRE trousse (un outil de lecture
     * ne renvoie pas vers un outil d'écriture, absent du tour). PromptSansOutilFantomeTest
     * échoue si la règle est violée.
     */
    public function aiguillage(): string;

    /** JSON-Schema des arguments (format tool-calling standard). */
    public function schema(): array;

    /**
     * Chemin simulé : la question déclenche-t-elle cet outil ? Renvoie les
     * arguments extraits, ou null si la question ne le concerne pas.
     */
    public function match(string $question, AiScope $scope): ?array;

    /** Exécute l'outil (contrôle d'accès + scoping entreprise inclus). */
    public function execute(array $args, AiScope $scope): AiToolResult;
}
