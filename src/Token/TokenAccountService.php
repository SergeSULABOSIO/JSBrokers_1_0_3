<?php

namespace App\Token;

use App\Entity\AssistantConversationContexte;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\TokenConsumption;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Service central du modèle de tokens (freemium JS Brokers).
 * @description Gère l'allocation gratuite renouvelable, le solde prépayé, le
 * métrage bloquant des lectures/écritures et la journalisation des
 * consommations. Toute la facturation est rattachée au PROPRIÉTAIRE de
 * l'entreprise (cf. plan). Point d'entrée unique : meterWrite() / meterRead().
 */
class TokenAccountService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ParametresTokenService $parametres,
    ) {
    }

    /**
     * Garantit que la fenêtre gratuite est à jour : si elle n'a jamais démarré
     * ou si elle a expiré (≥ 8 h), on la réinitialise (allocation rechargée).
     * Renouvellement paresseux — aucun cron nécessaire.
     */
    public function ensureFreshWindow(Utilisateur $owner): void
    {
        $now = new \DateTimeImmutable();
        $start = $owner->getFreeWindowStartedAt();

        if ($start === null || $now >= $start->modify('+' . $this->parametres->freeWindowHours() . ' hours')) {
            $owner->setFreeTokens($this->parametres->freeAllowance());
            $owner->setFreeWindowStartedAt($now);
            $this->em->flush();
        }
    }

    /**
     * Retourne l'état du solde après renouvellement éventuel.
     *
     * @return array{free:int, paid:int, total:int, windowStartedAt:?\DateTimeImmutable, nextRenewalAt:?\DateTimeImmutable, allowance:int}
     */
    public function getBalance(Utilisateur $owner): array
    {
        $this->ensureFreshWindow($owner);
        $start = $owner->getFreeWindowStartedAt();

        return [
            'free'            => $owner->getFreeTokens(),
            'paid'            => $owner->getPaidTokens(),
            'total'           => $owner->getTotalTokens(),
            'windowStartedAt' => $start,
            'nextRenewalAt'   => $start?->modify('+' . $this->parametres->freeWindowHours() . ' hours'),
            'allowance'       => $this->parametres->freeAllowance(),
        ];
    }

    /** Date du prochain renouvellement gratuit (après renouvellement éventuel). */
    public function nextRenewalAt(Utilisateur $owner): ?\DateTimeImmutable
    {
        return $this->getBalance($owner)['nextRenewalAt'];
    }

    /**
     * Le compte de l'entreprise est-il « payant » ? = son PROPRIÉTAIRE dispose
     * d'un solde de tokens PRÉPAYÉS strictement positif. Gouverne l'accès aux
     * fonctionnalités premium (assistant IA) : l'allocation gratuite ne suffit
     * pas — et un solde payant épuisé referme l'accès jusqu'à la recharge.
     */
    public function estComptePayant(Entreprise $entreprise): bool
    {
        $owner = $entreprise->getUtilisateur();

        return $owner instanceof Utilisateur && $owner->getPaidTokens() > 0;
    }

    /** Le propriétaire peut-il couvrir un coût donné ? (renouvellement pris en compte) */
    public function canAfford(Utilisateur $owner, int $cost): bool
    {
        if ($cost <= 0) {
            return true;
        }

        return $this->getBalance($owner)['total'] >= $cost;
    }

    /**
     * Débite un coût : on consomme d'abord le solde prépayé, puis l'allocation
     * gratuite (⇒ une fois le prépayé épuisé, on « bascule au mode gratuit »).
     * Jamais négatif. À n'appeler qu'après canAfford().
     */
    public function consume(Utilisateur $owner, int $cost): void
    {
        if ($cost <= 0) {
            return;
        }

        $paid = $owner->getPaidTokens();
        $fromPaid = min($paid, $cost);
        $owner->setPaidTokens($paid - $fromPaid);

        $reste = $cost - $fromPaid;
        if ($reste > 0) {
            $owner->setFreeTokens($owner->getFreeTokens() - $reste);
        }

        $this->em->flush();
    }

    /** Crédite des tokens prépayés (à l'achat d'un paquet). */
    public function credit(Utilisateur $owner, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $owner->setPaidTokens($owner->getPaidTokens() + $tokens);
        $this->em->flush();
    }

    /**
     * Reprend des tokens prépayés lors d'un remboursement (symétrique de credit()).
     * Borné à 0 : si le solde a déjà été partiellement consommé, on ne descend
     * jamais en négatif (le débit effectif peut être inférieur au montant remboursé).
     */
    public function refund(Utilisateur $owner, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $owner->setPaidTokens(max(0, $owner->getPaidTokens() - $tokens));
        $this->em->flush();
    }

    /**
     * Métrage d'une ÉCRITURE (création/édition d'une entité). Bloquant.
     *
     * @throws InsufficientTokensException si le solde du propriétaire est insuffisant.
     */
    public function meterWrite(object $entity, Entreprise $entreprise, ?Utilisateur $acteur): void
    {
        $owner = $entreprise->getUtilisateur();
        if (!$owner instanceof Utilisateur) {
            return; // Pas de propriétaire identifiable : on ne facture pas.
        }

        $cost = $this->parametres->weightFor($entity::class);
        $this->guardAndConsume($owner, $cost);

        $this->log(
            $entreprise,
            $owner,
            $acteur,
            $this->shortName($entity::class),
            TokenConsumption::SENS_ENTREE,
            1,
            $cost,
        );
    }

    /**
     * Métrage d'une LECTURE (lot de $count entités envoyées au frontend). Bloquant.
     *
     * @throws InsufficientTokensException si le solde du propriétaire est insuffisant.
     */
    public function meterRead(string $fqcn, int $count, Entreprise $entreprise, ?Utilisateur $acteur): void
    {
        if ($count <= 0) {
            return; // Rien d'envoyé : gratuit.
        }

        $owner = $entreprise->getUtilisateur();
        if (!$owner instanceof Utilisateur) {
            return;
        }

        $unit = $this->parametres->readWeight();
        $cost = $count * $unit;
        $this->guardAndConsume($owner, $cost);

        $this->log(
            $entreprise,
            $owner,
            $acteur,
            $this->shortName($fqcn),
            TokenConsumption::SENS_SORTIE,
            $count,
            $unit,
        );
    }

    /**
     * Métrage de l'attache de $nbObjets au contexte d'une conversation IA.
     * Coût unitaire = 80 % du poids d'un message assistant (suit le poids
     * paramétré en console). Bloquant, débité en une fois AVANT persistance ;
     * une ligne de journal par objet attaché.
     *
     * @throws InsufficientTokensException si le solde du propriétaire est insuffisant.
     */
    public function meterContexteIa(Entreprise $entreprise, ?Utilisateur $acteur, int $nbObjets): void
    {
        if ($nbObjets <= 0) {
            return;
        }

        $owner = $entreprise->getUtilisateur();
        if (!$owner instanceof Utilisateur) {
            return; // Pas de propriétaire identifiable : on ne facture pas.
        }

        $unit = $this->coutContexteIa();
        $this->guardAndConsume($owner, $nbObjets * $unit);

        for ($i = 0; $i < $nbObjets; $i++) {
            $this->log(
                $entreprise,
                $owner,
                $acteur,
                $this->shortName(AssistantConversationContexte::class),
                TokenConsumption::SENS_ENTREE,
                1,
                $unit,
            );
        }
    }

    /** Coût unitaire d'un objet attaché au contexte IA (80 % du poids message). */
    public function coutContexteIa(): int
    {
        return (int) ceil(
            TokenPricing::CONTEXTE_IA_RATIO * $this->parametres->weightFor(AssistantMessage::class),
        );
    }

    /** Vérifie la solvabilité puis débite, ou lève l'exception de blocage. */
    private function guardAndConsume(Utilisateur $owner, int $cost): void
    {
        if (!$this->canAfford($owner, $cost)) {
            throw new InsufficientTokensException(
                $cost,
                $owner->getTotalTokens(),
                $this->nextRenewalAt($owner),
            );
        }

        $this->consume($owner, $cost);
    }

    /** Journalise une ligne de consommation. */
    private function log(
        Entreprise $entreprise,
        Utilisateur $owner,
        ?Utilisateur $acteur,
        string $entiteNom,
        string $sens,
        int $nombre,
        int $poidsUnitaire,
    ): void {
        $conso = (new TokenConsumption())
            ->setEntreprise($entreprise)
            ->setProprietaire($owner)
            ->setActeur($acteur)
            ->setEntiteNom($entiteNom)
            ->setSens($sens)
            ->setNombre($nombre)
            ->setPoidsUnitaire($poidsUnitaire)
            ->setPoidsTotal($nombre * $poidsUnitaire);

        $this->em->persist($conso);
        $this->em->flush();
    }

    /** Nom court d'une classe (App\Entity\Cotation → Cotation). */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
