<?php

namespace App\Ai\Dictee;

use App\Ai\Fournisseur\OrdreDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * La CHAÎNE des fournisseurs de finition de dictée.
 *
 * Même mécanique que les quatre autres familles : `KET_FINISSEURS` dit la
 * préférence, la disponibilité tranche, `AI_ENGINE` prime. Cinq familles, une
 * seule règle de sélection.
 *
 * FAIL-OPEN : aucun fournisseur utilisable, et la dictée revient telle qu'elle a
 * été prononcée. Elle ne doit jamais perdre ce que l'utilisateur a dit parce que
 * sa mise au propre n'a pas pu se faire.
 */
final class FinitionResolver implements AppelDeFinition
{
    /** @param iterable<FournisseurDeFinition> $fournisseurs */
    public function __construct(
        #[AutowireIterator('app.fournisseur_finition')] private readonly iterable $fournisseurs,
        #[Autowire(env: 'KET_FINISSEURS')] private readonly string $ordre = 'anthropic,gemini',
        #[Autowire(env: 'AI_ENGINE')] private readonly string $force = '',
        // LA POLITIQUE, quand elle existe, PRIME sur la liste du .env — c'est tout
        // l'objet de l'écran de console. Facultative : sans elle, rien ne change.
        private readonly ?PolitiqueDesFournisseurs $politique = null,
    ) {
    }

    public function nom(): string
    {
        return $this->appel()?->nom() ?? 'aucun';
    }

    public function modele(): string
    {
        return $this->appel()?->modele() ?? '';
    }

    public function cleDeDebit(): string
    {
        return $this->appel()?->cleDeDebit() ?? 'finition:aucun';
    }

    public function estDisponible(): bool
    {
        return $this->appel() !== null;
    }

    /** Idem : la chaîne a déjà écarté les fournisseurs à sec. */
    public function estEpuise(): bool
    {
        return false;
    }

    public function finir(string $consigne, string $brut, int $plafondSortie): array
    {
        $appel = $this->appel();
        if ($appel === null) {
            // FinisseurDeDictee interroge estDisponible() avant : cette garde n'existe
            // que pour qu'un fail-open ne puisse jamais se transformer en exception.
            return ['texte' => '', 'tokens' => 0];
        }

        return $appel->finir($consigne, $brut, $plafondSortie);
    }

    private function appel(): ?FournisseurDeFinition
    {
        $chaine = OrdreDesFournisseurs::ordonner($this->fournisseurs, $this->politique?->ordre('dictee') ?? $this->ordre);

        $force = strtolower(trim($this->force));
        if ($force !== '') {
            foreach ($chaine as $fournisseur) {
                if ($fournisseur->nom() === $force) {
                    // Chaque implémentation vérifie elle-même que le moteur n'est pas
                    // simulé : un forçage sur « simulated » ne trouve personne ici, et
                    // c'est exactement ce que les tests attendent.
                    return $fournisseur->estDisponible() ? $fournisseur : null;
                }
            }

            return null;
        }

        return OrdreDesFournisseurs::utilisables($chaine)[0] ?? null;
    }
}
