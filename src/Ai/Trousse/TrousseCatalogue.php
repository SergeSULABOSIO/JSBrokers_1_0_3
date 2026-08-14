<?php

namespace App\Ai\Trousse;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolConditionnel;
use App\Ai\Tool\AiToolInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * SOURCE UNIQUE des outils réellement déclarés au modèle pour un tour donné.
 *
 * Tout part d'ici : les déclarations envoyées au fournisseur, la section
 * d'aiguillage du prompt système, le catalogue condensé soumis au routeur, et
 * l'énumération des outils de plan. C'est ce qui garantit la contrainte dure du
 * chantier — le prompt ne peut pas nommer un outil absent du tour, puisque les deux
 * sont dérivés du MÊME tableau.
 *
 * Trois filtres, dans cet ordre :
 *  1. la TROUSSE (lecture / écriture) — économie de débit ;
 *  2. AiToolConditionnel — l'outil a-t-il un sens dans ce périmètre et ce fil ;
 *  3. rien d'autre : la sécurité reste dans execute(), fail-closed.
 *
 * ORDRE DÉTERMINISTE. L'ordre d'itération d'un tag de conteneur ne l'est pas, or le
 * préfixe envoyé au fournisseur doit être stable d'un tour à l'autre : c'est lui qui
 * est mis en cache (75 % de hit observés). Un ordre qui bouge, c'est un cache qui ne
 * sert jamais.
 */
final class TrousseCatalogue
{
    /** @var iterable<AiToolInterface> */
    private iterable $outils;

    /** @var array<string, list<AiToolInterface>> mémoïsation par trousse (le prompt et les déclarations se construisent à quelques millisecondes d'intervalle) */
    private array $cache = [];

    public function __construct(
        #[AutowireIterator('app.ai_tool')] iterable $outils,
    ) {
        $this->outils = $outils;
    }

    /**
     * Les outils déclarés pour cette trousse et ce périmètre, dans l'ordre.
     *
     * @return list<AiToolInterface>
     */
    public function outilsDe(Trousse $trousse, AiScope $scope): array
    {
        $cle = $trousse->value . '|' . ($scope->invite->getId() ?? 0) . '|' . ($scope->conversation?->getId() ?? 0);
        if (isset($this->cache[$cle])) {
            return $this->cache[$cle];
        }

        $retenus = [];
        foreach ($this->outils as $outil) {
            // La trousse de COMPRÉHENSION est une LISTE BLANCHE, à l'inverse des deux
            // autres qui écartent. Elle doit le rester : un outil ajouté au projet
            // n'a aucune raison d'atterrir dans la phase la plus légère du moteur
            // sans que quelqu'un l'ait décidé.
            if ($trousse === Trousse::COMPREHENSION) {
                if (!$outil instanceof AiToolDeComprehension) {
                    continue;
                }
            } elseif (!$trousse->estEcriture() && $outil instanceof AiToolEcriture) {
                continue;
            }
            if ($outil instanceof AiToolConditionnel && !$outil->estDisponible($scope)) {
                continue;
            }
            $retenus[$outil->name()] = $outil;
        }

        ksort($retenus);

        return $this->cache[$cle] = array_values($retenus);
    }

    /**
     * Noms techniques déclarés pour cette trousse — l'ensemble que le prompt a le
     * droit de nommer, et rien de plus.
     *
     * @return list<string>
     */
    public function nomsDe(Trousse $trousse, AiScope $scope): array
    {
        return array_map(static fn (AiToolInterface $o) => $o->name(), $this->outilsDe($trousse, $scope));
    }

    /**
     * Tous les outils, toutes trousses confondues, sans filtre de périmètre.
     * Réservé au CATALOGUE CONDENSÉ soumis au routeur : c'est précisément la liste
     * complète qu'il doit voir pour choisir, et elle ne coûte qu'une ligne par outil.
     *
     * @return list<AiToolInterface>
     */
    public function tous(): array
    {
        $tous = [];
        foreach ($this->outils as $outil) {
            $tous[$outil->name()] = $outil;
        }
        ksort($tous);

        return array_values($tous);
    }

    /** L'outil porte-t-il le marqueur d'écriture ? */
    public function estOutilDEcriture(string $nom): bool
    {
        foreach ($this->outils as $outil) {
            if ($outil->name() === $nom) {
                return $outil instanceof AiToolEcriture;
            }
        }

        return false;
    }
}
