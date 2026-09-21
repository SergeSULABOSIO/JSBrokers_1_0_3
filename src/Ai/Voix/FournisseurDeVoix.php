<?php

namespace App\Ai\Voix;

use App\Ai\Fournisseur\Fournisseur;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * UN FOURNISSEUR DE VOIX pour Ket (Gemini, ElevenLabs…).
 *
 * Le contrat est volontairement minuscule : un texte entre, de l'audio PCM sort. Tout
 * le reste — la route, le cache, les jetons, le lecteur du navigateur et le repli sur
 * la voix native — est commun et ne connaît aucun fournisseur. Ajouter une voix, c'est
 * écrire une classe qui implémente ceci, et l'ajouter à KET_VOIX_FOURNISSEURS.
 *
 * Un fournisseur ne fait que PRONONCER : le texte vient toujours d'une bulle de Ket.
 */
#[AutoconfigureTag('app.fournisseur_voix')]
interface FournisseurDeVoix extends Fournisseur
{
    public const COMPLET = 'complet';
    public const QUOTA = 'quota';
    public const INDISPONIBLE = 'indisponible';
    public const ECHEC = 'echec';

    /** Format commun à tous : PCM 16 bits signé, petit-boutiste, mono, 24 kHz. */
    public const TAUX_ECHANTILLONNAGE = 24000;

    /** La voix utilisée : elle entre dans la clé du cache audio. */
    public function voix(): string;

    /**
     * Le modèle réellement utilisé pour cette demande. Il entre dans la clé du cache :
     * la même phrase dite par deux modèles donne deux enregistrements distincts.
     */
    public function modele(bool $vitesse = false): string;

    /**
     * CE FOURNISSEUR S'EST-IL DÉJÀ DÉCLARÉ À SEC ? Sans appel réseau : c'est la mémoire
     * d'épuisement qu'on interroge, celle-là même qui évite de refaire un aller-retour
     * perdu à chaque écoute.
     *
     * POURQUOI LE CONTRAT LE DEMANDE. Savoir d'avance qu'aucune voix ne parlera permet à
     * la page de brancher DIRECTEMENT la synthèse du navigateur, sans payer la requête
     * qui ne rendra qu'un refus. Mesuré le 2026-09-21 : crédits ElevenLabs du mois
     * épuisés depuis le 17/09, les trois modèles Gemini épuisés depuis le 19/09 — toutes
     * les lectures passaient donc déjà par le navigateur, mais chacune commençait par
     * attendre un « non ».
     */
    public function estEpuise(): bool;

    /**
     * Le texte en voix, morceau PCM par morceau PCM. `$vitesse` demande le modèle le
     * plus rapide (mode Live : premier son en ~1 s au lieu de 2,4 s). Valeur de retour
     * (`getReturn()`) :
     * COMPLET si du son est sorti et que le flux s'est terminé normalement ; QUOTA si le
     * fournisseur est épuisé avant le premier son ; INDISPONIBLE s'il ne peut pas être
     * appelé ; ECHEC sinon — y compris une panne APRÈS le premier son, dont l'audio
     * partiel ne doit être ni mis en cache ni facturé.
     *
     * @return \Generator<int, string, mixed, string>
     */
    public function flux(string $texte, bool $vitesse = false): \Generator;
}
