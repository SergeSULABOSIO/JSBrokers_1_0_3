<?php

namespace App\Service\Workspace;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
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

    /** Champs jamais exposés/pilotés (système + scoping auto). */
    private const CHAMPS_SYSTEME = ['id', 'createdAt', 'updatedAt'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormFactoryInterface $formFactory,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
        private readonly JSBDynamicSearchService $searchService,
        private readonly CascadeImpactAnalyzer $cascadeAnalyzer,
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
        if ($op->isCreate()) {
            // Portefeuille (auto si unique, sinon à demander) + champs obligatoires.
            $manquants = $this->resoudrePortefeuille($copie, $op, $scope) + $this->champsRequisManquants($copie, $op);
            if ($manquants !== []) {
                return ['ok' => false, 'statut' => 'invalide', 'manquants' => $manquants] + $base;
            }
            if (method_exists($copie, 'getPortefeuille') && $copie->getPortefeuille() !== null) {
                $base['portefeuille'] = $this->libelleInstance($copie->getPortefeuille());
            }
        }

        $form = $this->construireEtSoumettre($copie, $op);
        if (!$form->isValid()) {
            return ['ok' => false, 'statut' => 'invalide', 'manquants' => $this->erreurs($form)] + $base;
        }

        return ['ok' => true] + $base;
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
        } else {
            $entity = $this->trouverCible($op, $scope) ?? throw MutationException::introuvable(
                sprintf('%s #%d introuvable dans votre entreprise.', $libelle, (int) $op->targetId),
            );
        }

        $form = $this->construireEtSoumettre($entity, $op);
        if (!$form->isValid()) {
            throw MutationException::invalide(
                sprintf('Données invalides pour « %s ».', $libelle),
                $this->erreurs($form),
            );
        }

        $this->commitWrite($entity, $scope->entreprise, $acteur);

        return [
            'op'      => $op->op,
            'entite'  => $op->entityShortName,
            'libelle' => $libelle,
            'cible'   => $this->libelleInstance($entity),
            'id'      => method_exists($entity, 'getId') ? $entity->getId() : null,
        ];
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
    private function champsRequisManquants(object $entity, MutationOperation $op): array
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
            if (!$this->scalaireRequis($meta, $entity, $field)) {
                continue;
            }
            if (array_key_exists($field, $fields) && $fields[$field] !== null && $fields[$field] !== '') {
                continue; // fourni par l'utilisateur.
            }
            $manquants[$field] = ['Champ obligatoire à renseigner.'];
        }

        // Relations ManyToOne obligatoires (hors entreprise/invite auto-scopés).
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$this->relationRequise($field, $mapping)) {
                continue;
            }
            if (!empty($fields[$field]) || $this->valeurNonNulle($entity, $meta, $field)) {
                continue;
            }
            $manquants[$field] = ['Relation obligatoire à préciser (identifiant).'];
        }

        return $manquants;
    }

    /** Un champ scalaire est-il OBLIGATOIRE (non-nullable, sans défaut BDD/PHP, hors système) ? */
    private function scalaireRequis(ClassMetadata $meta, object $entity, string $field): bool
    {
        if (in_array($field, self::CHAMPS_SYSTEME, true) || $meta->isNullable($field)) {
            return false;
        }

        return !$this->aUnDefaut($meta, $field) && !$this->valeurNonNulle($entity, $meta, $field);
    }

    /** Une relation ManyToOne est-elle OBLIGATOIRE (colonne non-null, hors entreprise/invite auto) ? */
    private function relationRequise(string $field, object $mapping): bool
    {
        if (!$mapping->isManyToOne() || !$mapping->isOwningSide() || in_array($field, ['entreprise', 'invite'], true)) {
            return false;
        }
        foreach (($mapping->joinColumns ?? []) as $jc) {
            if (($jc->nullable ?? true) === false) {
                return true;
            }
        }

        return false;
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

        $labels = $this->libellesFormulaire($shortName, $fqcn);
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
            if (in_array($field, self::CHAMPS_SYSTEME, true) || in_array($field, $autoChamps, true)) {
                continue;
            }
            if (!in_array((string) $meta->getTypeOfField($field), self::TYPES_SCALAIRES, true)) {
                continue;
            }
            $item = $this->itemChamp($field, $labels, $meta, $cible, $mode);
            if ($mode === 'creation' && $this->scalaireRequis($meta, $entity, $field)) {
                $obligatoires[] = $item;
            } else {
                $facultatifs[] = $item;
            }
        }

        // Relations ManyToOne pilotables (par identifiant).
        foreach ($meta->getAssociationMappings() as $field => $mapping) {
            if (!$mapping->isManyToOne() || !$mapping->isOwningSide()
                || in_array($field, self::CHAMPS_SYSTEME, true) || in_array($field, $autoChamps, true)) {
                continue;
            }
            $item = $this->itemChamp($field, $labels, $meta, $cible, $mode);
            $requis = $mode === 'creation'
                && ($this->relationRequise($field, $mapping) || ($field === 'portefeuille' && $portefeuilleObligatoire));
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
        $item = ['champ' => $field, 'libelle' => $labels[$field] ?? $this->humaniser($field)];
        if ($mode === 'edition') {
            $item['valeurActuelle'] = $this->valeurLisible($cible, $meta, $field);
        }

        return $item;
    }

    /** Libellés lisibles des champs, lus depuis le FormType (jamais les listes de choix). */
    private function libellesFormulaire(string $shortName, string $fqcn): array
    {
        $labels = [];
        try {
            $form = $this->formFactory->create('App\\Form\\' . $shortName . 'Type', new $fqcn());
            foreach ($form->all() as $child) {
                $lbl = $child->getConfig()->getOption('label');
                if (is_string($lbl) && trim($lbl) !== '') {
                    $labels[$child->getName()] = $lbl;
                }
            }
        } catch (\Throwable) {
            // Pas de FormType exploitable : on retombera sur l'humanisation.
        }

        return $labels;
    }

    /** Humanise un nom de champ technique (fallback quand le FormType n'a pas de libellé). */
    private function humaniser(string $field): string
    {
        $s = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $field);
        $s = str_replace('_', ' ', $s);

        return ucfirst(mb_strtolower(trim($s)));
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

    /** Le champ a-t-il une valeur par défaut côté BDD (options.default) ? */
    private function aUnDefaut(ClassMetadata $meta, string $field): bool
    {
        try {
            $mapping = $meta->getFieldMapping($field);
        } catch (\Throwable) {
            return false;
        }
        $options = is_object($mapping) ? ($mapping->options ?? []) : ($mapping['options'] ?? []);

        return is_array($options) && array_key_exists('default', $options);
    }

    /** La valeur de l'entité pour ce champ est-elle déjà non nulle (défaut PHP) ? */
    private function valeurNonNulle(object $entity, ClassMetadata $meta, string $field): bool
    {
        try {
            return $meta->getFieldValue($entity, $field) !== null;
        } catch (\Throwable) {
            return false; // propriété typée non initialisée => considérée manquante.
        }
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
    private function construireEtSoumettre(object $entity, MutationOperation $op): FormInterface
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

        $formType = 'App\\Form\\' . $op->entityShortName . 'Type';
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
