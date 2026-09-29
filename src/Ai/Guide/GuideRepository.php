<?php

namespace App\Ai\Guide;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
    /** @var array<string, array{titre: string, description: string, chemin: ?string, derivee: ?FicheDerivee}>|null */
    private ?array $catalogue = null;

    /**
     * @param iterable<FicheDerivee> $derivees Fiches CALCULÉES, qui entrent au
     *        catalogue par la même porte que les `.md` — sans quoi `slugs()` ne les
     *        contiendrait pas, et l'enum du schéma de `consulter_guide` les
     *        rendrait injoignables.
     *
     *        ⚠ LA VALEUR PAR DÉFAUT N'EST PAS UNE COMMODITÉ : `ConsulterGuideToolTest`
     *        instancie ce dépôt à la main, sans conteneur, pour éprouver le catalogue
     *        sans booter le noyau. La lui retirer casserait ce test.
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[AutowireIterator('app.ai_fiche_derivee')] private readonly iterable $derivees = [],
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

        // Une fiche DÉRIVÉE ne se lit pas sur le disque : elle se calcule, et
        // seulement ici — c'est-à-dire seulement quand le modèle l'ouvre.
        $derivee = $fiches[$slug]['derivee'] ?? null;
        if ($derivee instanceof FicheDerivee) {
            return $derivee->contenu();
        }

        $contenu = @file_get_contents((string) $fiches[$slug]['chemin']);

        return $contenu === false ? null : $contenu;
    }

    /** @return array<string, array{titre: string, description: string, chemin: ?string, derivee: ?FicheDerivee}> */
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
                'derivee'     => null,
            ];
        }

        foreach ($this->derivees as $derivee) {
            $slug = $derivee->slug();
            // ⚠ UNE DÉRIVÉE NE DOIT PAS ÉCLIPSER UN FICHIER. Un slug en double ferait
            // disparaître une fiche `.md` du catalogue sans que rien ne le dise, et
            // `consulter_guide` servirait autre chose que ce qu'on croit lui demander.
            if (isset($this->catalogue[$slug])) {
                throw new \LogicException(sprintf(
                    'La fiche dérivée « %s » porte le slug d’une fiche existante (%s.md). '
                    . 'Renomme-la : deux fiches ne peuvent pas répondre au même sujet.',
                    $derivee::class,
                    $slug,
                ));
            }
            $this->catalogue[$slug] = [
                'titre'       => $derivee->titre(),
                'description' => $derivee->description(),
                'chemin'      => null,
                'derivee'     => $derivee,
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
