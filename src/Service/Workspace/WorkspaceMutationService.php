<?php

namespace App\Service\Workspace;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Token\TokenAccountService;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Cœur DÉTERMINISTE d'écriture/suppression pour l'assistant IA (Ket).
 *
 * Rejoue EXACTEMENT les gardes du CRUD HTTP (ControllerUtilsTrait) mais de façon
 * appelable hors requête de formulaire, en réutilisant les briques existantes
 * (DRY) : WorkspaceAccessResolver (fail-closed), JSBDynamicSearchService
 * (scoping entreprise), le FormType de l'entité (transformation + validation +
 * liaison des relations), TokenAccountService (métrage) et CascadeImpactAnalyzer
 * (garde de suppression). Le LLM n'exécute rien : il assemble une intention, ce
 * service la valide et l'exécute.
 *
 * Deux usages :
 *  - analyserOperation() : DRY-RUN pur (aucune écriture) — sert le tool
 *    preparer_operations (droits, scope, champs manquants/invalides, cascades) ;
 *  - executer() : exécution réelle, à appeler DANS une transaction pilotée par
 *    l'appelant (endpoint execute) ; lève MutationException → rollback global.
 *
 * commitWrite()/commitDelete() sont les deux plus petites unités partagées avec
 * ControllerUtilsTrait (métrage + persistance / suppression), pour un point de
 * passage unique.
 */
class WorkspaceMutationService
{
    /** Types de champs scalaires pilotables par l'IA (miroir de PrefillWhitelist). */
    private const TYPES_SCALAIRES = [
        'string', 'text', 'integer', 'smallint', 'bigint', 'float', 'decimal',
        'boolean', 'date', 'date_immutable', 'datetime', 'datetime_immutable',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormFactoryInterface $formFactory,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
        private readonly JSBDynamicSearchService $searchService,
        private readonly CascadeImpactAnalyzer $cascadeAnalyzer,
        private readonly ChampsObligatoiresInspector $champsInspector,
        private readonly FormTreeInspector $formTreeInspector,
    ) {
    }

    // ───────────────────────── Unités partagées (DRY) ─────────────────────────

    /**
     * Métrage (écriture) puis persistance + flush — point de passage unique
     * réutilisé par ControllerUtilsTrait et par l'exécuteur IA.
     *
     * @throws \App\Token\InsufficientTokensException si le solde du propriétaire est épuisé.
     */
    public function commitWrite(object $entity, Entreprise $entreprise, ?Utilisateur $acteur): void
    {
        $this->tokenAccountService->meterWrite($entity, $entreprise, $acteur);
        $this->em->persist($entity);
        $this->em->flush();
    }

    /** Suppression + flush — point de passage unique (contrôle d'accès à la charge de l'appelant). */
    public function commitDelete(object $entity): void
    {
        $this->em->remove($entity);
        $this->em->flush();
    }

    // ───────────────────────────── Chemin IA ──────────────────────────────────

    /**
     * DRY-RUN d'une opération : n'écrit RIEN. Renvoie un diagnostic structuré.
     *
     * @return array{
     *     ok: bool, statut: string, entite: string, libelle: string,
     *     cible: ?string, manquants: array<string,string[]>, impacts: string[], bloque: bool
     * }
     */
    public function analyserOperation(MutationOperation $op, AiScope $scope): array
    {
        $labels = $this->accessResolver->libellesEntites();
        $libelle = $labels[$op->entityShortName] ?? $op->entityShortName;
        $base = [
            'ok' => false, 'statut' => 'ok', 'entite' => $op->entityShortName,
            'libelle' => $libelle, 'cible' => null, 'manquants' => [], 'impacts' => [], 'bloque' => false,
            'portefeuille' => null,
        ];

        // Périmètre + allowlist (fail-closed).
        if (!$this->estAutorise($op, $scope)) {
            return ['ok' => false, 'statut' => 'hors_perimetre'] + $base;
        }
        // Forme de l'opération.
        if (!$op->estValide()) {
            return ['ok' => false, 'statut' => 'introuvable'] + $base;
        }

        // Cible (edit/delete) résolue STRICTEMENT dans l'entreprise du scope.
        $cible = null;
        if (!$op->isCreate()) {
            $cible = $this->trouverCible($op, $scope);
            if ($cible === null) {
                return ['ok' => false, 'statut' => 'introuvable'] + $base;
            }
            $base['cible'] = $this->libelleInstance($cible);
        }

        // Suppression : impacts de cascade + blocages FK.
        if ($op->isDelete()) {
            $impact = $this->cascadeAnalyzer->analyserSuppression($cible);
            $base['impacts'] = $impact->descriptions();
            $base['bloque'] = $impact->estBloque();

            return ['ok' => !$impact->estBloque(), 'statut' => $impact->estBloque() ? 'bloque' : 'ok'] + $base;
        }

        // Create/edit : validation FormType sur une COPIE (jamais l'entité gérée —
        // sûr vis-à-vis d'un flush ultérieur dans la même requête).
        $copie = $op->isCreate() ? $this->nouvelleEntite($op, $scope) : clone $cible;

        // Création : champs OBLIGATOIRES (non-nullables sans défaut) manquants —
        // détectés AVANT le form (que clearMissing=false ne validerait pas), afin
        // que Ket les demande plutôt que de provoquer une erreur SQL à l'exécution.
        $manquants = [];
        if ($op->isCreate()) {
            // Portefeuille (auto si unique, sinon à demander) + champs obligatoires.
            $manquants = $this->resoudrePortefeuille($copie, $op, $scope) + $this->champsRequisManquants($copie, $op);
            if ($manquants === [] && method_exists($copie, 'getPortefeuille') && $copie->getPortefeuille() !== null) {
                $base['portefeuille'] = $this->libelleInstance($copie->getPortefeuille());
            }
        }

        // La tête n'est validée par son FormType que si elle porte des champs à
        // écrire (une édition « conteneur », dont seules des collections changent,
        // n'a pas de champ propre à valider et ne re-persiste pas la tête).
        if ($manquants === [] && $this->estEcritureReelle($op)) {
            $form = $this->construireEtSoumettre($copie, $op);
            if (!$form->isValid()) {
                $manquants = $this->erreurs($form);
            }
        }

        // Collections imbriquées (récursif) : parité formulaire avec l'UI. Le
        // chiffrage (facturables) n'est PAS recalculé ici : source unique =
        // facturablesArbre() (utilisée à l'identique par le budget et l'exécution).
        $impacts = [];
        $bloque = false;
        $this->analyserCollections($copie, $op, $scope, $op->entityShortName, '', 0, $manquants, $impacts, $bloque);

        $base['impacts'] = array_merge($base['impacts'], $impacts);

        if ($bloque) {
            return ['ok' => false, 'statut' => 'bloque', 'manquants' => $manquants] + $base;
        }
        if ($manquants !== []) {
            return ['ok' => false, 'statut' => 'invalide', 'manquants' => $manquants] + $base;
        }

        return ['ok' => true] + $base;
    }

    /**
     * Parcourt récursivement les collections éditables déclarées par le FormType
     * du parent (FormTreeInspector = surface exacte de l'UI) et valide chaque
     * sous-opération SANS rien écrire. Enrichit, par référence :
     *  - $manquants : champ (préfixé du chemin) => messages ;
     *  - $impacts   : descriptions de cascade des suppressions ;
     *  - $bloque    : true si une suppression est bloquée par une contrainte.
     *
     * @param array<string,string[]> $manquants
     * @param string[]               $impacts
     */
    private function analyserCollections(
        object $parent,
        MutationOperation $parentOp,
        AiScope $scope,
        string $parentShortName,
        string $cheminPrefixe,
        int $profondeur,
        array &$manquants,
        array &$impacts,
        bool &$bloque,
    ): void {
        if ($parentOp->collections === [] || $profondeur >= FormTreeInspector::PROFONDEUR_MAX) {
            return;
        }

        foreach ($parentOp->collections as $nomCollection => $enfants) {
            $chemin = $cheminPrefixe . $nomCollection;
            $ce = $this->formTreeInspector->collectionEditable($parentShortName, $nomCollection);
            if ($ce === null) {
                $manquants[$chemin] = ['Collection non éditable depuis ce formulaire.'];
                continue;
            }

            $index = 0;
            foreach ($enfants as $enfant) {
                $enfant = $enfant->withEntityShortName($ce->childShortName);
                $cheminEnfant = sprintf('%s[%d]', $chemin, $index++);

                // Contrôle d'accès par nœud (identique à l'UI : structurel = ouvert,
                // entité métier de la carte = fail-closed).
                if (!$this->accessResolver->can($scope->invite, $ce->childShortName, $enfant->requiredLevel())) {
                    $manquants[$cheminEnfant] = ['Hors de votre périmètre d\'accès.'];
                    continue;
                }

                if ($enfant->isDelete()) {
                    $cible = $this->resoudreEnfantDansCollection($parent, $ce, $enfant->targetId);
                    if ($cible === null) {
                        $manquants[$cheminEnfant] = ['Élément introuvable dans la collection.'];
                        continue;
                    }
                    if (!$ce->allowDelete) {
                        $manquants[$cheminEnfant] = ['La suppression n\'est pas autorisée pour cette collection.'];
                        continue;
                    }
                    $impact = $this->cascadeAnalyzer->analyserSuppression($cible);
                    foreach ($impact->descriptions() as $d) {
                        $impacts[] = $d;
                    }
                    if ($impact->estBloque()) {
                        $bloque = true;
                    }
                    continue;
                }

                // create / edit.
                if ($enfant->isCreate()) {
                    if (!$ce->allowAdd) {
                        $manquants[$cheminEnfant] = ['L\'ajout n\'est pas autorisé pour cette collection.'];
                        continue;
                    }
                    $copieEnfant = $this->nouvelleEntite($enfant, $scope);
                    $this->lierAuParent($copieEnfant, $parent, $ce);
                    $reqManquants = $this->champsRequisManquants($copieEnfant, $enfant, [$ce->mappedBy]);
                    foreach ($reqManquants as $champ => $msgs) {
                        $manquants[$cheminEnfant . '.' . $champ] = $msgs;
                    }
                } else {
                    $cible = $this->resoudreEnfantDansCollection($parent, $ce, $enfant->targetId);
                    if ($cible === null) {
                        $manquants[$cheminEnfant] = ['Élément introuvable dans la collection.'];
                        continue;
                    }
                    $copieEnfant = clone $cible;
                }

                if ($this->estEcritureReelle($enfant)) {
                    $form = $this->construireEtSoumettre($copieEnfant, $enfant, $ce->childFormType);
                    if (!$form->isValid()) {
                        foreach ($this->erreurs($form) as $champ => $msgs) {
                            $manquants[$cheminEnfant . '.' . $champ] = $msgs;
                        }
                    }
                }

                // Récursion : les collections de l'enfant.
                $this->analyserCollections($copieEnfant, $enfant, $scope, $ce->childShortName, $cheminEnfant . '.', $profondeur + 1, $manquants, $impacts, $bloque);
            }
        }
    }

    /**
     * Exécute réellement une opération. À appeler DANS une transaction :
     * lève MutationException (rollback) ou InsufficientTokensException.
     *
     * @return array{op: string, entite: string, libelle: string, cible: ?string, id: ?int}
     */
    public function executer(MutationOperation $op, AiScope $scope, ?Utilisateur $acteur): array
    {
        $labels = $this->accessResolver->libellesEntites();
        $libelle = $labels[$op->entityShortName] ?? $op->entityShortName;

        if (!$this->estAutorise($op, $scope)) {
            throw MutationException::horsPerimetre(sprintf('Action hors de votre périmètre sur « %s ».', $libelle));
        }
        if (!$op->estValide()) {
            throw MutationException::introuvable(sprintf('Opération invalide sur « %s ».', $libelle));
        }

        if ($op->isDelete()) {
            $cible = $this->trouverCible($op, $scope) ?? throw MutationException::introuvable(
                sprintf('%s #%d introuvable dans votre entreprise.', $libelle, (int) $op->targetId),
            );
            $impact = $this->cascadeAnalyzer->analyserSuppression($cible);
            if ($impact->estBloque()) {
                throw MutationException::bloque(implode(' ', $impact->blocages));
            }
            $cibleLabel = $this->libelleInstance($cible);
            $this->commitDelete($cible);

            return ['op' => $op->op, 'entite' => $op->entityShortName, 'libelle' => $libelle, 'cible' => $cibleLabel, 'id' => null];
        }

        // Create / edit.
        if ($op->isCreate()) {
            $entity = $this->nouvelleEntite($op, $scope);
            // Portefeuille (auto/à demander) + champs obligatoires => 422 propre
            // si incomplet (jamais d'erreur SQL, jamais d'enregistrement « perdu »).
            $manquants = $this->resoudrePortefeuille($entity, $op, $scope) + $this->champsRequisManquants($entity, $op);
            if ($manquants !== []) {
                throw MutationException::invalide(sprintf('Informations obligatoires manquantes pour « %s ».', $libelle), $manquants);
            }
            $form = $this->construireEtSoumettre($entity, $op);
            if (!$form->isValid()) {
                throw MutationException::invalide(sprintf('Données invalides pour « %s ».', $libelle), $this->erreurs($form));
            }
            $this->commitWrite($entity, $scope->entreprise, $acteur);
        } else {
            $entity = $this->trouverCible($op, $scope) ?? throw MutationException::introuvable(
                sprintf('%s #%d introuvable dans votre entreprise.', $libelle, (int) $op->targetId),
            );
            // Édition « conteneur » (seules des collections changent) : la tête n'est
            // ni re-validée ni re-facturée si elle ne porte aucun champ propre.
            if ($this->estEcritureReelle($op)) {
                $form = $this->construireEtSoumettre($entity, $op);
                if (!$form->isValid()) {
                    throw MutationException::invalide(sprintf('Données invalides pour « %s ».', $libelle), $this->erreurs($form));
                }
                $this->commitWrite($entity, $scope->entreprise, $acteur);
            }
        }

        // Collections imbriquées (récursif) : chaque nœud écrit est métré et persisté
        // exactement comme via son propre formulaire dans l'UI.
        $enfants = [];
        $this->executerCollections($entity, $op, $scope, $acteur, $op->entityShortName, 0, $enfants);

        return [
            'op'      => $op->op,
            'entite'  => $op->entityShortName,
            'libelle' => $libelle,
            'cible'   => $this->libelleInstance($entity),
            'id'      => method_exists($entity, 'getId') ? $entity->getId() : null,
            'enfants' => $enfants,
        ];
    }

    /**
     * Exécute réellement les sous-opérations de collection d'un nœud parent (déjà
     * persisté), récursivement. Réplique le chemin de sauvegarde de l'UI
     * (handleFormSubmission) par élément : pré-scoping, liaison au parent,
     * soumission du FormType enfant, métrage + persistance. Journalise chaque
     * étape dans $enfants (avec ses propres descendants).
     *
     * @param array<int,array> $enfants (par référence)
     */
    private function executerCollections(
        object $parent,
        MutationOperation $parentOp,
        AiScope $scope,
        ?Utilisateur $acteur,
        string $parentShortName,
        int $profondeur,
        array &$enfants,
    ): void {
        if ($parentOp->collections === [] || $profondeur >= FormTreeInspector::PROFONDEUR_MAX) {
            return;
        }

        foreach ($parentOp->collections as $nomCollection => $ops) {
            $ce = $this->formTreeInspector->collectionEditable($parentShortName, $nomCollection);
            if ($ce === null) {
                throw MutationException::horsPerimetre(sprintf('Collection « %s » non éditable.', $nomCollection));
            }

            foreach (MutationPlan::ordonner($ops) as $enfantOp) {
                $enfantOp = $enfantOp->withEntityShortName($ce->childShortName);
                $libelleEnfant = $this->accessResolver->libellesEntites()[$ce->childShortName] ?? $ce->childShortName;

                if (!$this->accessResolver->can($scope->invite, $ce->childShortName, $enfantOp->requiredLevel())) {
                    throw MutationException::horsPerimetre(sprintf('Action hors de votre périmètre sur « %s ».', $libelleEnfant));
                }

                if ($enfantOp->isDelete()) {
                    $cible = $this->resoudreEnfantDansCollection($parent, $ce, $enfantOp->targetId)
                        ?? throw MutationException::introuvable(sprintf('Élément #%d introuvable dans « %s ».', (int) $enfantOp->targetId, $nomCollection));
                    if (!$ce->allowDelete) {
                        throw MutationException::horsPerimetre(sprintf('Suppression interdite dans « %s ».', $nomCollection));
                    }
                    $impact = $this->cascadeAnalyzer->analyserSuppression($cible);
                    if ($impact->estBloque()) {
                        throw MutationException::bloque(implode(' ', $impact->blocages));
                    }
                    $cibleLabel = $this->libelleInstance($cible);
                    $this->retirerDuParent($parent, $cible, $ce);
                    $this->em->flush();
                    $enfants[] = ['op' => 'delete', 'entite' => $ce->childShortName, 'libelle' => $libelleEnfant, 'cible' => $cibleLabel, 'id' => null, 'enfants' => []];
                    continue;
                }

                if ($enfantOp->isCreate()) {
                    if (!$ce->allowAdd) {
                        throw MutationException::horsPerimetre(sprintf('Ajout interdit dans « %s ».', $nomCollection));
                    }
                    $entiteEnfant = $this->nouvelleEntite($enfantOp, $scope);
                    $this->lierAuParent($entiteEnfant, $parent, $ce);
                    $manquants = $this->champsRequisManquants($entiteEnfant, $enfantOp, [$ce->mappedBy]);
                    if ($manquants !== []) {
                        throw MutationException::invalide(sprintf('Informations obligatoires manquantes pour « %s ».', $libelleEnfant), $manquants);
                    }
                } else {
                    $entiteEnfant = $this->resoudreEnfantDansCollection($parent, $ce, $enfantOp->targetId)
                        ?? throw MutationException::introuvable(sprintf('Élément #%d introuvable dans « %s ».', (int) $enfantOp->targetId, $nomCollection));
                }

                if ($this->estEcritureReelle($enfantOp)) {
                    // Le FormType enfant ne porte pas la relation parente (posée en amont
                    // via lierAuParent) ; clearMissing=false la préserve à la soumission.
                    $form = $this->construireEtSoumettre($entiteEnfant, $enfantOp, $ce->childFormType);
                    if (!$form->isValid()) {
                        throw MutationException::invalide(sprintf('Données invalides pour « %s ».', $libelleEnfant), $this->erreurs($form));
                    }
                    $this->commitWrite($entiteEnfant, $scope->entreprise, $acteur);
                }

                $petitsEnfants = [];
                $this->executerCollections($entiteEnfant, $enfantOp, $scope, $acteur, $ce->childShortName, $profondeur + 1, $petitsEnfants);

                $enfants[] = [
                    'op'      => $enfantOp->op,
                    'entite'  => $ce->childShortName,
                    'libelle' => $libelleEnfant,
                    'cible'   => $this->libelleInstance($entiteEnfant),
                    'id'      => method_exists($entiteEnfant, 'getId') ? $entiteEnfant->getId() : null,
                    'enfants' => $petitsEnfants,
                ];
            }
        }
    }

    // ─────────────────────────────── Interne ──────────────────────────────────

    /** Allowlist métier + accès fail-closed au niveau requis par l'opération. */
    private function estAutorise(MutationOperation $op, AiScope $scope): bool
    {
        if (!MutationAllowlist::autorise($op->entityShortName)) {
            return false; // paramétrage / rôles / hors liste : jamais mutable par Ket.
        }
        if ($this->accessResolver->isRoleManagementEntity($op->entityShortName)) {
            return false; // ceinture + bretelles.
        }

        return $this->accessResolver->can($scope->invite, $op->entityShortName, $op->requiredLevel());
    }

    /**
     * Le nœud représente-t-il une écriture RÉELLE (donc facturée et validée par son
     * FormType) ? Un create l'est toujours ; un edit l'est s'il porte au moins un
     * champ propre (une édition « conteneur » qui ne change que des collections ne
     * re-persiste pas la tête) ; un delete ne l'est jamais (gratuit).
     */
    private function estEcritureReelle(MutationOperation $op): bool
    {
        if ($op->isCreate()) {
            return true;
        }
        if ($op->isDelete()) {
            return false;
        }

        return $this->nettoyerChamps($op->fields) !== [];
    }

    /**
     * FQCN de chaque nœud FACTURÉ (écriture réelle) du sous-arbre d'une opération —
     * source unique du budget, partagée par le tool de préparation et l'endpoint
     * d'exécution (garantit un chiffrage identique). Les enfants sont typés d'après
     * le FormType du parent (jamais d'après le LLM).
     *
     * @return string[]
     */
    public function facturablesArbre(MutationOperation $op, AiScope $scope): array
    {
        $out = [];
        $this->collecterFacturables($op, $op->entityShortName, 0, $out);

        return $out;
    }

    /** @param string[] $out */
    private function collecterFacturables(MutationOperation $op, string $shortName, int $profondeur, array &$out): void
    {
        if ($this->estEcritureReelle($op)) {
            $out[] = 'App\\Entity\\' . $shortName;
        }
        if ($op->collections === [] || $profondeur >= FormTreeInspector::PROFONDEUR_MAX) {
            return;
        }
        foreach ($op->collections as $nomCollection => $enfants) {
            $ce = $this->formTreeInspector->collectionEditable($shortName, $nomCollection);
            if ($ce === null) {
                continue;
            }
            foreach ($enfants as $enfant) {
                $this->collecterFacturables($enfant, $ce->childShortName, $profondeur + 1, $out);
            }
        }
    }

    /** Pose la relation inverse (ManyToOne) de l'enfant vers le parent + l'ajoute à la collection. */
    private function lierAuParent(object $enfant, object $parent, CollectionEditable $ce): void
    {
        if (method_exists($enfant, $ce->setterInverse)) {
            $enfant->{$ce->setterInverse}($parent);
        }
        if (method_exists($parent, $ce->adder)) {
            $parent->{$ce->adder}($enfant);
        }
    }

    /** Retire l'enfant du parent (orphanRemoval le supprime), avec repli sur em->remove(). */
    private function retirerDuParent(object $parent, object $enfant, CollectionEditable $ce): void
    {
        if (method_exists($parent, $ce->remover)) {
            $parent->{$ce->remover}($enfant);

            return;
        }
        $this->em->remove($enfant);
    }

    /**
     * Retrouve un élément d'une collection du parent par son id — GARANTIT à la fois
     * l'appartenance au parent et le périmètre entreprise (l'élément est atteint via
     * la collection déjà scopée du parent, jamais par une requête globale).
     */
    private function resoudreEnfantDansCollection(object $parent, CollectionEditable $ce, ?int $id): ?object
    {
        if ($id === null || !method_exists($parent, $ce->getter)) {
            return null;
        }
        foreach ($parent->{$ce->getter}() as $element) {
            if (method_exists($element, 'getId') && (int) $element->getId() === $id) {
                return $element;
            }
        }

        return null;
    }

    /** Résout l'enregistrement cible d'une op edit/delete dans l'entreprise du scope, ou null. */
    private function trouverCible(MutationOperation $op, AiScope $scope): ?object
    {
        $fqcn = $op->fqcn();
        if (!class_exists($fqcn) || $op->targetId === null) {
            return null;
        }
        $result = $this->searchService->search($fqcn, ['id' => $op->targetId], $scope->entreprise, null, 1, 1);
        if (($result['status']['code'] ?? 500) !== 200) {
            return null;
        }

        return $result['data'][0] ?? null;
    }

    /**
     * Champs OBLIGATOIRES non fournis pour une création : colonnes non-nullables
     * SANS valeur par défaut (BDD ou PHP) et non couvertes par le scoping auto
     * (entreprise/invite) ni l'audit. Détecté sur les métadonnées Doctrine pour
     * que Ket les demande AVANT d'exécuter — évite l'échec SQL au flush (ex. la
     * colonne `exonere` d'un Client). Renvoie [champ => [message]].
     *
     * @return array<string, string[]>
     */
    private function champsRequisManquants(object $entity, MutationOperation $op, array $ignorer = []): array
    {
        try {
            $meta = $this->em->getClassMetadata($op->fqcn());
        } catch (\Throwable) {
            return [];
        }
        $fields = $this->nettoyerChamps($op->fields);
        $manquants = [];

        // Champs scalaires obligatoires (prédicat partagé avec l'inventaire).
        foreach ($meta->getFieldNames() as $field) {
            if (in_array($field, $ignorer, true) || !$this->champsInspector->scalaireRequis($meta, $entity, $field)) {
                continue;
            }
            if (array_key_exists($field, $fields) && $fields[$field] !== null && $fields[$field] !== '') {
                continue; // fourni par l'utilisateur.
            }
            $manquants[$field] = ['Champ obligatoire à renseigner.'];
        }

        // Relations ManyToOne obligatoires (hors entreprise/invite auto-scopés, et
        // hors relation parente déjà posée par le rattachement à la collection).
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (in_array($field, $ignorer, true) || !$this->champsInspector->relationRequise($field, $mapping)) {
                continue;
            }
            if (!empty($fields[$field]) || $this->champsInspector->valeurNonNulle($entity, $meta, $field)) {
                continue;
            }
            $manquants[$field] = ['Relation obligatoire à préciser (identifiant).'];
        }

        return $manquants;
    }

    /**
     * INVENTAIRE des champs pilotables d'une entité, groupés pour une présentation
     * transparente : OBLIGATOIRES (à fournir), FACULTATIFS (au choix) et AUTO
     * (renseignés par Ket d'après le contexte : entreprise, invité, et portefeuille
     * de l'invité s'il n'en gère qu'un). Cohérent par construction avec l'exécution
     * (mêmes prédicats scalaireRequis/relationRequise et portefeuillesGeres).
     *
     * @param object|null $cible Enregistrement à éditer (mode édition) ; null = création.
     *
     * @return array{entite:string,libelle:string,mode:string,obligatoires:array,facultatifs:array,auto:array}
     */
    public function inventaireChamps(string $shortName, AiScope $scope, ?object $cible = null): array
    {
        $libelle = $this->accessResolver->libellesEntites()[$shortName] ?? $shortName;
        $mode = $cible !== null ? 'edition' : 'creation';
        $vide = ['entite' => $shortName, 'libelle' => $libelle, 'mode' => $mode, 'obligatoires' => [], 'facultatifs' => [], 'auto' => []];

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return $vide;
        }
        try {
            $meta = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return $vide;
        }

        $labels = $this->champsInspector->libellesFormulaire($shortName, $fqcn);
        $entity = $cible ?? new $fqcn();
        $obligatoires = [];
        $facultatifs = [];
        $auto = [];

        // AUTO : entreprise + invité (contexte de l'espace).
        if ($meta->hasAssociation('entreprise')) {
            $auto[] = ['champ' => 'entreprise', 'libelle' => $labels['entreprise'] ?? 'Entreprise', 'valeur' => (string) $scope->entreprise->getNom()];
        }
        if ($meta->hasAssociation('invite')) {
            $auto[] = ['champ' => 'invite', 'libelle' => $labels['invite'] ?? 'Créateur', 'valeur' => 'vous'];
        }
        // AUTO/obligatoire : portefeuille selon ce que gère l'invité (création).
        $portefeuilleObligatoire = false;
        if ($mode === 'creation' && $meta->hasAssociation('portefeuille')) {
            $geres = $this->portefeuillesGeres($scope);
            if (count($geres) === 1) {
                $auto[] = ['champ' => 'portefeuille', 'libelle' => $labels['portefeuille'] ?? 'Portefeuille', 'valeur' => $this->libelleInstance($geres[0])];
            } elseif (count($geres) >= 2) {
                $portefeuilleObligatoire = true; // à choisir explicitement
            }
        }
        $autoChamps = array_column($auto, 'champ');

        // Champs scalaires pilotables.
        foreach ($meta->getFieldNames() as $field) {
            if (in_array($field, ChampsObligatoiresInspector::CHAMPS_SYSTEME, true) || in_array($field, $autoChamps, true)) {
                continue;
            }
            if (!in_array((string) $meta->getTypeOfField($field), self::TYPES_SCALAIRES, true)) {
                continue;
            }
            $item = $this->itemChamp($field, $labels, $meta, $cible, $mode);
            if ($mode === 'creation' && $this->champsInspector->scalaireRequis($meta, $entity, $field)) {
                $obligatoires[] = $item;
            } else {
                $facultatifs[] = $item;
            }
        }

        // Relations ManyToOne pilotables (par identifiant).
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$mapping->isManyToOne() || !$mapping->isOwningSide()
                || in_array($field, ChampsObligatoiresInspector::CHAMPS_SYSTEME, true) || in_array($field, $autoChamps, true)) {
                continue;
            }
            $item = $this->itemChamp($field, $labels, $meta, $cible, $mode);
            $requis = $mode === 'creation'
                && ($this->champsInspector->relationRequise($field, $mapping) || ($field === 'portefeuille' && $portefeuilleObligatoire));
            if ($requis) {
                $obligatoires[] = $item;
            } else {
                $facultatifs[] = $item;
            }
        }

        return ['entite' => $shortName, 'libelle' => $libelle, 'mode' => $mode, 'obligatoires' => $obligatoires, 'facultatifs' => $facultatifs, 'auto' => $auto];
    }

    /** Construit une entrée d'inventaire (avec valeur actuelle en édition). */
    private function itemChamp(string $field, array $labels, ClassMetadata $meta, ?object $cible, string $mode): array
    {
        $item = ['champ' => $field, 'libelle' => $labels[$field] ?? $this->champsInspector->humaniser($field)];
        if ($mode === 'edition') {
            $item['valeurActuelle'] = $this->valeurLisible($cible, $meta, $field);
        }

        return $item;
    }

    /** Valeur lisible d'un champ pour l'édition (booléens en clair, relations libellées, dates formatées). */
    private function valeurLisible(?object $entity, ClassMetadata $meta, string $field): string
    {
        if ($entity === null) {
            return '—';
        }
        try {
            $v = $meta->getFieldValue($entity, $field);
        } catch (\Throwable) {
            return '—';
        }
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'Oui' : 'Non';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('d/m/Y');
        }
        if (is_object($v)) {
            return $this->libelleInstance($v);
        }
        $s = (string) $v;

        return mb_strlen($s) > 80 ? mb_substr($s, 0, 77) . '…' : $s;
    }

    /**
     * Portefeuille de destination d'une création (Client notamment). Un
     * enregistrement sans portefeuille n'apparaît pas dans la vue « Mon
     * portefeuille » de l'utilisateur, d'où :
     *  - si l'utilisateur ne l'a pas précisé et gère UN SEUL portefeuille => on
     *    l'y range automatiquement (le portefeuille « de l'utilisateur ») ;
     *  - s'il en gère plusieurs => on renvoie « portefeuille » en manquant pour
     *    que Ket LUI DEMANDE lequel ;
     *  - s'il n'en gère aucun (ex. propriétaire) => laissé libre.
     *
     * Effet de bord assumé : l'auto-affectation est posée sur l'entité (rejouée
     * à l'identique au dry-run et à l'exécution).
     *
     * @return array<string, string[]> manquants éventuels (clé « portefeuille »)
     */
    private function resoudrePortefeuille(object $entity, MutationOperation $op, AiScope $scope): array
    {
        if (!$op->isCreate()
            || !method_exists($entity, 'getPortefeuille')
            || !method_exists($entity, 'setPortefeuille')
            || $entity->getPortefeuille() !== null) {
            return [];
        }
        $fields = $this->nettoyerChamps($op->fields);
        if (!empty($fields['portefeuille'])) {
            return []; // précisé par l'utilisateur : le FormType le liera.
        }

        $geres = $this->portefeuillesGeres($scope);

        if (count($geres) === 1) {
            $entity->setPortefeuille($geres[0]);

            return [];
        }
        if (count($geres) >= 2) {
            return ['portefeuille' => ['Précisez le portefeuille de destination (vous en gérez plusieurs).']];
        }

        return [];
    }

    /**
     * Portefeuilles gérés par l'invité dans l'entreprise du scope (source unique
     * partagée par l'auto-affectation et l'inventaire des champs).
     *
     * @return object[]
     */
    private function portefeuillesGeres(AiScope $scope): array
    {
        $geres = [];
        foreach ($scope->invite->getPortefeuilles() as $pf) {
            $ent = method_exists($pf, 'getEntreprise') ? $pf->getEntreprise() : null;
            if ($ent === null || $ent->getId() === $scope->entreprise->getId()) {
                $geres[] = $pf;
            }
        }

        return $geres;
    }

    /** Nouvelle entité pré-scopée (entreprise/invité renseignés si AuditableTrait). */
    private function nouvelleEntite(MutationOperation $op, AiScope $scope): object
    {
        $fqcn = $op->fqcn();
        $entity = new $fqcn();
        if (method_exists($entity, 'setEntreprise') && method_exists($entity, 'getEntreprise') && $entity->getEntreprise() === null) {
            $entity->setEntreprise($scope->entreprise);
        }
        if (method_exists($entity, 'setInvite') && method_exists($entity, 'getInvite') && $entity->getInvite() === null) {
            $entity->setInvite($scope->invite);
        }

        return $entity;
    }

    /**
     * Construit le FormType de l'entité et lui soumet les champs proposés
     * (clearMissing=false : édition partielle sûre). Pré-hydrate les parents
     * ManyToOne pour que les champs autocomplete valident les id soumis — même
     * logique que ControllerUtilsTrait::handleFormSubmission.
     */
    private function construireEtSoumettre(object $entity, MutationOperation $op, ?string $formTypeOverride = null): FormInterface
    {
        $fqcn = $op->fqcn();
        $fields = $this->nettoyerChamps($op->fields);

        // Pré-hydratation des parents ManyToOne (création surtout) + normalisation
        // des champs booléens. API objet ORM 3.
        try {
            $meta = $this->em->getClassMetadata($fqcn);
            foreach ($meta->getAssociationMappings() as $field => $mapping) {
                if (!$mapping->isManyToOne()) {
                    continue;
                }
                if (!empty($fields[$field])) {
                    $parent = $this->em->getRepository((string) $mapping->targetEntity)->find($fields[$field]);
                    $setter = 'set' . ucfirst($field);
                    if ($parent !== null && method_exists($entity, $setter)) {
                        $entity->{$setter}($parent);
                    }
                }
            }
            // Un champ booléen peut arriver sous mille formes du LLM (true/false,
            // "oui"/"non", "true"/"1"…). Les choix booléens Symfony attendent
            // '1'/'0' : on normalise pour que la soumission bind sans erreur.
            foreach ($fields as $champ => $valeur) {
                if (is_string($champ) && $meta->hasField($champ) && $meta->getTypeOfField($champ) === 'boolean') {
                    $fields[$champ] = $this->versBooleen($valeur) ? '1' : '0';
                }
            }
        } catch (\Throwable) {
            // Best-effort : le formulaire reste seul juge de la validité.
        }

        // FormType : par défaut App\Form\{Entité}Type ; pour un enfant de collection,
        // on passe l'entry_type EXACT déclaré par le formulaire parent (contrat UI).
        $formType = $formTypeOverride ?? ('App\\Form\\' . $op->entityShortName . 'Type');
        $form = $this->formFactory->create($formType, $entity);

        // Champs multiples absents => tableau vide (miroir de handleFormSubmission).
        foreach ($form->all() as $child) {
            if ($child->getConfig()->getOption('multiple') === true && !array_key_exists($child->getName(), $fields)) {
                $fields[$child->getName()] = [];
            }
        }

        $form->submit($fields, false);

        return $form;
    }

    /** Interprète une valeur « booléenne » tolérante venue du LLM. */
    private function versBooleen(mixed $valeur): bool
    {
        if (is_bool($valeur)) {
            return $valeur;
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (float) $valeur != 0.0;
        }
        $v = mb_strtolower(trim((string) $valeur));

        return in_array($v, ['1', 'true', 'vrai', 'oui', 'yes', 'y', 'o', 'x'], true);
    }

    /** Ne conserve que des paires champ(string) => valeur scalaire/null. */
    private function nettoyerChamps(array $fields): array
    {
        $propres = [];
        foreach ($fields as $champ => $valeur) {
            if (is_string($champ) && (is_scalar($valeur) || $valeur === null)) {
                $propres[$champ] = $valeur;
            }
        }

        return $propres;
    }

    /** @return array<string, string[]> Erreurs de formulaire par champ. */
    private function erreurs(FormInterface $form): array
    {
        $erreurs = [];
        foreach ($form->getErrors(true) as $error) {
            $champ = $error->getOrigin()?->getName() ?: '_global';
            $erreurs[$champ][] = $error->getMessage();
        }

        return $erreurs;
    }

    /** Libellé lisible d'une instance (best-effort, comme les puces de contexte). */
    private function libelleInstance(object $entity): string
    {
        foreach (['getNom', 'getLibelle', 'getTitre', 'getReference', 'getCode'] as $getter) {
            if (method_exists($entity, $getter)) {
                $val = $entity->{$getter}();
                if (is_string($val) && trim(strip_tags($val)) !== '') {
                    return trim(strip_tags($val));
                }
            }
        }
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;

        return $id !== null ? ('#' . $id) : '(sans libellé)';
    }
}
