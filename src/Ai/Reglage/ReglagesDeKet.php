<?php

namespace App\Ai\Reglage;

use App\Repository\PlateformeParametresRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * CE QUE LA CONSOLE A COUPÉ, ET LES SEUILS QU'ELLE A DÉPLACÉS.
 *
 * Calqué sur `PolitiqueDesFournisseurs`, et volontairement : mêmes gestes, même
 * cycle de vie, donc rien de nouveau à comprendre. Le singleton
 * `PlateformeParametres` porte la personnalisation, le CODE reste la couche de
 * défauts, et le service met le tout en cache pour la durée de la requête.
 *
 * ── ON N'ENREGISTRE QUE LES ÉCARTS ──────────────────────────────────────────
 * Un outil absent de la carte est ACTIF ; un paramètre absent vaut sa constante.
 * Trois conséquences, et chacune compte :
 *  · « rétablir les réglages par défaut » est exact — remettre NULL suffit, il n'y
 *    a pas de liste à reconstruire ni de valeur à deviner ;
 *  · un outil AJOUTÉ au code arrive actif sans que personne y pense, là où une
 *    liste blanche l'aurait laissé muet jusqu'à ce qu'on s'en aperçoive ;
 *  · un outil RETIRÉ du code laisse au pire une clé orpheline, sans effet.
 *
 * ── CE N'EST PAS UNE SÉCURITÉ ───────────────────────────────────────────────
 * Couper un outil retire une CAPACITÉ, jamais une garde : `execute()` continue de
 * vérifier les droits en fail-closed, et un outil réactivé par erreur ne donne donc
 * accès à rien de plus. Symétriquement, aucun réglage ne permet d'ouvrir Ket à un
 * compte qui n'y a pas droit — cette condition vit dans `PorteDeKet`, hors de
 * portée de la console.
 *
 * ── LE CACHE, ET POURQUOI IL SE VIDE DEUX FOIS ──────────────────────────────
 * `refresh()` vaut pour la requête en cours : le contrôleur l'appelle après son
 * `flush()`, sans quoi l'écran se réafficherait avec les réglages d'AVANT
 * l'enregistrement. `reset()` couvre l'autre cas — le worker, qui vit des heures :
 * sans lui, un agent verrait son réglage enregistré, affiché… et sans effet sur les
 * messages traités en tâche de fond.
 */
final class ReglagesDeKet implements ResetInterface
{
    /**
     * Les seuils métier ouverts à la console, avec leur borne basse et haute.
     *
     * TOUT N'EST PAS ICI, ET C'EST VOULU. Le projet compte des dizaines de
     * constantes numériques — délais d'appel, tailles de fichier, plafonds de
     * tokens : de la plomberie, qui n'a de sens que pour qui lit le code. N'ouvrir
     * que ce qu'un courtier comprend évite un écran de quarante curseurs dont
     * personne n'ose toucher un seul.
     *
     * Les bornes ne sont pas décoratives : une valeur hors bornes arrivant en base —
     * par un import, un script, une main malheureuse — est IGNORÉE au profit du
     * défaut, exactement comme `ModeleChoisi` ignore un nom de modèle invraisemblable.
     *
     * @var array<string, array{min: int, max: int, defaut: int, libelle: string, unite: string, explication: string}>
     */
    public const PARAMETRES = [
        'vigie.horizon_jours' => [
            'min' => 7, 'max' => 180, 'defaut' => 30,
            'libelle' => 'Horizon de la vigie',
            'unite' => 'jours',
            'explication' => 'Combien de jours d’avance Ket regarde pour annoncer les échéances à venir. Plus court, elle alerte tard ; plus long, elle noie l’urgent sous le lointain.',
        ],
        'plan_du_jour.max_lignes' => [
            'min' => 3, 'max' => 20, 'defaut' => 8,
            'libelle' => 'Lignes du programme du jour',
            'unite' => 'lignes',
            'explication' => 'Le nombre de points que Ket retient par section dans le programme du jour.',
        ],
        'fil.max_messages' => [
            'min' => 6, 'max' => 40, 'defaut' => 20,
            'libelle' => 'Profondeur du fil',
            'unite' => 'messages',
            'explication' => 'Jusqu’où Ket remonte dans la conversation. Plus profond, elle suit mieux un échange long — et chaque message coûte plus cher.',
        ],
        'conges.horizon_equipe_jours' => [
            'min' => 7, 'max' => 180, 'defaut' => 60,
            'libelle' => 'Horizon de l’agenda d’équipe',
            'unite' => 'jours',
            'explication' => 'La fenêtre sur laquelle Ket annonce les absences à venir de l’équipe.',
        ],
    ];

    /** Cache des valeurs résolues pour la requête courante. */
    private ?array $cache = null;

    public function __construct(
        private readonly PlateformeParametresRepository $repository,
    ) {
    }

    /** Vide le cache — appelé par le contrôleur après une édition, dans la même requête. */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /** Le même oubli, entre deux messages d'un worker qui dure (cf. docblock). */
    public function reset(): void
    {
        $this->refresh();
    }

    /**
     * Cet outil est-il déclaré au modèle ?
     *
     * FAIL-OPEN, ET C'EST LE BON SENS ICI. Un outil qu'on n'a jamais réglé est
     * actif : l'absence de décision ne doit pas priver les cabinets d'une capacité.
     * Le risque symétrique — un outil actif alors qu'on le croyait coupé — ne coûte
     * que des jetons, et se voit immédiatement sur l'écran de console.
     */
    public function outilActif(string $nom): bool
    {
        return ($this->tout()['outils'][$nom] ?? true) !== false;
    }

    /** Les outils explicitement coupés. @return list<string> */
    public function outilsCoupes(): array
    {
        $coupes = [];
        foreach ($this->tout()['outils'] as $nom => $actif) {
            if ($actif === false) {
                $coupes[] = (string) $nom;
            }
        }
        sort($coupes);

        return $coupes;
    }

    /**
     * La valeur d'un seuil métier, BORNÉE.
     *
     * Rend le défaut du code quand la clé est inconnue, non personnalisée, non
     * entière ou hors bornes. Un appelant n'a donc jamais à se défendre lui-même :
     * il reçoit toujours une valeur utilisable.
     */
    public function parametre(string $clef): int
    {
        $regle = self::PARAMETRES[$clef] ?? null;
        if ($regle === null) {
            throw new \InvalidArgumentException(sprintf('Réglage inconnu : « %s ».', $clef));
        }

        $valeur = $this->tout()['parametres'][$clef] ?? null;
        if (!\is_int($valeur) || $valeur < $regle['min'] || $valeur > $regle['max']) {
            return $regle['defaut'];
        }

        return $valeur;
    }

    /** La valeur effective de tous les seuils. @return array<string, int> */
    public function parametres(): array
    {
        $valeurs = [];
        foreach (array_keys(self::PARAMETRES) as $clef) {
            $valeurs[$clef] = $this->parametre($clef);
        }

        return $valeurs;
    }

    /** Un seuil a-t-il été déplacé par rapport au code ? */
    public function estPersonnalise(string $clef): bool
    {
        return isset(self::PARAMETRES[$clef])
            && $this->parametre($clef) !== self::PARAMETRES[$clef]['defaut'];
    }

    /** Rien n'a été personnalisé : la plateforme se comporte comme le code. */
    public function estVierge(): bool
    {
        $tout = $this->tout();

        return $tout['outils'] === [] && $tout['parametres'] === [];
    }

    /**
     * L'état brut tel qu'il est stocké, normalisé.
     *
     * @return array{outils: array<string, bool>, parametres: array<string, int>}
     */
    public function tout(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $enBase = $this->repository->getSingleton()->getKetReglages() ?? [];

        $outils = [];
        foreach (($enBase['outils'] ?? []) as $nom => $actif) {
            if (\is_string($nom) && \is_bool($actif)) {
                $outils[$nom] = $actif;
            }
        }

        $parametres = [];
        foreach (($enBase['parametres'] ?? []) as $clef => $valeur) {
            if (\is_string($clef) && \is_int($valeur)) {
                $parametres[$clef] = $valeur;
            }
        }

        return $this->cache = ['outils' => $outils, 'parametres' => $parametres];
    }
}
