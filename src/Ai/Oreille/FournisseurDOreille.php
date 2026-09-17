<?php

namespace App\Ai\Oreille;

use App\Ai\Fournisseur\Fournisseur;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * LES OREILLES DE KET : un fournisseur qui transcrit la parole de l'utilisateur.
 *
 * Symétrique du contrat de la voix, et tout aussi minuscule : de l'audio entre, du texte
 * sort. Le texte part ensuite dans le circuit ORDINAIRE d'un message — Ket reste le seul
 * cerveau, et ce contrat ne lui apporte aucune connaissance.
 *
 * Ajouter une oreille (Claude, Whisper, autre) : écrire une classe qui implémente ceci,
 * puis la nommer dans KET_OREILLE_FOURNISSEURS.
 */
#[AutoconfigureTag('app.fournisseur_oreille')]
interface FournisseurDOreille extends Fournisseur
{
    /** Format attendu par tous : WAV PCM 16 bits mono 16 kHz (ce que le navigateur envoie). */
    public const TAUX_ECHANTILLONNAGE = 16000;

    /**
     * @param string $wav    contenu d'un fichier WAV
     * @param string $langue code ISO à deux lettres (« fr », « en »)
     */
    public function transcrire(string $wav, string $langue): Transcription;
}
