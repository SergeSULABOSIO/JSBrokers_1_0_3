<?php

namespace App\Service\Workspace;

use App\Entity\Piste;
use App\Service\Partage\EffortCommercialAgent;
use App\Ai\Fichier\ConversationFichierResolver;
use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Token\TokenAccountService;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Form\FormError;
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

    /**
     * Libellés des SOUS-ENTITÉS structurelles, absentes de la carte d'accès (elles
     * n'y ont pas leur place : on ne les interroge jamais en tête, leur droit suit
     * celui du parent). Sans eux, l'aperçu affiché à l'utilisateur AVANT validation
     * exposait le nom technique de la collection — « conditionsPartageExceptionnelles »
     * là où il faut lire « Conditions de partage ».
     */
    private const LIBELLES_SOUS_ENTITES = [
        'ChargementPourPrime' => 'Composition de la prime',
        'ConditionPartage'    => 'Conditions de partage',
        'PaiementPrime'       => 'Paiements de prime signalés',
        'ReversementRetroAgent' => 'Reversements de rétrocommission agent',
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
        private readonly ConversationFichierResolver $fichierResolver,
        private readonly ReferentielEnumerateur $referentiels,
        // La règle du rattachement d'agent, consultée ICI comme par l'écran : c'est ce
        // qui empêche l'assistant de contourner par le chemin générique ce que son outil
        // nommé refuse (même intention que LiensProteges).
        private readonly EffortCommercialAgent $effortCommercialAgent,
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
    public function analyserOperation(MutationOperation $op, AiScope $scope, ?MutationReferences $refs = null): array
    {
        $refs ??= MutationReferences::dryRun();
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

        // RÈGLE MÉTIER DU RATTACHEMENT D'AGENT — le pendant exact de LiensProteges.
        //
        // Une affaire n'a qu'UN agent bénéficiaire, et un versement scelle ce choix. La
        // règle est appliquée par l'écran et par l'outil nommé (effort_commercial_agent) ;
        // mais rien n'empêcherait le modèle d'écrire directement
        // `{entite: "Piste", champs: {conditionsPartageAgent: [7]}}` par preparer_operations.
        // Sans ce garde-fou, la parité avec l'assistant serait un trou de sécurité déguisé
        // en fonctionnalité : la règle vaudrait pour qui l'emprunte, et pas pour qui la
        // contourne. On la pose donc là où TOUS les chemins d'écriture passent.
        $refusPartage = $this->refusDuRattachementAgent($op, $cible);
        if ($refusPartage !== null) {
            $base['impacts'] = [$refusPartage];
            $base['bloque'] = true;

            return ['ok' => false, 'statut' => 'bloque'] + $base;
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
        // Renvois vers d'autres opérations du plan (« @etiquette ») : résolus une
        // fois, ici, avant toute validation. Au dry-run l'id n'existe pas encore :
        // le champ est tenu pour FOURNI (donc pas réclamé à l'utilisateur) mais
        // n'est pas soumis au formulaire. Une étiquette inconnue est un manquant.
        $resolution = $this->resoudreReferences($op, $refs);
        $manquants = $resolution['manquants'];

        if ($manquants === [] && $op->isCreate()) {
            // Portefeuille (auto si unique, sinon à demander) + champs obligatoires.
            $manquants = $this->resoudrePortefeuille($copie, $op, $scope)
                + $this->champsRequisManquants($copie, $op, [], $resolution);
            if ($manquants === [] && method_exists($copie, 'getPortefeuille') && $copie->getPortefeuille() !== null) {
                $base['portefeuille'] = $this->libelleInstance($copie->getPortefeuille());
            }
        }

        // La tête n'est validée par son FormType que si elle porte des champs à
        // écrire (une édition « conteneur », dont seules des collections changent,
        // n'a pas de champ propre à valider et ne re-persiste pas la tête).
        if ($manquants === [] && $this->estEcritureReelle($op)) {
            $form = $this->construireEtSoumettre($copie, $op, $scope, null, $resolution);
            if (!$form->isValid()) {
                $manquants = $this->erreurs($form);
            }
        }

        // La création est « promise » aux opérations suivantes du plan.
        if ($op->isCreate()) {
            $refs->declarer($op->ref);
        }

        // Collections imbriquées (récursif) : parité formulaire avec l'UI. Le
        // chiffrage (facturables) n'est PAS recalculé ici : source unique =
        // facturablesArbre() (utilisée à l'identique par le budget et l'exécution).
        $impacts = [];
        $bloque = false;
        $this->analyserCollections($copie, $op, $scope, $op->entityShortName, '', 0, $manquants, $impacts, $bloque, $refs);

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
        ?MutationReferences $refs = null,
    ): void {
        $refs ??= MutationReferences::dryRun();
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
                $resolutionEnfant = $this->resoudreReferences($enfant, $refs);
                foreach ($resolutionEnfant['manquants'] as $champ => $msgs) {
                    $manquants[$cheminEnfant . '.' . $champ] = $msgs;
                }
                if ($resolutionEnfant['manquants'] !== []) {
                    continue;
                }

                if ($enfant->isCreate()) {
                    if (!$ce->allowAdd) {
                        $manquants[$cheminEnfant] = ['L\'ajout n\'est pas autorisé pour cette collection.'];
                        continue;
                    }
                    // DRY-RUN : on NE lie PAS l'enfant au parent — un clone Doctrine
                    // partage la collection de l'entité managée (clone superficiel),
                    // et l'y ajouter polluerait l'UnitOfWork (flush ultérieur en échec).
                    // La relation parente est ignorée pour la validation (mappedBy).
                    $copieEnfant = $this->nouvelleEntite($enfant, $scope);
                    $reqManquants = $this->champsRequisManquants($copieEnfant, $enfant, [$ce->mappedBy], $resolutionEnfant);
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
                    $form = $this->construireEtSoumettre($copieEnfant, $enfant, $scope, $ce->childFormType, $resolutionEnfant);
                    if (!$form->isValid()) {
                        foreach ($this->erreurs($form) as $champ => $msgs) {
                            $manquants[$cheminEnfant . '.' . $champ] = $msgs;
                        }
                    }
                }
                if ($enfant->isCreate()) {
                    $refs->declarer($enfant->ref);
                }

                // Récursion : les collections de l'enfant.
                $this->analyserCollections($copieEnfant, $enfant, $scope, $ce->childShortName, $cheminEnfant . '.', $profondeur + 1, $manquants, $impacts, $bloque, $refs);
            }
        }
    }

    /**
     * Exécute réellement une opération. À appeler DANS une transaction :
     * lève MutationException (rollback) ou InsufficientTokensException.
     *
     * @return array{op: string, entite: string, libelle: string, cible: ?string, id: ?int}
     */
    public function executer(MutationOperation $op, AiScope $scope, ?Utilisateur $acteur, ?MutationReferences $refs = null): array
    {
        $refs ??= MutationReferences::live();
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
            // GARDE-FOU : couper les liens qu'une cascade ne doit jamais remonter
            // (cf. LiensProteges). Supprimer une opportunité dérivée emporterait
            // sinon la POLICE qu'elle fait évoluer — l'inverse exact de l'intention.
            LiensProteges::dissocier($cible);
            $this->commitDelete($cible);

            return ['op' => $op->op, 'entite' => $op->entityShortName, 'libelle' => $libelle, 'cible' => $cibleLabel, 'id' => null];
        }

        // Renvois vers les créations DÉJÀ exécutées de ce plan : résolus en id réels
        // (source unique avec le dry-run) avant toute validation.
        $resolution = $this->resoudreReferences($op, $refs);
        if ($resolution['manquants'] !== []) {
            throw MutationException::invalide(sprintf('Renvoi non résolu pour « %s ».', $libelle), $resolution['manquants']);
        }

        // Create / edit.
        if ($op->isCreate()) {
            $entity = $this->nouvelleEntite($op, $scope);
            // Portefeuille (auto/à demander) + champs obligatoires => 422 propre
            // si incomplet (jamais d'erreur SQL, jamais d'enregistrement « perdu »).
            $manquants = $this->resoudrePortefeuille($entity, $op, $scope)
                + $this->champsRequisManquants($entity, $op, [], $resolution);
            if ($manquants !== []) {
                throw MutationException::invalide(sprintf('Informations obligatoires manquantes pour « %s ».', $libelle), $manquants);
            }
            $form = $this->construireEtSoumettre($entity, $op, $scope, null, $resolution, true);
            if (!$form->isValid()) {
                throw MutationException::invalide(sprintf('Données invalides pour « %s ».', $libelle), $this->erreurs($form));
            }
            $this->commitWrite($entity, $scope->entreprise, $acteur);
            // L'id existe : les opérations suivantes du plan peuvent y renvoyer.
            $refs->declarer($op->ref, method_exists($entity, 'getId') ? $entity->getId() : null);
        } else {
            $entity = $this->trouverCible($op, $scope) ?? throw MutationException::introuvable(
                sprintf('%s #%d introuvable dans votre entreprise.', $libelle, (int) $op->targetId),
            );
            // Édition « conteneur » (seules des collections changent) : la tête n'est
            // ni re-validée ni re-facturée si elle ne porte aucun champ propre.
            if ($this->estEcritureReelle($op)) {
                $form = $this->construireEtSoumettre($entity, $op, $scope, null, $resolution, true);
                if (!$form->isValid()) {
                    throw MutationException::invalide(sprintf('Données invalides pour « %s ».', $libelle), $this->erreurs($form));
                }
                $this->commitWrite($entity, $scope->entreprise, $acteur);
            }
        }

        // Collections imbriquées (récursif) : chaque nœud écrit est métré et persisté
        // exactement comme via son propre formulaire dans l'UI.
        $enfants = [];
        $this->executerCollections($entity, $op, $scope, $acteur, $op->entityShortName, 0, $enfants, $refs);

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
        ?MutationReferences $refs = null,
    ): void {
        $refs ??= MutationReferences::live();
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

                $resolutionEnfant = $this->resoudreReferences($enfantOp, $refs);
                if ($resolutionEnfant['manquants'] !== []) {
                    throw MutationException::invalide(sprintf('Renvoi non résolu pour « %s ».', $libelleEnfant), $resolutionEnfant['manquants']);
                }

                if ($enfantOp->isCreate()) {
                    if (!$ce->allowAdd) {
                        throw MutationException::horsPerimetre(sprintf('Ajout interdit dans « %s ».', $nomCollection));
                    }
                    $entiteEnfant = $this->nouvelleEntite($enfantOp, $scope);
                    $this->lierAuParent($entiteEnfant, $parent, $ce);
                    $manquants = $this->champsRequisManquants($entiteEnfant, $enfantOp, [$ce->mappedBy], $resolutionEnfant);
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
                    $form = $this->construireEtSoumettre($entiteEnfant, $enfantOp, $scope, $ce->childFormType, $resolutionEnfant, true);
                    if (!$form->isValid()) {
                        throw MutationException::invalide(sprintf('Données invalides pour « %s ».', $libelleEnfant), $this->erreurs($form));
                    }
                    $this->commitWrite($entiteEnfant, $scope->entreprise, $acteur);
                }
                if ($enfantOp->isCreate()) {
                    $refs->declarer($enfantOp->ref, method_exists($entiteEnfant, 'getId') ? $entiteEnfant->getId() : null);
                }

                $petitsEnfants = [];
                $this->executerCollections($entiteEnfant, $enfantOp, $scope, $acteur, $ce->childShortName, $profondeur + 1, $petitsEnfants, $refs);

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
    /**
     * Le motif s'opposant à une écriture sur `Piste.conditionsPartageAgent`, ou null.
     *
     * Deux règles, une seule autorité (EffortCommercialAgent) :
     *  — POSER une condition sur une affaire qui en a déjà une est refusé : une affaire n'a
     *    qu'un agent bénéficiaire, et le décompte n'aurait plus qu'à choisir « la première
     *    applicable », ce que personne ne voit à l'écran ;
     *  — RETIRER celle en place après un versement est refusé : on ne réécrit pas l'histoire
     *    d'un décaissement comptabilisé.
     *
     * On ne regarde que les opérations qui TOUCHENT ce champ : tout le reste passe, y compris
     * les éditions d'une piste qui portent d'autres champs.
     */
    private function refusDuRattachementAgent(MutationOperation $op, ?object $cible): ?string
    {
        if ($op->entityShortName !== 'Piste' || !array_key_exists('conditionsPartageAgent', $op->fields)) {
            return null;
        }

        $demande = array_values(array_filter((array) $op->fields['conditionsPartageAgent']));
        $piste = $cible instanceof Piste ? $cible : null;

        // Création : l'affaire naît vierge, il n'y a rien à écraser ni à défaire.
        if ($piste === null) {
            return null;
        }

        $enPlace = $this->effortCommercialAgent->condition($piste);

        // On VIDE le champ : c'est un détachement.
        if ($demande === []) {
            return $enPlace === null ? null : $this->effortCommercialAgent->refusDeDetachement($piste);
        }

        // On POSE : autorisé si l'affaire est libre, ou si l'on repose exactement la même
        // condition (une réécriture à l'identique ne change rien et ne doit pas bloquer un
        // plan qui touche la piste pour d'autres raisons).
        if ($enPlace === null || in_array((int) $enPlace->getId(), array_map('intval', $demande), true)) {
            return null;
        }

        return $this->effortCommercialAgent->refusDeRattachement($piste);
    }
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
        return array_column($this->facturablesDetailles($op, $scope), 'fqcn');
    }

    /**
     * Même collecte, mais chaque nœud facturé porte son ENTITÉ et son ÉTAPE de
     * parcours — de quoi ventiler le budget par étape (« ce que coûte ce que vous
     * avez accepté ») sans jamais recalculer le chiffrage ailleurs. Un nœud sans
     * étape hérite de celle de son parent.
     *
     * @return array<int, array{fqcn: string, entite: string, etape: ?string}>
     */
    public function facturablesDetailles(MutationOperation $op, AiScope $scope): array
    {
        $out = [];
        $this->collecterFacturables($op, $op->entityShortName, 0, $op->etape, $out);

        return $out;
    }

    /** @param array<int, array{fqcn: string, entite: string, etape: ?string}> $out */
    private function collecterFacturables(MutationOperation $op, string $shortName, int $profondeur, ?string $etapeHeritee, array &$out): void
    {
        $etape = $op->etape ?? $etapeHeritee;
        if ($this->estEcritureReelle($op)) {
            $out[] = ['fqcn' => 'App\\Entity\\' . $shortName, 'entite' => $shortName, 'etape' => $etape];
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
                $this->collecterFacturables($enfant, $ce->childShortName, $profondeur + 1, $etape, $out);
            }
        }
    }

    /**
     * Collections que le formulaire d'une entité permet d'ALIMENTER dès sa
     * création (allow_add) : ce que Ket peut proposer de renseigner dans le MÊME
     * plan plutôt que dans une seconde validation. Libellés lisibles de l'entité
     * enfant (source unique : la carte d'accès).
     *
     * @return array<string, string> nom de collection => libellé de l'entité enfant
     */
    public function collectionsProposables(string $shortName): array
    {
        $labels = $this->accessResolver->libellesEntites();
        $proposables = [];
        foreach ($this->formTreeInspector->collectionsEditables($shortName) as $nom => $ce) {
            if ($ce->allowAdd) {
                $proposables[$nom] = $labels[$ce->childShortName]
                    ?? self::LIBELLES_SOUS_ENTITES[$ce->childShortName]
                    ?? $nom;
            }
        }

        return $proposables;
    }

    /**
     * Les champs de RELATION d'une entité, et le nom court de leur cible.
     *
     * Sert à traduire une relation dictée par son NOM (« SUNU », « Mme Marlette »)
     * en identifiant, côté serveur — ce qui évite au modèle d'aller le chercher
     * lui-même, tour après tour. Toutes les relations sont couvertes, to-one comme
     * to-many : une relation multiple se donne par une LISTE de noms.
     *
     * @return array<string, string> nom du champ => nom court de l'entité cible
     */
    public function relationsDe(string $shortName): array
    {
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return [];
        }

        try {
            $meta = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return [];
        }

        $relations = [];
        foreach ($meta->getAssociationMappings() as $champ => $mapping) {
            $cible = (string) $mapping->targetEntity;
            if (str_starts_with($cible, 'App\\Entity\\')) {
                $relations[$champ] = substr($cible, strlen('App\\Entity\\'));
            }
        }

        return $relations;
    }

    /**
     * Les champs TEMPORELS d'une entité et leur type Doctrine (`date`, `datetime`,
     * suffixe `_immutable` compris).
     *
     * Sert à normaliser une date DICTÉE (« 11/08/2026 ») dans le format qu'exige le
     * formulaire, avant même de la lui soumettre — cf. NormaliseurDeDates. Le type
     * est lu dans les métadonnées de l'ORM : c'est la seule source qui distingue à
     * coup sûr une date nue d'un horodatage, distinction dont dépend le format
     * attendu (`Y-m-d` contre `Y-m-d\TH:i`).
     *
     * @return array<string, string> nom du champ => type Doctrine
     */
    public function champsTemporelsDe(string $shortName): array
    {
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return [];
        }

        try {
            $meta = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return [];
        }

        // Liste FERMÉE : « dateinterval » commence aussi par « date » et n'est pas une
        // date. Un type non listé repart tel quel vers le formulaire, qui tranchera.
        $connus = ['date', 'date_immutable', 'datetime', 'datetime_immutable'];

        $temporels = [];
        foreach ($meta->getFieldNames() as $champ) {
            $type = (string) $meta->getTypeOfField($champ);
            if (in_array($type, $connus, true)) {
                $temporels[$champ] = $type;
            }
        }

        return $temporels;
    }

    /**
     * Nom court de l'entité portée par une collection éditable, ou null.
     * Le nom vient du FORMULAIRE (parité avec l'écran), jamais d'une convention.
     */
    public function entiteDeCollection(string $shortName, string $collection): ?string
    {
        $ce = $this->formTreeInspector->collectionEditable($shortName, $collection);

        return $ce?->childShortName;
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
    private function champsRequisManquants(object $entity, MutationOperation $op, array $ignorer = [], ?array $resolution = null): array
    {
        try {
            $meta = $this->em->getClassMetadata($op->fqcn());
        } catch (\Throwable) {
            return [];
        }
        $resolution ??= $this->resoudreReferences($op, MutationReferences::dryRun());
        $fields = $resolution['champs'];
        // Un champ satisfait par un renvoi (« @etiquette ») vers une création du
        // même plan est FOURNI — même si son id n'existe pas encore.
        $ignorer = array_merge($ignorer, $resolution['parReference']);
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

        $descripteurs = $this->champsInspector->descripteursChamps($shortName, $fqcn);
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
            $item = $this->itemChamp($field, $descripteurs, $meta, $cible, $mode, $entity);
            if ($mode === 'creation' && $this->champsInspector->scalaireRequis($meta, $entity, $field)) {
                $obligatoires[] = $item;
            } else {
                $facultatifs[] = $item;
            }
        }

        // Relations pilotables PAR IDENTIFIANT. Le périmètre est exactement celui que
        // construireEtSoumettre() sait écrire — TO-ONE côté propriétaire (un OneToOne
        // propriétaire porte sa colonne de jointure comme un ManyToOne, ex.
        // Piste::avenantDeBase) et ManyToMany (liste d'ids). Le restreindre au ManyToOne
        // rendait ces champs écrivables mais JAMAIS annoncés : ils restaient vides par
        // construction, faute d'apparaître dans l'inventaire.
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            $toOneProprietaire = $mapping->isToOneOwningSide();
            $manyToMany = $mapping->isManyToMany() && $mapping->isOwningSide();
            if ((!$toOneProprietaire && !$manyToMany)
                || in_array($field, ChampsObligatoiresInspector::CHAMPS_SYSTEME, true) || in_array($field, $autoChamps, true)) {
                continue;
            }
            $item = $this->itemChamp($field, $descripteurs, $meta, $cible, $mode, $entity, $scope, $mapping);
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

    /**
     * Construit une entrée d'inventaire : ce que l'appelant doit savoir pour REMPLIR le
     * champ, et non seulement son nom.
     *
     * C'était le point de rupture du moteur d'écriture. L'entrée ne portait que
     * `champ` + `libelle` — or beaucoup de champs ne persistent pas du texte libre mais
     * un CODE issu d'une constante (`typeAvenant: 0` = « Souscription »). Annoncé
     * « Type d'Avenant », sans liste ni sens, un tel champ ne pouvait qu'être laissé
     * vide (le protocole interdit d'inventer une valeur) ou rempli avec le libellé
     * affiché, aussitôt rejeté par le formulaire — et le message d'erreur
     * (« Cette valeur n'est pas valide ») n'enseignait pas davantage les valeurs
     * acceptables : la réparation bouclait.
     *
     * On y ajoute donc :
     *  - `nature`      : ce qu'est le champ, pour savoir quoi fournir ;
     *  - `valeurs`     : les codes acceptés AVEC leur sens (choix fermé, ou référentiel
     *                    assez court pour être lu — cf. ReferentielEnumerateur) ;
     *  - `defaut`      : ce qui sera écrit si l'on ne dit rien (propriété d'entité,
     *                    défaut de colonne, ou option `data` du formulaire) ;
     *  - `entiteCible` : sur une relation, l'entité où chercher l'identifiant ;
     *  - `multiple`    : une LISTE d'identifiants est attendue, pas un seul.
     *
     * @param array<string, array> $descripteurs descripteurs de champs du FormType
     * @param object|null          $mapping      mapping d'association (relations uniquement)
     */
    private function itemChamp(
        string $field,
        array $descripteurs,
        ClassMetadata $meta,
        ?object $cible,
        string $mode,
        object $entity,
        ?AiScope $scope = null,
        ?object $mapping = null,
    ): array {
        $d = $descripteurs[$field] ?? null;
        $item = [
            'champ'   => $field,
            'libelle' => ($d['libelle'] ?? '') !== '' ? $d['libelle'] : $this->champsInspector->humaniser($field),
            'nature'  => $this->natureChamp($field, $meta, $d, $mapping),
        ];

        if ($mapping !== null) {
            $item['entiteCible'] = $this->shortNameDe((string) ($mapping->targetEntity ?? ''));
            if ($mapping->isManyToMany()) {
                $item['multiple'] = true;
            }
        } elseif (!empty($d['multiple'])) {
            $item['multiple'] = true;
        }

        // VALEURS acceptées : liste fermée du formulaire, sinon référentiel court.
        $valeurs = $this->valeursAcceptees($field, $d, $mapping, $scope);
        if ($valeurs !== null) {
            $item['valeurs'] = $valeurs;
        } elseif ($mapping !== null && isset($item['entiteCible'])) {
            // Trop d'entrées pour les lister : un renvoi explicite vaut mieux qu'une
            // liste tronquée, qui se lirait comme l'ensemble.
            $item['aide'] = sprintf(
                'Fournis l’identifiant. Le référentiel « %s » compte trop d’entrées pour être listé ici : '
                . 'trouve-le avec rechercher_entites (entite: %s).',
                $item['entiteCible'],
                $item['entiteCible'],
            );
        }

        // DÉFAUT : ce qui sera écrit à défaut d'instruction. La consigne « applique et
        // annonce le défaut » était inapplicable tant qu'aucun défaut n'était transmis.
        if ($mode === 'creation') {
            $defaut = $this->defautChamp($field, $meta, $entity, $d, $mapping);
            if ($defaut !== null) {
                $item['defaut'] = $defaut;
            }
        }

        if ($mode === 'edition') {
            $item['valeurActuelle'] = $this->valeurLisible($cible, $meta, $field);
        }

        // Aide métier rédigée dans le formulaire (« Laissez « En cours » tant que la
        // police court… ») : déjà en français, déjà juste, jamais transmise jusqu'ici.
        if (!empty($d['aide']) && !isset($item['aide'])) {
            $item['aide'] = $d['aide'];
        }

        // Piège d'unité : l'écran (et donc l'écriture) attend un POURCENTAGE, la
        // base stocke une FRACTION. Sans cette mention, recopier la valeur lue
        // divise le taux par 100 en silence.
        if (!empty($d['pourcentage'])) {
            $item['unite'] = 'pourcentage';
            $item['aide'] = 'À FOURNIR en pourcentage (ex. 15 pour 15 %). Attention : la valeur '
                . 'LUE dans une fiche est une fraction (0.15) — ne la recopie jamais telle quelle.';
        }

        return $item;
    }

    /** Nature d'un champ, telle qu'elle dicte CE QU'IL FAUT FOURNIR. */
    private function natureChamp(string $field, ClassMetadata $meta, ?array $d, ?object $mapping): string
    {
        if ($mapping !== null) {
            return 'relation';
        }
        if (!empty($d['choix'])) {
            return 'choix';
        }

        return match ((string) $meta->getTypeOfField($field)) {
            'boolean' => 'booleen',
            'date', 'date_immutable', 'datetime', 'datetime_immutable' => 'date',
            'integer', 'smallint', 'bigint', 'float', 'decimal' => 'nombre',
            default => 'texte',
        };
    }

    /**
     * Valeurs acceptées par le champ, en `[{code, libelle}]`.
     *
     * `null` signifie « pas d'énumération possible » : soit le champ est libre, soit son
     * référentiel dépasse le plafond. La distinction avec un tableau VIDE compte : vide =
     * ce référentiel n'a aucune entrée dans l'entreprise (il faut la créer), null = il y
     * en a trop pour les montrer. Les confondre ferait dire « aucun assureur » là où il
     * y en a trois cents.
     *
     * @return array<int, array{code: int|string, libelle: string}>|null
     */
    private function valeursAcceptees(string $field, ?array $d, ?object $mapping, ?AiScope $scope): ?array
    {
        // Liste fermée déclarée par le formulaire (le contrat exact de l'écran).
        if (!empty($d['choix'])) {
            return $this->enPaires($d['choix']);
        }

        // Relation : énumérer le référentiel s'il est assez court pour être lu.
        if ($mapping === null || $scope === null) {
            return null;
        }
        $cible = $this->shortNameDe((string) ($mapping->targetEntity ?? ''));
        if ($cible === '') {
            return null;
        }
        $codes = $this->referentiels->codes($cible, $scope);

        return $codes === null ? null : $this->enPaires($codes);
    }

    /**
     * @param array<int|string, string> $codes
     *
     * @return array<int, array{code: int|string, libelle: string}>
     */
    private function enPaires(array $codes): array
    {
        $paires = [];
        foreach ($codes as $code => $libelle) {
            $paires[] = ['code' => $code, 'libelle' => $libelle];
        }

        return $paires;
    }

    /**
     * Ce qui sera EFFECTIVEMENT écrit si le champ n'est pas fourni, avec sa provenance.
     *
     * Trois sources, dans l'ordre où elles s'imposent réellement :
     *  1. l'option `data` du FormType — elle PRIME sur la valeur de l'entité (Symfony),
     *     donc un défaut posé sur la propriété y serait écrasé en silence ;
     *  2. la valeur portée par la propriété de l'entité neuve (`= self::X`) ;
     *  3. le défaut de colonne, appliqué par la base.
     *
     * @return array{code: int|string|bool, libelle: string, source: string}|null
     */
    private function defautChamp(string $field, ClassMetadata $meta, object $entity, ?array $d, ?object $mapping): ?array
    {
        if ($mapping !== null) {
            return null; // une relation n'a pas de défaut annonçable ici (cf. portefeuille, traité en AUTO).
        }

        $choix = $d['choix'] ?? [];

        if (!empty($d['aFormulaireData'])) {
            $brut = $d['defautFormulaire'];
            if (is_scalar($brut)) {
                return $this->paireDefaut($brut, $choix, 'formulaire');
            }
        }

        try {
            $brut = $meta->getFieldValue($entity, $field);
        } catch (\Throwable) {
            $brut = null;
        }
        if (is_scalar($brut)) {
            return $this->paireDefaut($brut, $choix, 'entite');
        }

        $brut = $this->champsInspector->defautColonne($meta, $field);

        return is_scalar($brut) ? $this->paireDefaut($brut, $choix, 'colonne') : null;
    }

    /**
     * @param array<int|string, string> $choix
     *
     * @return array{code: int|string|bool, libelle: string, source: string}
     */
    private function paireDefaut(bool|float|int|string $brut, array $choix, string $source): array
    {
        $cle = is_bool($brut) ? ($brut ? '1' : '0') : (is_int($brut) ? $brut : (string) $brut);
        $libelle = $choix[$cle] ?? (is_bool($brut) ? ($brut ? 'Oui' : 'Non') : (string) $brut);

        return ['code' => $brut, 'libelle' => $libelle, 'source' => $source];
    }

    /** Nom court d'une classe (dernier segment du FQCN). */
    private function shortNameDe(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
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
    private function construireEtSoumettre(object $entity, MutationOperation $op, AiScope $scope, ?string $formTypeOverride = null, ?array $resolution = null, bool $pourExecution = false): FormInterface
    {
        $fqcn = $op->fqcn();
        $resolution ??= $this->resoudreReferences($op, MutationReferences::dryRun());
        $fields = $resolution['champs'];

        // Pièces jointes de la conversation référencées par un champ (« @fichier:<id> ») :
        // résolues en UploadedFile RÉEL, fail-closed au périmètre de la conversation.
        // Retirées de $fields d'abord (pour ne pas polluer la pré-hydratation des
        // relations), puis ré-injectées après la construction du formulaire, sous la
        // forme attendue par le champ (VichFileType est COMPOUND : ['file' => upload]).
        // UNE PIÈCE QUI NE PEUT PAS ÊTRE JOINTE SE DIT. Ce bloc retirait le marqueur
        // en silence quand il ne résolvait rien : le 2026-08-14, Ket a référencé une
        // pièce #19 inexistante (la conversation s'arrêtait à #18), le Document s'est
        // créé SANS fichier, et le plan s'est annoncé « exécuté, conforme au plan
        // validé ». L'utilisateur a cherché son contrat dans un document vide.
        // Le refus devient donc une erreur de FORMULAIRE : le plan n'est plus « prêt »
        // au dry-run, et l'exécution échoue au lieu d'écrire une coquille.
        $fichiersResolus = [];
        $piecesRefusees = [];
        foreach ($fields as $champ => $valeur) {
            if (!ConversationFichierRef::estMarqueur($valeur)) {
                continue;
            }
            unset($fields[$champ]);
            $piece = $this->fichierResolver->resoudre((string) $valeur, $scope, $pourExecution);
            if ($piece->estResolue()) {
                $fichiersResolus[$champ] = $piece->upload;
                continue;
            }
            $piecesRefusees[$champ] = (string) $piece->motif;
        }

        // Pré-hydratation des relations TO-ONE côté PROPRIÉTAIRE (création surtout)
        // + normalisation des champs booléens. API objet ORM 3.
        //
        // ManyToOne ET OneToOne propriétaire : la promesse du moteur est « une
        // relation se donne par son id », et elle vaut pour les deux. Un OneToOne
        // propriétaire porte sa colonne de jointure exactement comme un ManyToOne
        // (ex. Piste::avenantDeBase, le lien qui rattache une piste dérivée à la
        // police qu'elle fait évoluer) ; le restreindre au ManyToOne rendait ces
        // champs inatteignables, sans qu'aucune règle ne le justifie. Le côté
        // INVERSE reste exclu : lui affecter une valeur ne persisterait rien.
        try {
            $meta = $this->em->getClassMetadata($fqcn);
            foreach ($meta->getAssociationMappings() as $field => $mapping) {
                if (!$mapping->isToOneOwningSide()) {
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

        // CHOIX FERMÉS : un libellé accepté à la place de son code. Ce n'est pas une
        // tolérance de complaisance — c'est la symétrie qui manquait. La lecture d'une
        // fiche restitue « Souscription » (indicateur calculé) là où l'écriture exige 0 :
        // sans cette résolution, un champ à code était lisible et non réécrivable, et
        // toute reprise de valeur lue partait en erreur.
        //
        // Les choix sont lus sur le formulaire RÉELLEMENT construit — un enfant de
        // collection utilise l'entry_type déclaré par son parent, pas forcément
        // App\Form\{Nom}Type. Un libellé inconnu ou ambigu n'est pas transformé : le
        // ChoiceType reste seul juge, et aucune valeur n'est devinée.
        foreach ($form->all() as $child) {
            $nom = $child->getName();
            if (array_key_exists($nom, $fields)) {
                $fields[$nom] = $this->versCodeChoix($child, $fields[$nom]);
            }
        }

        // Pièces jointes résolues : mises à la forme attendue par le champ. Un
        // VichFileType est COMPOUND (enfant « file »), un FileType nu prend l'upload
        // directement. On respecte le contrat du formulaire réel (parité UI).
        foreach ($fichiersResolus as $champ => $upload) {
            // Le champ n'existe pas sur ce formulaire : joindre un fichier à une
            // entité qui n'en accepte pas était l'autre perte silencieuse. On le DIT,
            // plutôt que de laisser croire que la pièce a été rattachée.
            if (!$form->has($champ)) {
                $piecesRefusees[$champ] = sprintf(
                    'Le champ « %s » n’existe pas sur ce formulaire : cet enregistrement n’accepte pas de fichier. '
                    . 'Passe par un Document rattaché (champ « fichier »).',
                    $champ,
                );
                continue;
            }
            $fields[$champ] = $form->get($champ)->has('file') ? ['file' => $upload] : $upload;
        }

        $form->submit($fields, false);

        // APRÈS la soumission : une erreur ajoutée avant serait effacée par submit().
        // Portée par le champ quand il existe, sinon par le formulaire — dans les deux
        // cas erreurs() la nomme, et le plan la remonte à l'utilisateur.
        foreach ($piecesRefusees as $champ => $motif) {
            ($form->has($champ) ? $form->get($champ) : $form)->addError(new FormError($motif));
        }

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

    /**
     * Résout le LIBELLÉ d'un choix fermé vers son CODE. Jumeau de {@see versBooleen()},
     * pour la même raison : une valeur juste sur le fond ne doit pas être rejetée sur sa
     * forme.
     *
     * Prudent par construction — on ne devine rien :
     *  - un code déjà valide passe intact (aucune conversion parasite) ;
     *  - un champ hors liste fermée passe intact ;
     *  - un libellé AMBIGU (deux codes portant le même texte) n'est pas transformé ;
     *  - un libellé inconnu n'est pas transformé : le ChoiceType refusera, comme avant.
     *
     * La comparaison ignore la casse, les accents et la ponctuation d'affichage, parce
     * que « Prime Nette », « prime nette » et « prime  nette » désignent la même chose.
     */
    private function versCodeChoix(FormInterface $child, mixed $valeur): mixed
    {
        $choix = $this->champsInspector->choixDuFormulaire($child);
        if ($choix === []) {
            return $valeur; // pas une liste fermée : rien à résoudre.
        }

        // Une liste (choix multiple) se résout élément par élément.
        if (is_array($valeur)) {
            return array_map(fn ($v) => $this->versCodeChoix($child, $v), $valeur);
        }
        if (!is_scalar($valeur)) {
            return $valeur;
        }

        // Déjà un code valide (comparaison souple : '0' et 0 sont le même choix).
        foreach (array_keys($choix) as $code) {
            if ((string) $code === (string) $valeur) {
                return $valeur;
            }
        }

        $cherche = $this->champsInspector->libelleComparable((string) $valeur);
        if ($cherche === '') {
            return $valeur;
        }
        $trouves = [];
        foreach ($choix as $code => $libelle) {
            if ($this->champsInspector->libelleComparable($libelle) === $cherche) {
                $trouves[] = $code;
            }
        }

        // Exactement une correspondance, sinon on laisse le formulaire trancher.
        return count($trouves) === 1 ? $trouves[0] : $valeur;
    }

    /**
     * Ne conserve que des paires champ(string) => valeur scalaire/null, ou LISTE
     * de scalaires (relation multiple : `multiple` du formulaire / ManyToMany,
     * ex. les partenaires d'une piste — l'UI y soumet un tableau d'identifiants).
     */
    private function nettoyerChamps(array $fields): array
    {
        $propres = [];
        foreach ($fields as $champ => $valeur) {
            if (!is_string($champ)) {
                continue;
            }
            if (is_scalar($valeur) || $valeur === null) {
                $propres[$champ] = $valeur;
            } elseif (is_array($valeur) && array_is_list($valeur)) {
                $propres[$champ] = array_values(array_filter($valeur, static fn ($v) => is_scalar($v)));
            }
        }

        return $propres;
    }

    /**
     * Résout les RENVOIS d'un nœud vers d'autres opérations du même plan : une
     * valeur « @etiquette » désigne l'enregistrement créé par l'opération qui porte
     * cette étiquette. C'est ce qui rend validable EN UNE FOIS un plan couvrant
     * plusieurs entités dépendantes (créer le client PUIS sa piste).
     *
     * Renvoie :
     *  - `champs`       : champs prêts à soumettre (renvois remplacés par l'id réel ;
     *                     retirés tant que l'id n'existe pas — dry-run) ;
     *  - `parReference` : champs satisfaits par un renvoi (à ne pas réclamer) ;
     *  - `manquants`    : renvois vers une étiquette INCONNUE (fail-closed : jamais
     *                     de rattachement silencieux au mauvais enregistrement).
     *
     * @return array{champs: array<string, mixed>, parReference: string[], manquants: array<string, string[]>}
     */
    private function resoudreReferences(MutationOperation $op, MutationReferences $refs): array
    {
        $champs = [];
        $parReference = [];
        $manquants = [];

        foreach ($this->nettoyerChamps($op->fields) as $champ => $valeur) {
            $liste = is_array($valeur);
            $valeurs = $liste ? $valeur : [$valeur];
            $resolues = [];
            $porteReference = false;

            foreach ($valeurs as $item) {
                if (!MutationReferences::estReference($item)) {
                    $resolues[] = $item;
                    continue;
                }
                $porteReference = true;
                $cible = $refs->resoudre((string) $item);
                if ($cible === null) {
                    $manquants[$champ] = [sprintf(
                        'Renvoi « %s » inconnu : aucune opération précédente de ce plan ne porte cette étiquette.',
                        (string) $item,
                    )];
                    continue 2;
                }
                if ($cible !== MutationReferences::PROMISE) {
                    $resolues[] = $cible; // id réel (exécution).
                }
            }

            if ($porteReference) {
                $parReference[] = $champ;
            }
            if ($resolues === [] && $porteReference) {
                continue; // renvoi encore « promis » : rien à soumettre au formulaire.
            }
            $champs[$champ] = $liste ? $resolues : ($resolues[0] ?? null);
        }

        return ['champs' => $champs, 'parReference' => $parReference, 'manquants' => $manquants];
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
