<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\AssistantMessage;
use App\Entity\Utilisateur;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;

/**
 * Outil de COMPTE : solde actuel de tokens de l'entreprise du scope, accompagné
 * d'un rappel de la logique de consommation JS Brokers (valeurs dynamiques du
 * plan tarifaire). La facturation étant rattachée au PROPRIÉTAIRE de
 * l'entreprise, c'est SON solde qui est restitué — l'épuisement bloque le
 * travail de toute l'équipe, le connaître aide chacun à anticiper.
 *
 * Sécurité : AUCUN argument — le solde est toujours celui de $scope->entreprise
 * (validée par le contrôleur), jamais d'une entreprise désignée par le modèle.
 * Pas de garde d'entité : donnée de compte hors cartes de permission, l'accès
 * au module assistant est déjà gaté en amont (AssistantIa + compte payant).
 * On ne révèle NI l'identité du propriétaire, NI les montants payés en USD.
 * L'outil ne mètre rien : lire son solde est gratuit (seul le message
 * englobant coûte son poids AssistantMessage).
 */
final class SoldeTokensTool implements AiToolInterface
{
    public function __construct(
        private readonly TokenAccountService $tokenAccountService,
        private readonly ParametresTokenService $parametres,
    ) {
    }

    public function name(): string
    {
        return 'solde_tokens';
    }

    public function description(): string
    {
        return "Donne le solde ACTUEL de tokens de l'entreprise (compte du propriétaire : prépayés, "
            . 'allocation gratuite, total, prochain renouvellement) et rappelle la logique de '
            . 'consommation des tokens chez JS Brokers. À appeler quand l\'utilisateur demande le '
            . 'solde, les crédits/tokens restants ou disponibles, ou comment les tokens se '
            . 'consomment. Aucun paramètre : le solde est toujours celui de l\'entreprise courante.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    /**
     * Chemin simulé : le mot « token(s) » seul suffit ; « crédits » n'est
     * retenu qu'immédiatement suivi de « restants/disponibles » pour ne pas
     * voler les questions métier (« solde du client X », « solde restant dû »
     * = données comptables).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\btokens?\b/', $normalized)
            || preg_match('/\bcredits? (restants?|disponibles?)\b/', $normalized)) {
            return [];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $owner = $scope->entreprise->getUtilisateur();
        if (!$owner instanceof Utilisateur) {
            return AiToolResult::introuvable('compte de tokens');
        }

        // getBalance() renouvelle au passage la fenêtre gratuite (lazy, voulu).
        $balance = $this->tokenAccountService->getBalance($owner);

        return AiToolResult::ok([
            'entreprise'                    => (string) $scope->entreprise->getNom(),
            'total'                         => $balance['total'],
            'prepayes'                      => $balance['paid'],
            'gratuits'                      => $balance['free'],
            'allocationGratuite'            => $balance['allowance'],
            'prochainRenouvellementGratuit' => $balance['nextRenewalAt']?->format('Y-m-d H:i'),
            'logiqueConsommation'           => $this->logiqueConsommation(),
        ]);
    }

    /**
     * Rappel de la logique de consommation, construit avec les valeurs
     * DYNAMIQUES du plan tarifaire (éditable en console, repli constantes).
     */
    private function logiqueConsommation(): string
    {
        return sprintf(
            'Chez JS Brokers, chaque échange de données consomme des tokens, toujours débités du '
            . 'solde du PROPRIÉTAIRE de l\'entreprise (toute l\'activité de l\'équipe est à sa '
            . 'charge). Lecture : %d token%s par enregistrement affiché ou exporté. Écriture '
            . '(création/modification) : poids variable selon le type de fiche (%d tokens par '
            . 'défaut). Assistant IA : %d tokens par message envoyé, %d tokens par objet attaché '
            . 'au contexte du chat. Mode gratuit : %d tokens offerts, renouvelés toutes les %d '
            . 'heures (non cumulables) ; les paquets prépayés, eux, se cumulent et sont consommés '
            . 'en premier. Solde épuisé : lectures et écritures sont bloquées jusqu\'à la recharge '
            . 'ou au renouvellement gratuit. Détail complet sur la page « Fonctionnement des '
            . 'tokens » de la plateforme.',
            $this->parametres->readWeight(),
            $this->parametres->readWeight() > 1 ? 's' : '',
            $this->parametres->defaultWriteWeight(),
            $this->parametres->weightFor(AssistantMessage::class),
            $this->tokenAccountService->coutContexteIa(),
            $this->parametres->freeAllowance(),
            $this->parametres->freeWindowHours(),
        );
    }
}
