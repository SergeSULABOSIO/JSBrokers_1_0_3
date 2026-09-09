<?php

namespace App\Echange\Service;

use App\Echange\Classeur\LigneLue;

/**
 * TRADUIT LE VERDICT DU CIRCUIT D'ÉCRITURE en anomalie située.
 *
 * `WorkspaceMutationService::analyserOperation()` rend un diagnostic technique — un
 * statut, des champs manquants, des impacts. L'utilisateur, lui, lit un classeur : il lui
 * faut une phrase en français, à une adresse (feuille, ligne), et un code stable pour que
 * le rapport puisse compter.
 *
 * ⚠ CETTE TRADUCTION EST PARTAGÉE, ET DOIT LE RESTER. Deux voies mènent au même circuit
 * d'écriture — la reprise à la maille tranche et le format normalisé. En écrire deux
 * versions, ce serait promettre que la même faute se dira de la même façon des deux
 * côtés, et manquer à cette promesse au premier message retouché.
 */
final class DiagnosticEnAnomalie
{
    /**
     * ⚠ LE LIBELLÉ ET LE CODE, ET NON UNE RESSOURCE D'ÉCHANGE.
     *
     * Cette notion appartient au seul classeur normalisé : la voie « état du portefeuille »
     * n'en a pas, ses lignes produisant des écritures sur cinq entités différentes. Passer
     * une ressource factice ferait dire au rapport « Tranche » pour une erreur portant sur
     * le client.
     *
     * @param array<string, mixed> $diagnostic
     */
    public function signaler(
        array $diagnostic,
        LigneLue $ligne,
        string $libelle,
        string $code,
        RapportDeControle $rapport,
    ): void {
        $message = match ($diagnostic['statut']) {
            'hors_perimetre' => sprintf('« %s » est hors de votre périmètre d\'écriture.', $libelle),
            'introuvable' => sprintf(
                'Aucune ligne de « %s » ne porte cet identifiant dans votre cabinet. '
                . 'Elle a peut-être été supprimée depuis votre export.',
                $libelle,
            ),
            'bloque' => implode(' ', $diagnostic['impacts'] ?: ['Opération impossible.']),
            default => $this->messageDesManquants($diagnostic['manquants'] ?? []),
        };

        $rapport->ajouter(Anomalie::erreur(
            match ($diagnostic['statut']) {
                'hors_perimetre' => Anomalie::DROIT_INSUFFISANT,
                'introuvable' => Anomalie::LIGNE_INTROUVABLE,
                'bloque' => Anomalie::SUPPRESSION_BLOQUEE,
                default => Anomalie::CHAMP_OBLIGATOIRE,
            },
            $message,
            $ligne->feuille,
            $ligne->numero,
        ));
        $rapport->compterErreur($code);
    }

    /** @param array<string, string[]> $manquants */
    private function messageDesManquants(array $manquants): string
    {
        if ($manquants === []) {
            return 'Cette ligne ne peut pas être enregistrée en l\'état.';
        }

        $morceaux = [];
        foreach ($manquants as $champ => $messages) {
            $morceaux[] = sprintf('%s (%s)', $champ, implode(' ', (array) $messages));
        }

        return 'Informations manquantes ou invalides : ' . implode(' ; ', $morceaux) . '.';
    }
}
