<?php

namespace App\Echange\Service;

/**
 * LE RÉSULTAT D'UN CONTRÔLE À BLANC : ce qui serait fait, et ce qui empêche de le faire.
 *
 * Source UNIQUE pour trois consommateurs qui doivent dire la même chose — l'écran, le
 * classeur `_RAPPORT` téléchargeable, et l'assistant. Chacun le met en forme à sa
 * façon ; aucun ne recompte.
 *
 * LA SYNTHÈSE EST UNE PROMESSE. « 12 créations, 3 modifications » est ce que
 * l'utilisateur lit avant de confirmer : ce doit être exactement ce que l'exécution
 * fera, sans quoi la confirmation ne porte sur rien.
 */
final class RapportDeControle
{
    /** @var Anomalie[] */
    private array $anomalies = [];

    /** @var array<string, array{libelle: string, creations: int, modifications: int, suppressions: int, erreurs: int}> */
    private array $synthese = [];

    private int $lignesLues = 0;

    /**
     * Enregistre une anomalie, et rien de plus.
     *
     * Le décompte par ressource passe par {@see compterErreur()}, appelé par qui sait
     * de QUELLE ressource il parle. Le déduire ici du nom de feuille marcherait tant
     * qu'aucun libellé ne se répète — c'est-à-dire jusqu'au premier qui se répète.
     */
    public function ajouter(Anomalie $anomalie): void
    {
        $this->anomalies[] = $anomalie;
    }

    public function declarerRessource(string $code, string $libelle): void
    {
        $this->synthese[$code] ??= [
            'libelle' => $libelle,
            'creations' => 0,
            'modifications' => 0,
            'suppressions' => 0,
            'erreurs' => 0,
        ];
    }

    public function compter(string $code, string $operation): void
    {
        if (!isset($this->synthese[$code])) {
            return;
        }

        $cle = match ($operation) {
            'create' => 'creations',
            'edit'   => 'modifications',
            'delete' => 'suppressions',
            default  => null,
        };
        if ($cle !== null) {
            ++$this->synthese[$code][$cle];
        }
    }

    public function compterErreur(string $code): void
    {
        if (isset($this->synthese[$code])) {
            ++$this->synthese[$code]['erreurs'];
        }
    }

    public function compterLignes(int $nombre): void
    {
        $this->lignesLues += $nombre;
    }

    public function lignesLues(): int
    {
        return $this->lignesLues;
    }

    /** @return Anomalie[] */
    public function anomalies(): array
    {
        return $this->anomalies;
    }

    /** @return Anomalie[] */
    public function erreurs(): array
    {
        return array_values(array_filter($this->anomalies, static fn (Anomalie $a) => $a->bloque()));
    }

    /**
     * L'import peut-il être confirmé ?
     *
     * Une seule erreur bloquante suffit à répondre non. Il n'existe pas d'import
     * partiel : accepter « le reste » laisserait la base dans un état que personne
     * n'a décrit ni voulu.
     */
    public function confirmable(): bool
    {
        return $this->erreurs() === [];
    }

    public function nbCreations(): int
    {
        return array_sum(array_column($this->synthese, 'creations'));
    }

    public function nbModifications(): int
    {
        return array_sum(array_column($this->synthese, 'modifications'));
    }

    public function nbSuppressions(): int
    {
        return array_sum(array_column($this->synthese, 'suppressions'));
    }

    /**
     * Forme persistée et transmise — celle que l'écran affiche, que le classeur
     * `_RAPPORT` rend et que l'assistant raconte.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'confirmable'    => $this->confirmable(),
            'lignes_lues'    => $this->lignesLues,
            'creations'      => $this->nbCreations(),
            'modifications'  => $this->nbModifications(),
            'suppressions'   => $this->nbSuppressions(),
            'nb_erreurs'     => count($this->erreurs()),
            'nb_anomalies'   => count($this->anomalies),
            'synthese'       => array_values(array_map(
                static fn (array $ligne, string $code) => ['code' => $code] + $ligne,
                $this->synthese,
                array_keys($this->synthese),
            )),
            'anomalies'      => array_map(static fn (Anomalie $a) => $a->toArray(), $this->anomalies),
        ];
    }

    /** Reconstruit un rapport depuis sa forme persistée (lecture seule). */
    public static function depuisArray(array $donnees): self
    {
        $rapport = new self();
        $rapport->lignesLues = (int) ($donnees['lignes_lues'] ?? 0);

        foreach ($donnees['synthese'] ?? [] as $ligne) {
            $code = (string) ($ligne['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $rapport->synthese[$code] = [
                'libelle'       => (string) ($ligne['libelle'] ?? $code),
                'creations'     => (int) ($ligne['creations'] ?? 0),
                'modifications' => (int) ($ligne['modifications'] ?? 0),
                'suppressions'  => (int) ($ligne['suppressions'] ?? 0),
                'erreurs'       => (int) ($ligne['erreurs'] ?? 0),
            ];
        }

        foreach ($donnees['anomalies'] ?? [] as $a) {
            $rapport->anomalies[] = new Anomalie(
                (string) ($a['gravite'] ?? Anomalie::ERREUR),
                (string) ($a['code'] ?? ''),
                (string) ($a['message'] ?? ''),
                $a['feuille'] ?? null,
                isset($a['ligne']) ? (int) $a['ligne'] : null,
                $a['colonne'] ?? null,
            );
        }

        return $rapport;
    }
}
