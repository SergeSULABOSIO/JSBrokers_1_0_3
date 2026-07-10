<?php

namespace App\Ai\Engine;

use App\Ai\AiReply;
use App\Ai\AiRequest;

/**
 * @file Abstraction du moteur conversationnel de l'assistant IA, indépendante
 * du fournisseur de modèle.
 * @description Permet de livrer l'assistant de bout en bout tout en DIFFÉRANT
 * le choix du LLM : l'implémentation par défaut (SimulatedAiEngine) répond de
 * façon déterministe via les outils de données. Le jour où le modèle réel est
 * choisi (bridge Symfony AI / Anthropic…), il suffit d'ajouter un adaptateur
 * `XxxAiEngine implements AiEngineInterface` et de basculer l'alias de service
 * dans config/services.yaml — aucun autre changement applicatif.
 *
 * Même pattern que PaymentGatewayInterface / SimulatedGateway (paiement).
 * IMPORTANT : le contrôle d'accès aux données ne dépend PAS du moteur — il vit
 * dans AiToolInterface::execute() (fail-closed), quel que soit le modèle.
 */
interface AiEngineInterface
{
    /** Identifiant technique du moteur (ex. « simulated », « anthropic ») — traçable. */
    public function name(): string;

    /** Produit la réponse de l'assistant à partir du contexte et de l'historique. */
    public function reply(AiRequest $request): AiReply;
}
