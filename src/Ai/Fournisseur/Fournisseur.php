<?php

namespace App\Ai\Fournisseur;

/**
 * CE QU'ONT EN COMMUN TOUS LES FOURNISSEURS de Ket : un nom court, celui qu'on
 * écrit dans la liste d'ordre, et la capacité de dire s'ils sont appelables.
 *
 * Ce contrat minuscule existe pour une seule raison : la mécanique d'ORDRE et de
 * REPLI est la même partout, et elle ne doit être écrite qu'une fois
 * (cf. OrdreDesFournisseurs).
 *
 * CINQ FAMILLES le portent aujourd'hui — le moteur de texte, la voix, les
 * oreilles, la phase de compréhension et la finition de dictée. Le moteur de
 * texte les a rejointes en dernier : il était resté un `switch` en dur, ce qui le
 * rendait seul à ne pas pouvoir être piloté par une liste.
 */
interface Fournisseur
{
    /** Nom court, celui de KET_VOIX_FOURNISSEURS / KET_OREILLE_FOURNISSEURS. */
    public function nom(): string;

    /** Clé présente, moteur réel : ce fournisseur peut être appelé. */
    public function estDisponible(): bool;

    /**
     * CE FOURNISSEUR S'EST-IL DÉJÀ DÉCLARÉ À SEC ? Sans appel réseau : c'est la
     * mémoire d'épuisement qu'on interroge, celle-là même qui évite de refaire un
     * aller-retour perdu à chaque tour.
     *
     * POURQUOI LE CONTRAT LE DEMANDE, et pas seulement la voix. Un refus de quota
     * coûte une attente pour un résultat connu d'avance ; le payer une fois est
     * inévitable, le payer à chaque tour est un défaut. Savoir d'avance qu'un
     * fournisseur ne rendra rien permet d'aller droit au suivant — ou, pour la
     * voix, de brancher directement la synthèse du navigateur. Mesuré le
     * 2026-09-21 : crédits ElevenLabs épuisés depuis le 17/09, trois modèles
     * Gemini depuis le 19/09 — toutes les lectures passaient déjà par le
     * navigateur, mais chacune commençait par attendre un « non ».
     *
     * Un fournisseur sans quota à suivre rend simplement false.
     */
    public function estEpuise(): bool;
}
