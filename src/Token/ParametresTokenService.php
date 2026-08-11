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
     * @return array{packs:array, freeAllowance:int, freeWindowHours:int, readWeight:int, defaultWriteWeight:int, writeWeights:array, usdPerToken:float, documentBase:int, documentParPage:int, documentCaracteresParPage:int, documentFormats:array<string,float>}
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

            // Documents produits par l'IA. Chaque scalaire porte SON repli : régler
            // la base ne doit pas emporter le prix de la page.
            'documentBase'              => max(0, $p->getDocumentBase()    ?? TokenPricing::DOCUMENT_BASE),
            'documentParPage'           => max(0, $p->getDocumentParPage() ?? TokenPricing::DOCUMENT_PAR_PAGE),
            // max(1, …) : garde anti-division par zéro, même esprit que windowSeconds().
            'documentCaracteresParPage' => max(1, $p->getDocumentCaracteresParPage() ?? TokenPricing::DOCUMENT_CARACTERES_PAR_PAGE),
            'documentFormats'           => self::fusionnerFormats($p->getDocumentFormats()),
        ];
    }

    /**
     * Repli CHAMP PAR CHAMP des multiplicateurs de format. On part TOUJOURS de la
     * constante et on superpose les valeurs personnalisées, clé par clé — jamais un
     * `??` sur la carte entière.
     *
     * La nuance a des conséquences réelles : `writeWeights`, juste au-dessus,
     * REMPLACE délibérément (une entité qu'un agent retire de la carte doit rester
     * retirée — sa suppression y est un acte de sens). Les formats, eux, sont une
     * énumération technique fermée, miroir de l'enum servie par la route : un format
     * ajouté au code doit apparaître même sur une plateforme déjà personnalisée,
     * sans quoi il serait facturé au multiplicateur neutre, en silence.
     *
     * @param array<string, mixed>|null $personnalises
     *
     * @return array<string, float>
     */
    private static function fusionnerFormats(?array $personnalises): array
    {
        $propres = [];
        foreach ($personnalises ?? [] as $format => $multiplicateur) {
            if (!is_numeric($multiplicateur)) {
                continue;
            }
            $propres[mb_strtolower(trim((string) $format))] = max(0.0, (float) $multiplicateur);
        }

        return array_replace(TokenPricing::DOCUMENT_FORMATS, $propres);
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

    /** Documents IA : coût fixe d'une production, en tokens. */
    public function documentBase(): int
    {
        return $this->values()['documentBase'];
    }

    /** Documents IA : coût d'une page facturée, en tokens. */
    public function documentParPage(): int
    {
        return $this->values()['documentParPage'];
    }

    /** Documents IA : taille d'une page facturée, en caractères (toujours ≥ 1). */
    public function documentCaracteresParPage(): int
    {
        return $this->values()['documentCaracteresParPage'];
    }

    /**
     * Documents IA : carte complète des multiplicateurs, défauts du code fusionnés
     * avec la personnalisation console.
     *
     * @return array<string, float>
     */
    public function documentFormats(): array
    {
        return $this->values()['documentFormats'];
    }

    /** Multiplicateur d'un format, avec repli NEUTRE pour un format inconnu. */
    public function documentMultiplicateur(string $format): float
    {
        return $this->documentFormats()[mb_strtolower(trim($format))]
            ?? TokenPricing::DOCUMENT_MULTIPLICATEUR_DEFAUT;
    }

    /** Le format est-il présent dans la carte tarifaire courante ? */
    public function estFormatDocumentConnu(string $format): bool
    {
        return isset($this->documentFormats()[mb_strtolower(trim($format))]);
    }
}
