<?php

namespace App\Payment\Gateway;

use App\Payment\PaymentContext;
use App\Payment\PaymentIntent;
use App\Payment\PaymentResult;
use App\Payment\WebhookEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * @file Abstraction d'un prestataire de paiement (PSP), indépendante du fournisseur.
 * @description Trois temps : créer l'intention → confirmer → réconcilier le
 * webhook. Permet d'encaisser réellement tout en DIFFÉRANT le choix du PSP :
 * l'implémentation par défaut (SimulatedGateway) reste active et l'app
 * fonctionne de bout en bout. Le jour où le PSP est choisi, il suffit d'ajouter
 * un adaptateur `XxxGateway implements PaymentGatewayInterface` et de basculer
 * l'alias de service — aucun autre changement applicatif.
 *
 * Pattern stratégie/adaptateur déjà employé dans le projet (cf. les
 * `*StrategyInterface` taggés dans config/services.yaml).
 */
interface PaymentGatewayInterface
{
    /** Identifiant technique du PSP (ex. « simulated », « stripe ») — tracé sur l'achat. */
    public function name(): string;

    /** Crée l'intention de paiement chez le PSP (montant TTC, référence, métadonnées). */
    public function createIntent(PaymentContext $context): PaymentIntent;

    /**
     * Confirme/relit l'état d'une intention par sa référence prestataire
     * (confirmation synchrone du simulateur, ou au retour de la page hébergée).
     */
    public function confirm(string $providerReference): PaymentResult;

    /**
     * Vérifie la signature de la requête webhook puis renvoie l'événement
     * normalisé. DOIT lever une exception si la signature est invalide (le
     * webhook ne doit JAMAIS être traité sans authentification).
     *
     * @throws \RuntimeException si la signature est absente ou invalide.
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
