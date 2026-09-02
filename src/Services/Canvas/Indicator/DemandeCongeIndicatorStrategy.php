<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\DemandeConge;
use App\Entity\Utilisateur;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * CE QU'UNE LIGNE DE LA LISTE DES CONGÉS DOIT MONTRER.
 *
 * Un congé ne se lit pas sans son collaborateur ni sa période — mais un canevas de liste
 * ne sait lire qu'un attribut PLAT : « agent.nom » tomberait en erreur dès la première
 * ligne, ce qu'une liste vide ne révélerait pas. Ces indicateurs sont la traduction.
 *
 * ── LE SOLDE DE L'AGENT, SUR LA LIGNE ───────────────────────────────────────────────
 * C'est l'information dont le valideur a besoin AVANT de décider : approuver dix jours à
 * quelqu'un qui n'en a plus que trois se répare mal. Il vient de CalculateurSolde, le
 * même calcul que la fiche et que les e-mails.
 *
 * ── LES DRAPEAUX D'ACTIONS DÉPENDENT DE QUI REGARDE ─────────────────────────────────
 * « Approuver » n'a de sens que pour un valideur, et jamais sur sa propre demande. On
 * lit donc l'invité connecté ici. Ce n'est qu'un confort d'interface : le workflow
 * rejoue la règle à l'exécution, et c'est lui qui refuse pour de bon.
 */
class DemandeCongeIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private readonly CalculateurSolde $calculateurSolde,
        private readonly DemandeCongePolicy $policy,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly Security $security,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DemandeConge::class;
    }

    public function calculate(object $entity): array
    {
        /** @var DemandeConge $entity */
        $agent = $entity->getAgent();
        $acteur = $this->inviteConnecte();

        $indicateurs = [
            'agentNom' => $agent?->getNom() ?? 'Collaborateur inconnu',
            'typeAbsenceLibelle' => (string) ($entity->getTypeAbsence() ?? "Type non précisé"),
            'statutLibelle' => $this->statutLibelle($entity),
            'periodeLibelle' => $this->periodeLibelle($entity),
            'valideurNom' => $entity->getValideur()?->getNom(),
            'nombreDocuments' => $entity->getDocuments()->count(),
            'soldeDisponibleAgent' => $agent !== null
                ? $this->calculateurSolde->pour($agent, $entity->getExercice())->disponible()
                : null,
        ];

        // Sans invité connecté (commande, test unitaire, inspection de formulaire), aucun
        // geste n'est proposé : fail-closed, comme partout ailleurs.
        if ($acteur === null) {
            return $indicateurs + [
                'peutEtreSoumise' => false,
                'peutEtreDecidee' => false,
                'peutEtreAnnulee' => false,
            ];
        }

        return $indicateurs + [
            'peutEtreSoumise' => $entity->getStatut() === DemandeConge::STATUT_BROUILLON
                && $this->policy->peutModifier($acteur, $entity),
            'peutEtreDecidee' => $this->policy->peutDecider($acteur, $entity),
            'peutEtreAnnulee' => $this->policy->peutAnnuler($acteur, $entity),
        ];
    }

    /**
     * Le statut, enrichi de ce que la date ajoute.
     *
     * IL N'Y A PAS D'ÉTAT « ÉCHUE » EN BASE : une demande approuvée dont la date de fin
     * est passée reste APPROUVEE. L'échéance se lit ici, sur la date — c'est ce qui évite
     * une tâche de bascule nocturne et un état de plus à maintenir.
     */
    private function statutLibelle(DemandeConge $demande): string
    {
        $libelle = \App\Services\Search\CongeStatutScope::libelle($demande->getStatut());

        if ($demande->getStatut() !== DemandeConge::STATUT_APPROUVEE) {
            return $libelle;
        }

        $fin = $demande->getDateFin();
        $aujourdhui = new \DateTimeImmutable('today');

        if ($fin !== null && $fin < $aujourdhui) {
            return $libelle . ' · échue';
        }

        if ($demande->aCommence($aujourdhui)) {
            return $libelle . ' · en cours';
        }

        return $libelle;
    }

    /**
     * « Du 07/09/2026 au 11/09/2026 · 5 j ouvrables », avec la mention des demi-journées.
     *
     * ── POURQUOI « OUVRABLES » EST ÉCRIT ────────────────────────────────────────────
     * « Du 02/09 au 05/09 · 3 j » se lit comme une erreur quand on compte quatre jours au
     * calendrier. Il n'y en a pourtant que trois de travaillés : le 5 est un samedi. Les
     * deux bornes SONT comptées — c'est le week-end qui ne l'est pas.
     *
     * Le mot manquait, et son absence transformait un décompte juste en soupçon de bogue.
     * L'unité s'écrit donc là où le chiffre se lit, et non seulement dans l'intro du
     * formulaire que l'on ne relit plus. — Bastien & Scapin > Signifiance.
     */
    private function periodeLibelle(DemandeConge $demande): string
    {
        $debut = $demande->getDateDebut()?->format('d/m/Y') ?? '?';
        $fin = $demande->getDateFin()?->format('d/m/Y') ?? '?';
        $jours = $demande->nbJoursFloat();

        $libelle = $debut === $fin
            ? sprintf('Le %s', $debut)
            : sprintf('Du %s au %s', $debut, $fin);

        if ($jours > 0.0) {
            $libelle .= sprintf(' · %s j ouvrables', rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ','));
        }

        $demies = [];
        if ($demande->isDemiJourneeDebut()) {
            $demies[] = 'début';
        }
        if ($demande->isDemiJourneeFin()) {
            $demies[] = 'fin';
        }
        if ($demies !== []) {
            $libelle .= sprintf(' · demi-journée de %s', implode(' et de ', $demies));
        }

        return $libelle;
    }

    private function inviteConnecte(): ?\App\Entity\Invite
    {
        $user = $this->security->getUser();

        return $user instanceof Utilisateur
            ? $this->accessResolver->resolveConnectedInvite($user)
            : null;
    }
}
