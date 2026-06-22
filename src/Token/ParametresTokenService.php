<?php

namespace App\Token;

use App\Repository\PlateformeParametresRepository;

/**
 * @file Fournisseur runtime du plan tarifaire des tokens.
 * @description Expose les mêmes valeurs que App\Token\TokenPricing mais en
 * lecture DYNAMIQUE depuis la BDD (entité PlateformeParametres, éditable via la
 * Console). Chaque champ retombe sur la constante TokenPricing correspondante
 * lorsqu'il n'a pas été personnalisé : tant qu'aucun agent ne modifie le plan,
 * le comportement est strictement identique à l'ancien code statique.
 *
 * Le singleton est mis en cache pour la durée de la requête (les paramètres ne
 * changent pas en cours de requête).
 */
class ParametresTokenService
{
    /** Cache des valeurs résolues pour la requête courante. */
    private ?array $cache = null;

    public function __construct(private PlateformeParametresRepository $repository)
    {
    }

    /** Vide le cache (utile après une édition du plan dans la même requête). */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /**
     * @return array{packs:array, freeAllowance:int, freeWindowHours:int, readWeight:int, defaultWriteWeight:int, writeWeights:array, usdPerToken:float}
     */
    private function values(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $p = $this->repository->getSingleton();

        return $this->cache = [
            'packs'              => $p->getPacks()              ?? TokenPricing::PACKS,
            'freeAllowance'      => $p->getFreeAllowance()      ?? TokenPricing::FREE_ALLOWANCE,
            'freeWindowHours'    => $p->getFreeWindowHours()    ?? TokenPricing::FREE_WINDOW_HOURS,
            'readWeight'         => $p->getReadWeight()         ?? TokenPricing::READ_WEIGHT,
            'defaultWriteWeight' => $p->getDefaultWriteWeight() ?? TokenPricing::DEFAULT_WRITE_WEIGHT,
            'writeWeights'       => $p->getWriteWeights()       ?? TokenPricing::WRITE_WEIGHTS,
            'usdPerToken'        => $p->getUsdPerToken()        ?? TokenPricing::USD_PER_TOKEN,
        ];
    }

    /** Paquets prépayés : { clé: { tokens, price } }. */
    public function packs(): array
    {
        return $this->values()['packs'];
    }

    /** Définition d'un paquet ou null s'il n'existe pas. */
    public function pack(string $key): ?array
    {
        return $this->packs()[$key] ?? null;
    }

    public function freeAllowance(): int
    {
        return $this->values()['freeAllowance'];
    }

    public function freeWindowHours(): int
    {
        return $this->values()['freeWindowHours'];
    }

    public function readWeight(): int
    {
        return $this->values()['readWeight'];
    }

    public function defaultWriteWeight(): int
    {
        return $this->values()['defaultWriteWeight'];
    }

    /** Poids en écriture d'une entité (par FQCN), avec repli sur le poids par défaut. */
    public function weightFor(string $fqcn): int
    {
        return $this->values()['writeWeights'][$fqcn] ?? $this->defaultWriteWeight();
    }

    /**
     * Carte complète des poids d'écriture par entité (FQCN => poids).
     *
     * @return array<string, int>
     */
    public function writeWeights(): array
    {
        return $this->values()['writeWeights'];
    }

    public function usdPerToken(): float
    {
        return $this->values()['usdPerToken'];
    }

    /** Convertit un nombre de tokens consommés en coût USD selon le taux courant. */
    public function costUsd(int $tokens): float
    {
        return $tokens * $this->usdPerToken();
    }
}
