<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Agrégations sur les champs STOCKÉS d'une entité du workspace : compte,
 * somme, moyenne, min, max — avec regroupement optionnel (champ scalaire ou
 * relation) et période optionnelle sur un champ date. Complète les autres
 * outils de lecture : les montants CALCULÉS (primes, commissions) relèvent
 * d'indicateur_calcule ; ici on agrège ce qui est en base (répartitions,
 * comptages par statut, sommes de montants stockés…).
 *
 * SÉCURITÉ : champs validés par les métadonnées Doctrine (aucun identifiant
 * libre n'atteint le DQL), scoping entreprise obligatoire (entité sans chemin
 * vers l'entreprise = refus), canRead fail-closed. Sur un champ invalide, la
 * réponse liste les champs disponibles — le modèle se corrige tout seul.
 */
final class StatistiquesTool implements AiToolInterface
{
    private const OPERATIONS = ['compte' => 'COUNT', 'somme' => 'SUM', 'moyenne' => 'AVG', 'min' => 'MIN', 'max' => 'MAX'];
    private const TYPES_NUMERIQUES = ['integer', 'smallint', 'bigint', 'float', 'decimal'];
    private const TYPES_DATE = ['date', 'date_immutable', 'datetime', 'datetime_immutable'];
    /** Plafond de groupes restitués (top-N). */
    private const MAX_GROUPES = 20;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntityManagerInterface $em,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    public function name(): string
    {
        return 'statistiques';
    }

    public function description(): string
    {
        return 'Agrège les champs STOCKÉS d\'une catégorie de données : compte, somme, moyenne, '
            . 'min, max — regroupement optionnel (groupePar : champ ou relation, ex. répartition '
            . 'des clients par groupe, top des pistes par risque) et période optionnelle (champDate '
            . '+ du/au). Pour les montants CALCULÉS (prime, commission…), utiliser '
            . 'indicateur_calcule. En cas de champ invalide, la réponse liste les champs valides.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité à agréger.",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => array_keys(self::OPERATIONS),
                    'description' => 'compte (sans champ) ou somme/moyenne/min/max (champ numérique requis).',
                ],
                'champ' => [
                    'type' => 'string',
                    'description' => 'Champ numérique agrégé (requis sauf pour compte).',
                ],
                'groupePar' => [
                    'type' => 'string',
                    'description' => 'Regroupement : champ scalaire ou relation de l\'entité (optionnel).',
                ],
                'champDate' => [
                    'type' => 'string',
                    'description' => 'Champ date pour la période du/au (optionnel).',
                ],
                'du' => ['type' => 'string', 'description' => 'Début de période AAAA-MM-JJ.'],
                'au' => ['type' => 'string', 'description' => 'Fin de période AAAA-MM-JJ.'],
            ],
            'required' => ['entite', 'operation'],
        ];
    }

    /**
     * Pas de chemin simulé : l'outil exige des arguments structurés (champ,
     * regroupement) que le matching par mots-clés ne sait pas produire — il est
     * réservé au tool-calling du LLM réel.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!isset($labels[$shortName]) || !class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : une agrégation est une lecture comme une autre.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $operation = (string) ($args['operation'] ?? '');
        if (!isset(self::OPERATIONS[$operation])) {
            return AiToolResult::introuvable($operation);
        }

        $metadata = $this->em->getClassMetadata($fqcn);

        // Champ agrégé : requis (et numérique) pour tout sauf compte. Un champ
        // invalide renvoie la liste des champs valides (le modèle se corrige).
        $champ = trim((string) ($args['champ'] ?? ''));
        if ($operation !== 'compte' && !$this->estChampDeType($metadata, $champ, self::TYPES_NUMERIQUES)) {
            return AiToolResult::introuvable(sprintf(
                "champ numérique « %s » invalide pour %s — champs numériques : %s",
                $champ,
                $shortName,
                implode(', ', $this->champsDeType($metadata, self::TYPES_NUMERIQUES)) ?: '(aucun)',
            ));
        }

        $qb = $this->em->getRepository($fqcn)->createQueryBuilder('e');
        if (!$this->scoperEntreprise($qb, $metadata, $scope->entreprise)) {
            return AiToolResult::introuvable(sprintf('%s (non agrégeable)', $labels[$shortName]));
        }

        // Période optionnelle sur un champ date validé.
        $champDate = trim((string) ($args['champDate'] ?? ''));
        $du = trim((string) ($args['du'] ?? ''));
        $au = trim((string) ($args['au'] ?? ''));
        if (($du !== '' || $au !== '') && $champDate === '') {
            $champDate = $this->champsDeType($metadata, self::TYPES_DATE)[0] ?? '';
        }
        if ($champDate !== '') {
            if (!$this->estChampDeType($metadata, $champDate, self::TYPES_DATE)) {
                return AiToolResult::introuvable(sprintf(
                    "champ date « %s » invalide pour %s — champs dates : %s",
                    $champDate,
                    $shortName,
                    implode(', ', $this->champsDeType($metadata, self::TYPES_DATE)) ?: '(aucun)',
                ));
            }
            if ($du !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $du)) {
                $qb->andWhere("e.{$champDate} >= :du")->setParameter('du', $du . ' 00:00:00');
            }
            if ($au !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au)) {
                $qb->andWhere("e.{$champDate} <= :au")->setParameter('au', $au . ' 23:59:59');
            }
        }

        $expression = $operation === 'compte'
            ? 'COUNT(e.id)'
            : sprintf('%s(e.%s)', self::OPERATIONS[$operation], $champ);

        // Regroupement optionnel : champ scalaire, ou relation *-vers-un dont on
        // affiche le libellé (displayField de la cible, repli id).
        $groupePar = trim((string) ($args['groupePar'] ?? ''));
        if ($groupePar !== '') {
            if ($metadata->hasField($groupePar)) {
                $groupeExpr = "e.{$groupePar}";
            } elseif ($metadata->hasAssociation($groupePar) && $metadata->isSingleValuedAssociation($groupePar)) {
                $cibleFqcn = $metadata->getAssociationTargetClass($groupePar);
                $displayField = $this->libelleur->displayField($cibleFqcn) ?? 'id';
                $qb->leftJoin("e.{$groupePar}", 'g');
                $groupeExpr = "g.{$displayField}";
            } else {
                return AiToolResult::introuvable(sprintf(
                    "regroupement « %s » invalide pour %s — champs : %s ; relations : %s",
                    $groupePar,
                    $shortName,
                    implode(', ', $metadata->getFieldNames()),
                    implode(', ', array_filter(
                        $metadata->getAssociationNames(),
                        fn (string $a) => $metadata->isSingleValuedAssociation($a),
                    )) ?: '(aucune)',
                ));
            }

            $lignes = $qb->select("{$groupeExpr} AS groupe", "{$expression} AS valeur")
                ->groupBy($groupeExpr)
                ->orderBy('valeur', 'DESC')
                ->setMaxResults(self::MAX_GROUPES)
                ->getQuery()->getArrayResult();

            return AiToolResult::ok([
                'entite'    => $shortName,
                'libelle'   => $labels[$shortName],
                'operation' => $operation,
                'champ'     => $operation === 'compte' ? null : $champ,
                'groupePar' => $groupePar,
                'groupes'   => array_map(static fn (array $l) => [
                    'groupe' => $l['groupe'] ?? '(sans valeur)',
                    'valeur' => round((float) $l['valeur'], 2),
                ], $lignes),
                'plafond'   => self::MAX_GROUPES,
            ]);
        }

        $valeur = $qb->select($expression)->getQuery()->getSingleScalarResult();

        return AiToolResult::ok([
            'entite'    => $shortName,
            'libelle'   => $labels[$shortName],
            'operation' => $operation,
            'champ'     => $operation === 'compte' ? null : $champ,
            'valeur'    => round((float) $valeur, 2),
        ]);
    }

    /** Le champ existe-t-il avec l'un des types Doctrine attendus ? */
    private function estChampDeType(object $metadata, string $champ, array $types): bool
    {
        return $champ !== ''
            && $metadata->hasField($champ)
            && in_array((string) $metadata->getTypeOfField($champ), $types, true);
    }

    /** @return string[] champs de l'entité portant l'un des types demandés */
    private function champsDeType(object $metadata, array $types): array
    {
        return array_values(array_filter(
            $metadata->getFieldNames(),
            fn (string $f) => in_array((string) $metadata->getTypeOfField($f), $types, true),
        ));
    }

    /**
     * Scoping entreprise OBLIGATOIRE (même logique que JSBDynamicSearchService) :
     * association directe `entreprise`, ou via `invite`. Sans chemin : refus.
     */
    private function scoperEntreprise(QueryBuilder $qb, object $metadata, Entreprise $entreprise): bool
    {
        if ($metadata->hasAssociation('entreprise')) {
            $qb->andWhere('e.entreprise = :entrepriseScope')->setParameter('entrepriseScope', $entreprise);

            return true;
        }
        if ($metadata->hasAssociation('invite')) {
            $qb->join('e.invite', 'scope_invite')
                ->andWhere('scope_invite.entreprise = :entrepriseScope')
                ->setParameter('entrepriseScope', $entreprise);

            return true;
        }

        return false;
    }
}
