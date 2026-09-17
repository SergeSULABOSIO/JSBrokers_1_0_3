<?php

namespace App\Ai\Fournisseur;

/**
 * CE QU'ONT EN COMMUN TOUS LES FOURNISSEURS de Ket — sa bouche (voix) comme ses
 * oreilles (transcription) : un nom court, celui qu'on écrit dans la variable
 * d'environnement d'ordre, et la capacité de dire s'ils sont appelables.
 *
 * Ce contrat minuscule existe pour une seule raison : la mécanique d'ORDRE et de
 * REPLI est la même des deux côtés, et elle ne doit être écrite qu'une fois
 * (cf. OrdreDesFournisseurs).
 */
interface Fournisseur
{
    /** Nom court, celui de KET_VOIX_FOURNISSEURS / KET_OREILLE_FOURNISSEURS. */
    public function nom(): string;

    /** Clé présente, moteur réel : ce fournisseur peut être appelé. */
    public function estDisponible(): bool;
}
