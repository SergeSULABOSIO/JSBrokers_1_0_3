<?php

namespace App\Ai\Mutation;

use App\Ai\Tool\AiToolProduisantUnPlan;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * SOURCE UNIQUE de la liste des outils capables de faire apparaître la barre de
 * décision d'un plan d'écriture : elle est DÉRIVÉE du code (les outils marqués
 * AiToolProduisantUnPlan), jamais recopiée à la main.
 *
 * Le prompt système énumérait cette liste en dur dans la règle « JAMAIS DE PLAN NI
 * DE BOUTON FANTÔME ». Elle a dérivé deux fois en production — un outil d'écriture
 * ajouté n'y figurait pas, si bien que le modèle, l'ayant utilisé la veille, ne
 * savait plus qu'il fallait l'APPELER pour obtenir un bouton : il recopiait le
 * tableau du tour précédent. Ce service ferme la dérive : ajouter un outil de plan
 * suffit à ce que le prompt le nomme.
 *
 * Ordre DÉTERMINISTE (preparer_operations d'abord — l'outil générique dont tous
 * les autres dépendent —, puis alphabétique) : le prompt système doit être stable
 * d'un tour à l'autre, l'ordre d'itération d'un tag de conteneur ne l'est pas.
 */
final class OutilsDePlan
{
    /** Outil générique : les autres traduisent leurs arguments et lui délèguent. */
    private const PIVOT = 'preparer_operations';

    /** @var list<string>|null Mémoïsé : le prompt est reconstruit à chaque message. */
    private ?array $noms = null;

    /** @param iterable<AiToolProduisantUnPlan> $outils */
    public function __construct(
        #[AutowireIterator('app.ai_tool')] private readonly iterable $outils,
    ) {
    }

    /**
     * Noms techniques des outils producteurs de plan, dans l'ordre déterministe.
     *
     * @return list<string>
     */
    public function noms(): array
    {
        if ($this->noms !== null) {
            return $this->noms;
        }

        $noms = [];
        foreach ($this->outils as $outil) {
            if ($outil instanceof AiToolProduisantUnPlan) {
                $noms[] = $outil->name();
            }
        }

        $noms = array_values(array_unique($noms));
        sort($noms);

        // Le pivot en tête : c'est celui que le modèle doit appeler par défaut.
        if (($position = array_search(self::PIVOT, $noms, true)) !== false) {
            unset($noms[$position]);
            $noms = array_merge([self::PIVOT], array_values($noms));
        }

        return $this->noms = $noms;
    }

    /**
     * Cet outil est-il de ceux qui peuvent faire apparaître une barre de décision ?
     *
     * Sert au moteur : quand un outil de plan a tourné SANS produire de plan, la
     * réponse doit le signaler au contrôleur — c'est le seul signal fiable du plan
     * décrit en prose mais jamais préparé. La question se pose sur un NOM (celui que
     * le fournisseur a renvoyé), d'où cette forme.
     */
    public function estOutilDePlan(string $nom): bool
    {
        return in_array($nom, $this->noms(), true);
    }

    /**
     * Énumération lisible pour le prompt système : « a, b, c ou d ». Chaîne vide
     * si aucun outil n'est marqué (configuration dégradée) — l'appelant garde
     * alors une phrase valide, sans liste mensongère.
     */
    /**
     * Énumération restreinte aux outils RÉELLEMENT déclarés ce tour-ci.
     *
     * Deux outils de plan sont conditionnels (marquage non renouvelable, signalement
     * de paiement) : hors du périmètre de l'invité, ils ne sont pas déclarés. Les
     * nommer quand même dans la règle anti-plan-fantôme ferait croire au modèle qu'il
     * dispose d'une capacité absente — exactement la situation que cette règle existe
     * pour empêcher.
     *
     * @param list<string> $nomsDeclares
     */
    public function enumerationParmi(array $nomsDeclares): string
    {
        return $this->enumerationDe(array_values(array_intersect($this->noms(), $nomsDeclares)));
    }

    public function enumeration(): string
    {
        return $this->enumerationDe($this->noms());
    }

    /** @param list<string> $noms */
    private function enumerationDe(array $noms): string
    {
        if ($noms === []) {
            return '';
        }
        if (count($noms) === 1) {
            return $noms[0];
        }

        $dernier = array_pop($noms);

        return implode(', ', $noms) . ' ou ' . $dernier;
    }
}
