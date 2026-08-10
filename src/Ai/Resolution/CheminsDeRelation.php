<?php

namespace App\Ai\Resolution;

use Doctrine\ORM\EntityManagerInterface;

/**
 * SOURCE UNIQUE des chemins de relations *-vers-un entre deux entités du workspace.
 *
 * « Les tâches de ce client », « les pistes de Mme Marlette », « les avenants de
 * Kibali » : dans chaque cas il faut savoir COMMENT une entité rejoint une autre —
 * « client » (direct), « piste.client », « cotation.piste.client ». Ce parcours vivait
 * en privé dans RechercherEntitesTool ; il sert désormais aussi à FILTRER la rubrique
 * qu'on ouvre à l'écran, et deux implémentations du même graphe auraient fini par
 * diverger — c'est-à-dire par montrer à l'écran autre chose que ce que le chat annonce.
 *
 * ON RENVOIE TOUS LES CHEMINS, pas seulement le plus court : celui-ci peut emprunter
 * une relation secondaire souvent NULLE (Avenant.pisteDeRenouvellement → Client, en 2
 * segments) tandis que le vrai lien passe plus profond (Avenant.cotation.piste.client,
 * en 3). L'appelant les combine en OR pour ne manquer aucun enregistrement lié.
 *
 * Seuls les segments *-vers-un sont traversés : un segment de collection dupliquerait
 * les lignes paginées.
 */
final class CheminsDeRelation
{
    /** Profondeur maximale explorée (père → fils → petit-fils). */
    public const MAX_PROFONDEUR = 3;

    /**
     * Graphe exploré une seule fois par entité de départ : il ne change pas d'une
     * requête à l'autre, et la recherche d'un rattachement par son nom interroge
     * toutes les cibles d'un coup.
     *
     * @var array<string, array<string, string[]>>
     */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Les chemins pointillés reliant $fqcn à $cibleFqcn, [] s'il n'y en a aucun dans
     * la profondeur permise.
     *
     * @return string[]
     */
    public function vers(string $fqcn, string $cibleFqcn): array
    {
        return $this->parCible($fqcn)[$cibleFqcn] ?? [];
    }

    /**
     * Le chemin le plus COURT vers la cible, ou null. À réserver aux consommateurs qui
     * ne savent exprimer qu'un seul chemin — le filtre d'une liste à l'écran, par
     * exemple, où plusieurs clés seraient combinées en ET et ne ramèneraient rien.
     */
    public function pluCourtVers(string $fqcn, string $cibleFqcn): ?string
    {
        $chemins = $this->vers($fqcn, $cibleFqcn);
        if ($chemins === []) {
            return null;
        }

        usort($chemins, static fn (string $a, string $b) => substr_count($a, '.') <=> substr_count($b, '.'));

        return $chemins[0];
    }

    /**
     * Le MÊME parcours, rendu pour TOUTES les cibles atteignables à la fois : chaque
     * classe joignable en *-vers-un, avec les chemins qui y mènent. Une seule
     * exploration sert alors les deux besoins — la cible connue, et la cible à
     * découvrir (rattachement cherché par son nom).
     *
     * @return array<string, string[]> FQCN de la cible => chemins pointillés distincts
     */
    public function parCible(string $fqcn): array
    {
        if (isset($this->cache[$fqcn])) {
            return $this->cache[$fqcn];
        }

        $parCible = [];
        // DFS sur les chemins SIMPLES : chaque état porte sa propre liste de classes
        // visitées (pas de visite globale) pour explorer les chemins alternatifs, tout
        // en évitant les cycles à l'intérieur d'un même chemin.
        $pile = [[$fqcn, [], [$fqcn => true]]];

        while ($pile !== []) {
            [$classe, $chemin, $visites] = array_pop($pile);
            if (\count($chemin) >= self::MAX_PROFONDEUR) {
                continue;
            }
            $metadata = $this->em->getClassMetadata($classe);
            foreach ($metadata->getAssociationNames() as $name) {
                if (!$metadata->isSingleValuedAssociation($name)) {
                    continue;
                }
                $target = $metadata->getAssociationTargetClass($name);
                $nouveauChemin = [...$chemin, $name];
                $parCible[$target][] = implode('.', $nouveauChemin);
                if (!isset($visites[$target])) {
                    $pile[] = [$target, $nouveauChemin, $visites + [$target => true]];
                }
            }
        }

        foreach ($parCible as $cible => $chemins) {
            $parCible[$cible] = array_values(array_unique($chemins));
        }

        return $this->cache[$fqcn] = $parCible;
    }
}
