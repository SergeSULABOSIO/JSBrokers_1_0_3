<?php

namespace App\Crm;

use App\Entity\Crm\CrmProfil;

/**
 * @file Recommandations Customer Success automatiques.
 * @description Transforme l'état d'un client (étape de pipeline, score, signaux)
 * en actions concrètes suggérées à l'agent. Règles simples et déterministes,
 * réutilisées par la fiche client (onglet Santé) et le tableau de bord CS.
 */
class CrmRecommendationService
{
    /**
     * @param array<string, mixed> $signals
     *
     * @return string[] Liste de recommandations (les plus prioritaires d'abord)
     */
    public function forClient(CrmProfil $profil, array $signals): array
    {
        $reco = [];
        $now = new \DateTimeImmutable();

        $daysSinceActivity = $signals['lastActivityAt'] instanceof \DateTimeInterface
            ? (int) $signals['lastActivityAt']->diff($now)->days
            : null;

        if ($profil->getEtapePipeline() === CrmPipelineService::STAGE_CHURN) {
            $reco[] = 'Client en churn : lancer une campagne de réactivation et planifier un appel personnalisé.';
        }

        if ($profil->getScoreCouleur() === 'rouge') {
            $reco[] = 'Score critique : contacter le client sous 48 h pour comprendre les blocages.';
        } elseif ($profil->getScoreCouleur() === 'orange') {
            $reco[] = 'Santé fragile : proposer un accompagnement (démo, prise en main) pour relancer l\'usage.';
        }

        if ($daysSinceActivity !== null && $daysSinceActivity >= 15) {
            $reco[] = sprintf('Aucune activité depuis %d jours : envoyer une relance d\'engagement.', $daysSinceActivity);
        }

        if (($signals['paidTokens'] ?? 0) > 0 && ($signals['paidTokens'] ?? 0) < 1000) {
            $reco[] = 'Solde de tokens bas : suggérer une recharge avant rupture.';
        }

        if (($signals['nbPurchases'] ?? 0) === 0 && ($signals['totalConsumption'] ?? 0) > 0) {
            $reco[] = 'En essai actif sans achat : proposer une offre de premier achat (coupon).';
        }

        if (($signals['nbPurchases'] ?? 0) >= 2 && $profil->getScoreCouleur() === 'vert') {
            $reco[] = 'Client fidèle et en bonne santé : candidat idéal pour un upsell / programme ambassadeur.';
        }

        if (($signals['nbInvites'] ?? 0) === 0 && ($signals['nbEntreprises'] ?? 0) > 0) {
            $reco[] = 'Aucun collaborateur invité : encourager l\'invitation d\'équipe (expansion du compte).';
        }

        if ($reco === []) {
            $reco[] = 'Aucune action urgente : maintenir le suivi régulier.';
        }

        return $reco;
    }
}
