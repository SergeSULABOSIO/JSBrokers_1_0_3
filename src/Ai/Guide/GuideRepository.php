<?php

namespace App\Ai\Guide;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fiches de connaissance métier de l'assistant IA (« skills » à divulgation
 * progressive) : des fichiers markdown versionnés dans src/Ai/Guide/fiches/.
 * Seul le CATALOGUE (slug + description) est injecté dans le prompt système —
 * le contenu d'une fiche n'est chargé que quand le modèle appelle l'outil
 * consulter_guide, pour maîtriser les tokens de chaque message.
 *
 * Convention de fiche : première ligne « # Titre », deuxième ligne utile
 * « > description en une phrase » (utilisée dans le catalogue), puis le corps.
 */
final class GuideRepository
{
    /** @var array<string, array{titre: string, description: string, chemin: string}>|null */
    private ?array $catalogue = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** @return array<string, array{titre: string, description: string}> slug => titre + description */
    public function catalogue(): array
    {
        return array_map(
            static fn (array $f) => ['titre' => $f['titre'], 'description' => $f['description']],
            $this->scan(),
        );
    }

    /** @return string[] slugs des fiches disponibles (enum du schéma d'outil) */
    public function slugs(): array
    {
        return array_keys($this->scan());
    }

    /** Contenu markdown complet d'une fiche, ou null si le sujet est inconnu. */
    public function fiche(string $slug): ?string
    {
        $fiches = $this->scan();
        if (!isset($fiches[$slug])) {
            return null;
        }

        $contenu = @file_get_contents($fiches[$slug]['chemin']);

        return $contenu === false ? null : $contenu;
    }

    /** @return array<string, array{titre: string, description: string, chemin: string}> */
    private function scan(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $this->catalogue = [];
        foreach (glob($this->projectDir . '/src/Ai/Guide/fiches/*.md') ?: [] as $chemin) {
            $slug = basename($chemin, '.md');
            [$titre, $description] = $this->entete($chemin);
            $this->catalogue[$slug] = [
                'titre'       => $titre,
                'description' => $description,
                'chemin'      => $chemin,
            ];
        }
        ksort($this->catalogue);

        return $this->catalogue;
    }

    /** @return array{0: string, 1: string} titre (# …) et description (> …) de la fiche */
    private function entete(string $chemin): array
    {
        $titre = basename($chemin, '.md');
        $description = '';
        foreach (array_slice(file($chemin) ?: [], 0, 5) as $ligne) {
            $ligne = trim($ligne);
            if ($titre === basename($chemin, '.md') && str_starts_with($ligne, '# ')) {
                $titre = trim(substr($ligne, 2));
            } elseif ($description === '' && str_starts_with($ligne, '> ')) {
                $description = trim(substr($ligne, 2));
            }
        }

        return [$titre, $description];
    }
}
