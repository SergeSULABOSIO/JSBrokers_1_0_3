<?php

namespace App\Ai\Oreille;

use App\Ai\Fournisseur\OrdreDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * LES OREILLES DE KET, quel que soit le fournisseur.
 *
 * Même mécanique que la voix : les oreilles sont essayées dans l'ordre de
 * KET_OREILLE_FOURNISSEURS, et l'on passe à la suivante tant que rien n'a été entendu.
 * Quand aucune ne répond, la route rend 503 et le navigateur écoute avec sa propre
 * reconnaissance vocale — gratuite, et toujours là.
 */
final class OreilleDeKet
{
    /** @param iterable<FournisseurDOreille> $fournisseurs */
    public function __construct(
        #[AutowireIterator('app.fournisseur_oreille')] private readonly iterable $fournisseurs,
        #[Autowire(env: 'KET_OREILLE_FOURNISSEURS')] private readonly string $ordreParDefaut = 'elevenlabs,gemini',
        private readonly ?PolitiqueDesFournisseurs $politique = null,
    ) {
    }

    /**
     * ⚠ L'ORDRE SE RÉSOUT À CHAQUE APPEL — cf. VoixDeKet::fournisseurs() pour le
     * pourquoi : calculé au constructeur, il ne relirait jamais une politique
     * changée en console, et le réglage semblerait pris sans rien changer.
     *
     * @return list<FournisseurDOreille>
     */
    public function fournisseurs(): array
    {
        $ordre = $this->politique?->ordre('oreille') ?? $this->ordreParDefaut;

        return OrdreDesFournisseurs::disponibles(OrdreDesFournisseurs::ordonner($this->fournisseurs, $ordre));
    }

    public function estDisponible(): bool
    {
        return $this->fournisseurs() !== [];
    }

    /**
     * La parole en texte. Le premier fournisseur qui entend quelque chose l'emporte ; une
     * transcription VIDE n'est pas un échec (l'utilisateur n'a rien dit) et arrête la
     * chaîne : inutile de payer les suivants pour un silence.
     */
    public function transcrire(string $wav, string $langue = 'fr'): Transcription
    {
        $statuts = [];
        foreach ($this->fournisseurs() as $fournisseur) {
            $transcription = $fournisseur->transcrire($wav, $langue);
            if ($transcription->statut === Transcription::COMPLET) {
                return $transcription;
            }
            $statuts[] = $transcription->statut;
        }

        if ($statuts === []) {
            return Transcription::refus(Transcription::INDISPONIBLE);
        }

        return Transcription::refus(
            \in_array(Transcription::QUOTA, $statuts, true) ? Transcription::QUOTA : Transcription::ECHEC,
        );
    }
}
