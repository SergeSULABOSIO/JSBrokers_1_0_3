<?php

namespace App\Service\Conge;

/**
 * Le résultat des contrôles d'une demande : ce qui la BLOQUE, et ce qui a été CONTOURNÉ.
 *
 * ── POURQUOI DEUX LISTES ET NON UNE ─────────────────────────────────────────────────
 * Trois contrôles sont contournables par un valideur : le préavis, le plafond d'absents
 * simultanés et les périodes de blocage. Pour un collaborateur ordinaire ils refusent ;
 * pour un valideur ils ne font que signaler.
 *
 * Rendre une seule liste aurait obligé chaque appelant à savoir lesquels sont durs et
 * lesquels ne le sont pas — c'est-à-dire à recopier la règle. Ici le validateur tranche
 * une fois, et l'appelant n'a plus qu'à lire.
 *
 * ── UN CONTOURNEMENT NE DOIT PAS ÊTRE SILENCIEUX ────────────────────────────────────
 * Les avertissements sont conservés sur la demande et repris dans le mail de soumission.
 * Un valideur qui passe outre en a le droit ; le cabinet a celui de le savoir.
 */
final class ControleConge
{
    /**
     * @param string[] $violations     ce qui empêche la soumission, sans appel
     * @param string[] $avertissements contrôles franchis grâce au statut de valideur
     */
    public function __construct(
        public readonly array $violations = [],
        public readonly array $avertissements = [],
    ) {
    }

    public function estBloquee(): bool
    {
        return $this->violations !== [];
    }

    public function aDesContournements(): bool
    {
        return $this->avertissements !== [];
    }

    /**
     * Les contournements en une chaîne, pour la colonne qui les conserve sur la demande.
     * Null quand il n'y en a aucun — une chaîne vide se lirait comme « pas encore calculé ».
     */
    public function contournementsEnTexte(): ?string
    {
        return $this->avertissements === [] ? null : implode("\n", $this->avertissements);
    }
}
