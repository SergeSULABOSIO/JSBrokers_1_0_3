<?php

namespace App\Service\Workspace;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Analyse — à partir des SEULES métadonnées Doctrine (aucune règle codée en
 * dur) — l'impact réel d'une suppression d'entité :
 *  - les enfants supprimés EN CASCADE (cascade: remove / orphanRemoval), avec
 *    leur compte, pour que l'utilisateur mesure la portée AVANT de confirmer ;
 *  - les BLOCAGES : une clé étrangère entrante NON NULLABLE et sans
 *    onDelete=SET NULL/CASCADE qui, si des lignes la référencent, ferait échouer
 *    la suppression (ex. Portefeuille.gestionnaire) — Ket refuse alors proprement
 *    plutôt que de provoquer une erreur SQL.
 *
 * Advisoire : la transaction d'exécution reste le filet de sécurité final
 * (rollback global). En cas d'anomalie d'analyse, on renvoie un impact vide
 * (fail-safe) — la transaction protège de toute façon.
 *
 * API Doctrine ORM 3.x : les mappings sont des objets AssociationMapping
 * (isCascadeRemove(), isManyToOne(), isOwningSide(), ->orphanRemoval,
 * ->targetEntity, ->inversedBy, ->joinColumns[JoinColumnMapping]).
 */
class CascadeImpactAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function analyserSuppression(object $entity): CascadeImpact
    {
        try {
            $meta = $this->em->getClassMetadata($entity::class);
        } catch (\Throwable) {
            return new CascadeImpact();
        }

        return new CascadeImpact(
            enfants: $this->enfantsEnCascade($entity, $meta),
            blocages: $this->blocagesFkEntrantes($entity, $meta),
        );
    }

    /**
     * PROFONDEUR MAXIMALE DE PARCOURS DES CASCADES.
     *
     * S'arrêter à une profondeur mentait par omission sur les cas qui comptent :
     * supprimer une opportunité annonçait « 2 Cotation liée(s) » alors que
     * disparaissaient aussi leurs avenants, leurs échéanciers et les paiements de
     * prime déjà déclarés. On ne peut pas demander à quelqu'un de valider ce qu'on
     * lui cache. Trois niveaux couvrent les chaînes réelles du domaine
     * (Piste → Cotation → Tranche → PaiementPrime) sans parcours déraisonnable.
     */
    private const PROFONDEUR_MAX = 3;

    /**
     * Enfants supprimés en cascade, AGRÉGÉS PAR TYPE sur toute la chaîne.
     *
     * Le parcours suit aussi les associations to-one en cascade (ex.
     * Piste::avenantDeBase) : ce sont elles qui réservent les mauvaises surprises,
     * puisqu'elles remontent vers des entités que l'utilisateur croyait à l'abri.
     *
     * @return array<int, array{entite: string, libelle: string, count: int}>
     */
    private function enfantsEnCascade(object $entity, ClassMetadata $meta): array
    {
        $comptes = [];
        // Les relations en cascade forment un GRAPHE, pas un arbre : Cotation::piste
        // renvoie vers sa Piste, Avenant::cotation vers sa Cotation. Sans mémoire des
        // objets déjà vus, le parcours boucle indéfiniment.
        $vus = new \SplObjectStorage();
        $vus->attach($entity);

        $this->parcourirCascades($entity, $meta, $comptes, $vus, 1);

        $impacts = [];
        foreach ($comptes as $short => $count) {
            $impacts[] = ['entite' => $short, 'libelle' => $short, 'count' => $count];
        }

        return $impacts;
    }

    /**
     * @param array<string, int> $comptes accumulateur entité => nombre
     */
    private function parcourirCascades(
        object $entity,
        ClassMetadata $meta,
        array &$comptes,
        \SplObjectStorage $vus,
        int $profondeur,
    ): void {
        if ($profondeur > self::PROFONDEUR_MAX) {
            return;
        }

        // Liens que le moteur COUPE avant de supprimer : ils ne détruiront rien, et
        // les annoncer promettrait la disparition d'une police qui survivra.
        $proteges = LiensProteges::champs($entity);

        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$mapping->isCascadeRemove() && empty($mapping->orphanRemoval)) {
                continue;
            }
            if (array_key_exists($field, $proteges)) {
                continue;
            }

            $short = $this->shortName((string) $mapping->targetEntity);
            foreach ($this->valeursAssociation($entity, $meta, $field) as $enfant) {
                if ($vus->contains($enfant)) {
                    continue;
                }
                $vus->attach($enfant);
                $comptes[$short] = ($comptes[$short] ?? 0) + 1;

                try {
                    $metaEnfant = $this->em->getClassMetadata($enfant::class);
                } catch (\Throwable) {
                    continue;
                }
                $this->parcourirCascades($enfant, $metaEnfant, $comptes, $vus, $profondeur + 1);
            }
        }
    }

    /**
     * Éléments d'une association, sous forme d'objets (collection : ses membres ;
     * to-one : l'objet ou rien). Fail-safe : une association illisible est ignorée.
     *
     * @return array<int, object>
     */
    private function valeursAssociation(object $entity, ClassMetadata $meta, string $field): array
    {
        try {
            $value = $meta->getFieldValue($entity, $field);
        } catch (\Throwable) {
            return [];
        }
        if ($value === null) {
            return [];
        }
        if (is_object($value) && !$value instanceof \Traversable) {
            return [$value];
        }

        $elements = [];
        try {
            foreach ($value as $element) {
                if (is_object($element)) {
                    $elements[] = $element;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $elements;
    }

    /**
     * Clés étrangères ENTRANTES restrictives : une autre entité référence
     * celle-ci via une colonne non nullable sans onDelete permissif, ET sans que
     * la cible ne les supprime déjà en cascade. Si des lignes existent, la
     * suppression échouerait → blocage explicite.
     *
     * @return string[]
     */
    private function blocagesFkEntrantes(object $entity, ClassMetadata $cibleMeta): array
    {
        if ($cibleMeta->getIdentifierValues($entity) === []) {
            return [];
        }
        $classe = $entity::class;
        $blocages = [];

        try {
            $toutes = $this->em->getMetadataFactory()->getAllMetadata();
        } catch (\Throwable) {
            return [];
        }

        foreach ($toutes as $autre) {
            if (!$autre instanceof ClassMetadata) {
                continue;
            }
            foreach ($autre->getAssociationMappings() as $field => $mapping) {
                if (($mapping->targetEntity ?? null) !== $classe || !$mapping->isOwningSide()) {
                    continue;
                }
                // La cible supprime-t-elle déjà ces lignes en cascade (relation
                // inverse orphanRemoval/cascade remove) ? Alors pas de blocage.
                if ($this->cibleSupprimeEnCascade($cibleMeta, $mapping)) {
                    continue;
                }
                if (!$this->joinColumnRestrictive($mapping)) {
                    continue;
                }

                $count = $this->compterReferences($autre->getName(), $field, $entity);
                if ($count > 0) {
                    $blocages[] = sprintf(
                        'Suppression impossible : %d %s y est/sont rattaché(e)(s) par un lien obligatoire. Détachez-les d’abord.',
                        $count,
                        $this->shortName($autre->getName()),
                    );
                }
            }
        }

        return $blocages;
    }

    /** La cible efface-t-elle déjà ces références via sa relation inverse (cascade/orphan) ? */
    private function cibleSupprimeEnCascade(ClassMetadata $cibleMeta, object $mapping): bool
    {
        $inverse = $mapping->inversedBy ?? null;
        if ($inverse === null || !$cibleMeta->hasAssociation($inverse)) {
            return false;
        }
        try {
            $inv = $cibleMeta->getAssociationMapping($inverse);
        } catch (\Throwable) {
            return false;
        }

        return $inv->isCascadeRemove() || !empty($inv->orphanRemoval);
    }

    /** La colonne de jointure est-elle non nullable et sans onDelete permissif ? */
    private function joinColumnRestrictive(object $mapping): bool
    {
        $joinColumns = $mapping->joinColumns ?? [];
        foreach ($joinColumns as $jc) {
            $nullable = $jc->nullable ?? true;      // NULL (non défini) => nullable par défaut
            $onDelete = strtoupper((string) ($jc->onDelete ?? ''));
            if ($nullable === false && $onDelete !== 'SET NULL' && $onDelete !== 'CASCADE') {
                return true;
            }
        }

        return false;
    }

    /** Compte les lignes de $ownerClass dont l'association $field pointe vers $entity. */
    private function compterReferences(string $ownerClass, string $field, object $entity): int
    {
        try {
            return (int) $this->em->createQueryBuilder()
                ->select('COUNT(o.id)')
                ->from($ownerClass, 'o')
                ->where(sprintf('o.%s = :cible', $field))
                ->setParameter('cible', $entity)
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            return 0; // fail-safe : la transaction reste le filet de sécurité.
        }
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
