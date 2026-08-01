<?php

namespace App\Ai;

use App\Services\Canvas\CalculationProvider;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Sérialise la fiche d'un enregistrement pour l'assistant IA : attributs
 * stockés au contrat `list:read` (le même que les listes du workspace),
 * élagués des valeurs vides — chaque champ non rempli coûterait des tokens
 * pour rien. Partagé entre LireFicheTool et les objets attachés au contexte
 * d'une conversation (AiContextBuilder).
 *
 * `ficheEnrichie()` ajoute les ATTRIBUTS CALCULÉS de l'entité (stratégies
 * d'indicateurs de l'application, celles-là mêmes qui alimentent les écrans).
 * Sans eux, plusieurs champs sont illisibles pour le modèle : un `redevable: 1`
 * ou un `modeCalcul: 0` ne veulent rien dire, et un `pourcentage: 0.15` est
 * ambigu — alors que « Redevable : Assureur », « Mode de calcul : Pourcentage
 * sur chargement » et « 15 % » sont sans équivoque. Une entité que Ket ne
 * comprend pas est une étape qu'elle risque d'abandonner en silence.
 *
 * TOUS les indicateurs, JAMAIS de troncature. Les fiches des objets attachés au
 * chat étaient auparavant plafonnées à 15 indicateurs, le reste étant remplacé
 * par un marqueur « analyseApprofondie » invitant Ket à proposer d'approfondir.
 * Une fiche partielle par construction est une invitation permanente à combler
 * les trous : c'est ainsi qu'un avenant dont la suite n'était pas décrite s'est
 * vu déclaré « pas encore renouvelé » alors qu'un avenant lui succédait (bug
 * KIN AVIA). Le prompt système peut désormais affirmer que l'état calculé de
 * l'objet est COMPLET — ce qui retire à Ket toute excuse de deviner.
 *
 * Réservé aux lectures CIBLÉES (une fiche, les valeurs d'un référentiel) : les
 * stratégies interrogent la base, on ne les déclenche pas sur des listes.
 */
final class FicheNormaliseur
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly CalculationProvider $calculationProvider,
    ) {
    }

    public function fiche(object $entity): array
    {
        return $this->nettoyer((array) $this->normalizer->normalize($entity, null, ['groups' => ['list:read']]));
    }

    /**
     * Fiche + attributs calculés de l'entité (libellés des champs codés, montants
     * dérivés, pourcentages en clair). Contrairement à la fiche brute, elle porte
     * l'ÉTAT réel de l'objet — sans quoi Ket, ne voyant aucun avenant sur une
     * Cotation, la croit « non validée » et parle de montants « potentiels » alors
     * que la police existe (RÈGLE isBound).
     *
     * Sert AUSSI de fiche des objets ATTACHÉS au chat, et donc des infobulles des
     * puces de contexte (AiContextBuilder::objetsAttaches) : l'utilisateur voit
     * exactement ce que l'assistante capture.
     *
     * Best-effort : une stratégie en échec ne doit jamais priver le modèle de la
     * fiche elle-même.
     */
    public function ficheEnrichie(object $entity): array
    {
        $fiche = $this->fiche($entity);

        try {
            $calcules = $this->calculationProvider->getIndicateursSpecifics($entity);
        } catch (\Throwable) {
            return $fiche;
        }

        // Les attributs stockés priment : un calcul ne masque jamais une valeur réelle.
        return $fiche + $this->nettoyer($calcules);
    }

    /**
     * Élague les valeurs vides, RÉCURSIVEMENT : une relation normalisée
     * (ex. le chargement de base d'un type de revenu) traîne ses propres champs
     * nuls, qui coûteraient des tokens sans rien apprendre au modèle.
     *
     * @param array<string, mixed> $data
     */
    private function nettoyer(array $data): array
    {
        $propre = [];
        foreach ($data as $cle => $valeur) {
            if (is_array($valeur)) {
                $valeur = $this->nettoyer($valeur);
            }
            if ($valeur === null || $valeur === '' || $valeur === []) {
                continue;
            }
            $propre[$cle] = $valeur;
        }

        return $propre;
    }
}
