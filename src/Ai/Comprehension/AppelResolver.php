<?php

namespace App\Ai\Comprehension;

use App\Ai\AiRequest;
use App\Ai\Fournisseur\OrdreDesFournisseurs;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * La CHAÎNE des fournisseurs de la phase de compréhension.
 *
 * Même mécanique que le moteur de texte, la voix et les oreilles : `KET_COMPRENANTS`
 * dit la préférence, la présence d'une clé tranche, et `AI_ENGINE` prime sur les
 * deux. Cinq familles, une seule règle — la question « pourquoi Ket parle-t-elle à
 * l'un et comprend-elle chez l'autre ? » ne doit jamais se poser.
 *
 * FAIL-OPEN JUSQU'AU BOUT : si aucun fournisseur de la liste n'a de clé, cette
 * classe se déclare indisponible et Comprehenseur passe simplement son tour. La
 * demande part alors telle quelle, exactement comme avant l'existence de cette
 * phase. Elle n'est là que pour améliorer une réponse, jamais pour l'empêcher.
 */
final class AppelResolver implements AppelDeComprehension
{
    /** @param iterable<FournisseurDeComprehension> $fournisseurs */
    public function __construct(
        #[AutowireIterator('app.fournisseur_comprehension')] private readonly iterable $fournisseurs,
        #[Autowire(env: 'KET_COMPRENANTS')] private readonly string $ordre = 'anthropic,gemini',
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
        return $this->appel()?->cleDeDebit() ?? 'comprehension:aucun';
    }

    public function estDisponible(): bool
    {
        return $this->appel() !== null;
    }

    /**
     * Le résolveur ne rend JAMAIS un fournisseur à sec : le filtre de la chaîne
     * l'a déjà écarté. S'il n'en reste aucun, c'est « indisponible » qu'on dit —
     * et Comprehenseur passe son tour, comme il le fait sans clé.
     */
    public function estEpuise(): bool
    {
        return false;
    }

    public function conclure(AiRequest $request): array
    {
        $appel = $this->appel();
        if ($appel === null) {
            // Ne devrait pas arriver : Comprehenseur interroge estDisponible() avant.
            // La garde reste, parce qu'un fail-open qui lèverait ne serait plus un
            // fail-open.
            return ['texte' => '', 'tokens' => 0];
        }

        return $appel->conclure($request);
    }

    private function appel(): ?FournisseurDeComprehension
    {
        $chaine = OrdreDesFournisseurs::ordonner($this->fournisseurs, $this->politique?->ordre('comprehension') ?? $this->ordre);

        $force = strtolower(trim($this->force));
        if ($force !== '') {
            foreach ($chaine as $fournisseur) {
                if ($fournisseur->nom() === $force) {
                    return $fournisseur;
                }
            }

            // AI_ENGINE nomme un moteur qui n'a pas de comprenant — « simulated », par
            // exemple. Il n'y a alors rien à comprendre : le moteur simulé ne mène pas
            // de phases, et prendre un fournisseur réel ici appellerait une API que le
            // forçage cherchait précisément à éviter.
            return null;
        }

        return OrdreDesFournisseurs::utilisables($chaine)[0] ?? null;
    }
}
