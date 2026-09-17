<?php

namespace App\Ai\Voix;

use App\Ai\Fournisseur\OrdreDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * LA VOIX DE KET, quel que soit le fournisseur.
 *
 * Les fournisseurs sont essayés dans l'ordre de KET_VOIX_FOURNISSEURS (par défaut
 * ElevenLabs puis Gemini). Tant qu'aucun son n'est sorti, un fournisseur indisponible,
 * épuisé ou en panne passe la main au suivant. Dès qu'un fournisseur a parlé, il va au
 * bout : reprendre une phrase à zéro avec une autre voix serait pire qu'un arrêt. Quand
 * aucun ne parle, la route répond 503 et le navigateur lit avec sa propre voix.
 */
final class VoixDeKet
{
    /** @var list<FournisseurDeVoix> */
    private array $ordonnes;

    private ?FournisseurDeVoix $dernier = null;

    /** @param iterable<FournisseurDeVoix> $fournisseurs */
    public function __construct(
        #[AutowireIterator('app.fournisseur_voix')] iterable $fournisseurs,
        #[Autowire(env: 'KET_VOIX_FOURNISSEURS')] string $ordre = 'elevenlabs,gemini',
    ) {
        // L'ordre et le filtre de disponibilité sont les mêmes pour la bouche et pour les
        // oreilles : ils vivent dans OrdreDesFournisseurs, jamais en double.
        $this->ordonnes = OrdreDesFournisseurs::ordonner($fournisseurs, $ordre);
    }

    /**
     * Les fournisseurs appelables, dans l'ordre de préférence.
     *
     * @return list<FournisseurDeVoix>
     */
    public function fournisseurs(): array
    {
        return OrdreDesFournisseurs::disponibles($this->ordonnes);
    }

    public function estDisponible(): bool
    {
        return $this->fournisseurs() !== [];
    }

    /**
     * L'identité d'une voix dans le cache audio : deux fournisseurs, ou deux voix d'un même
     * fournisseur, ne partagent jamais un enregistrement.
     */
    public static function identite(FournisseurDeVoix $fournisseur): string
    {
        return $fournisseur->nom() . ':' . $fournisseur->voix();
    }

    /** Le fournisseur qui a parlé lors du dernier flux (celui dont l'audio se met en cache). */
    public function dernierFournisseur(): ?FournisseurDeVoix
    {
        return $this->dernier;
    }

    /**
     * @return \Generator<int, string, mixed, string>
     */
    public function flux(string $texte): \Generator
    {
        $this->dernier = null;
        $statuts = [];
        foreach ($this->fournisseurs() as $fournisseur) {
            $flux = $fournisseur->flux($texte);
            $aParle = false;
            foreach ($flux as $morceau) {
                if (!$aParle) {
                    $aParle = true;
                    $this->dernier = $fournisseur;
                }
                yield $morceau;
            }
            if ($aParle) {
                return $flux->getReturn();
            }
            $statuts[] = $flux->getReturn();
        }

        if ($statuts === []) {
            return FournisseurDeVoix::INDISPONIBLE;
        }

        return \in_array(FournisseurDeVoix::QUOTA, $statuts, true) ? FournisseurDeVoix::QUOTA : FournisseurDeVoix::ECHEC;
    }
}
