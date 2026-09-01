<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\HistoriqueDemande;

/**
 * TOUT CE QU'UN E-MAIL DE CONGÉ DOIT DIRE, calculé une seule fois.
 *
 * Le template Twig ne fait que METTRE EN FORME ce qu'il reçoit : il ne recalcule aucun
 * chiffre, n'interroge aucun service, ne rejoue aucune règle. C'est la seule manière que
 * le solde annoncé dans un mail corresponde exactement à celui de l'écran — ils viennent
 * littéralement du même objet.
 *
 * ── UN INSTANTANÉ DATÉ ──────────────────────────────────────────────────────────────
 * Les soldes portés par un mail valent au moment de l'envoi. Un mail ouvert trois jours
 * plus tard ne reflète plus le compteur, et le lecteur doit le savoir : d'où
 * `instantaneLe`, affiché en toutes lettres plutôt que sous-entendu.
 */
final class CongeMailContext
{
    public function __construct(
        public readonly DemandeConge $demande,
        public readonly HistoriqueDemande $transition,
        public readonly SoldeConge $solde,
        /** Le disponible AVANT que cette demande ne soit posée. */
        public readonly float $disponibleAvant,
        /** Le disponible si la demande est (ou reste) approuvée. */
        public readonly float $disponibleApres,
        /** Collègues déjà absents sur tout ou partie de la période. */
        public readonly array $collegues,
        public readonly \DateTimeImmutable $instantaneLe,
        /** Lien direct vers l'espace de travail du destinataire. */
        public readonly string $lienApplication,
        public readonly string $titre,
        public readonly string $intro,
        public readonly string $icone,
    ) {
    }

    /** La transition a-t-elle été rendue par le demandeur, faute d'un autre valideur ? */
    public function estAutoApprouvee(): bool
    {
        return $this->transition->isAutoApprouvee();
    }

    /** La décision a-t-elle été prise par l'assistant plutôt qu'à l'écran ? */
    public function viaAssistant(): bool
    {
        return $this->transition->getOrigine() === DemandeConge::ORIGINE_KET;
    }
}
