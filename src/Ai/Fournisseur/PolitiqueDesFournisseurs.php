<?php

namespace App\Ai\Fournisseur;

use App\Repository\PlateformeParametresRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * QUI RÉPOND, DANS QUEL ORDRE, AVEC QUEL MODÈLE — décidé en console, plus en
 * variables d'environnement.
 *
 * Calqué sur ParametresTokenService, et volontairement : mêmes gestes, même
 * cycle de vie, donc rien de nouveau à comprendre. Le singleton
 * PlateformeParametres porte la personnalisation, les variables d'environnement
 * restent la couche de DÉFAUTS, et le service met le tout en cache pour la durée
 * de la requête. Le contrôleur appelle `refresh()` après son `flush()` — c'est la
 * convention du projet, il n'y a aucune invalidation de pool ailleurs.
 *
 * ── FUSION CLÉ PAR CLÉ, JAMAIS REMPLACEMENT EN BLOC ──────────────────────────
 * Une famille absente de la base garde son défaut. Un fournisseur AJOUTÉ AU CODE
 * apparaît même sur une plateforme déjà personnalisée : c'est exactement la
 * sémantique de `fusionnerFormats()`, et pour la même raison — la variante
 * « remplacement » de `writeWeights` a du sens quand retirer une entrée est un
 * acte délibéré, ce qui n'est pas le cas ici.
 *
 * ── DEUX RÉGLAGES PAR FAMILLE ────────────────────────────────────────────────
 * `mode` = « epingle » (un seul fournisseur, aucun repli) ou « chaine » (l'ordre
 * est parcouru, chaque maillon indisponible ou à sec cédant au suivant).
 * `ordre` = la liste, dans laquelle un nom absent n'est JAMAIS appelé.
 * `reglages` = ce qui est propre à chaque fournisseur : modèle, voix, replis.
 *
 * ── LES CLÉS D'API NE SONT PAS ICI ───────────────────────────────────────────
 * La politique ne ressuscite jamais un fournisseur sans clé : `estDisponible()`
 * reste le garde. Un fournisseur épinglé mais sans clé cède au maillon suivant.
 */
final class PolitiqueDesFournisseurs
{
    public const MODE_CHAINE = 'chaine';
    public const MODE_EPINGLE = 'epingle';

    /** Les cinq familles, dans l'ordre où la console les présente. */
    public const FAMILLES = ['moteur', 'comprehension', 'dictee', 'voix', 'oreille'];

    /** Cache des valeurs résolues pour la requête courante. */
    private ?array $cache = null;

    public function __construct(
        private readonly PlateformeParametresRepository $repository,
        #[Autowire(env: 'KET_MOTEURS')] private readonly string $moteursParDefaut = 'anthropic,gemini,simulated',
        #[Autowire(env: 'KET_COMPRENANTS')] private readonly string $comprenantsParDefaut = 'anthropic,gemini',
        #[Autowire(env: 'KET_FINISSEURS')] private readonly string $finisseursParDefaut = 'anthropic,gemini',
        #[Autowire(env: 'KET_VOIX_FOURNISSEURS')] private readonly string $voixParDefaut = 'elevenlabs,gemini',
        #[Autowire(env: 'KET_OREILLE_FOURNISSEURS')] private readonly string $oreillesParDefaut = 'elevenlabs,gemini',
    ) {
    }

    /** Vide le cache — appelé par le contrôleur après une édition, dans la même requête. */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /**
     * L'ordre effectif d'une famille, sous la forme que `OrdreDesFournisseurs`
     * attend : une liste de noms séparés par des virgules.
     *
     * En mode ÉPINGLÉ, la liste se réduit au premier nom : les suivants ne seront
     * jamais appelés, ce qui est précisément ce qu'« épinglé » veut dire.
     */
    public function ordre(string $famille): string
    {
        $politique = $this->famille($famille);
        $ordre = $politique['ordre'];

        if ($politique['mode'] === self::MODE_EPINGLE && $ordre !== []) {
            $ordre = [$ordre[0]];
        }

        return implode(',', $ordre);
    }

    /** Le mode d'une famille : « chaine » ou « epingle ». */
    public function mode(string $famille): string
    {
        return $this->famille($famille)['mode'];
    }

    /**
     * Un réglage propre à un fournisseur — son modèle, sa voix, ses replis.
     * Rend $defaut quand la console n'a rien personnalisé, ce qui est le cas
     * courant : la valeur vient alors du `.env`, comme avant.
     */
    public function reglage(string $famille, string $fournisseur, string $clef, mixed $defaut = null): mixed
    {
        return $this->famille($famille)['reglages'][$fournisseur][$clef] ?? $defaut;
    }

    /**
     * L'état complet, tel que l'écran de console l'édite et le réaffiche.
     *
     * @return array<string, array{mode: string, ordre: list<string>, reglages: array<string, array<string, mixed>>}>
     */
    public function tout(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $enBase = $this->repository->getSingleton()->getKetFournisseurs() ?? [];
        $resolue = [];
        foreach (self::FAMILLES as $famille) {
            $resolue[$famille] = self::fusionner($this->defaut($famille), $enBase[$famille] ?? null);
        }

        return $this->cache = $resolue;
    }

    /**
     * @return array{mode: string, ordre: list<string>, reglages: array<string, array<string, mixed>>}
     */
    private function famille(string $famille): array
    {
        return $this->tout()[$famille] ?? ['mode' => self::MODE_CHAINE, 'ordre' => [], 'reglages' => []];
    }

    /**
     * Le défaut d'une famille : l'ordre du `.env`, en mode chaîne, sans réglage
     * particulier — chaque fournisseur lit alors ses propres variables.
     *
     * @return array{mode: string, ordre: list<string>, reglages: array<string, array<string, mixed>>}
     */
    private function defaut(string $famille): array
    {
        $liste = match ($famille) {
            'moteur'        => $this->moteursParDefaut,
            'comprehension' => $this->comprenantsParDefaut,
            'dictee'        => $this->finisseursParDefaut,
            'voix'          => $this->voixParDefaut,
            'oreille'       => $this->oreillesParDefaut,
            default         => '',
        };

        return [
            'mode'     => self::MODE_CHAINE,
            'ordre'    => array_values(array_filter(array_map('trim', explode(',', $liste)))),
            'reglages' => [],
        ];
    }

    /**
     * La personnalisation par-dessus le défaut, clé par clé.
     *
     * Une famille personnalisée sans « ordre » garde celui du `.env` : on ne veut
     * pas qu'un réglage de modèle, saisi seul, coupe tous les fournisseurs.
     *
     * @param array{mode: string, ordre: list<string>, reglages: array<string, array<string, mixed>>} $defaut
     *
     * @return array{mode: string, ordre: list<string>, reglages: array<string, array<string, mixed>>}
     */
    private static function fusionner(array $defaut, ?array $propre): array
    {
        if (!\is_array($propre)) {
            return $defaut;
        }

        $mode = \in_array($propre['mode'] ?? null, [self::MODE_CHAINE, self::MODE_EPINGLE], true)
            ? $propre['mode']
            : $defaut['mode'];

        $ordre = $defaut['ordre'];
        if (isset($propre['ordre']) && \is_array($propre['ordre'])) {
            $propose = array_values(array_filter(array_map(
                static fn ($n): string => trim((string) $n),
                $propre['ordre'],
            )));
            // Une liste vide n'est pas une politique : ce serait couper Ket
            // entièrement par une case décochée. On garde le défaut.
            if ($propose !== []) {
                $ordre = $propose;
            }
        }

        $reglages = $defaut['reglages'];
        foreach (($propre['reglages'] ?? []) as $fournisseur => $valeurs) {
            if (\is_array($valeurs)) {
                $reglages[(string) $fournisseur] = ($reglages[(string) $fournisseur] ?? []) + $valeurs;
            }
        }

        return ['mode' => $mode, 'ordre' => $ordre, 'reglages' => $reglages];
    }
}
