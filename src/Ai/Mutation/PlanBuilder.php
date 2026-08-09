<?php

namespace App\Ai\Mutation;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\TokenAccountService;

/**
 * SOURCE UNIQUE de la construction d'un plan d'écriture (dry-run) : analyse des
 * opérations, impacts, aperçu autoritaire, budget en tokens, et l'action d'interface
 * qui fait naître la barre « Valider et exécuter ».
 *
 * POURQUOI CE SERVICE EXISTE. Ce corps vivait dans PreparerOperationsTool. Tant
 * qu'il n'y avait qu'un outil générique, cela suffisait. Dès qu'un SECOND outil
 * produit un plan à partir d'un parcours métier séquencé côté serveur
 * (SaisirPropositionTool), la duplication devient un piège : le plan stocké en meta,
 * le verrou de plan unique (PlanEnAttente), le chiffrage (estimateWriteCost) et
 * l'exécuteur déterministe attendent EXACTEMENT la même structure. Deux
 * constructions parallèles divergeraient au premier correctif — et la divergence ne
 * se verrait qu'à l'exécution, sur les données réelles de l'utilisateur.
 *
 * Le contrat est donc : tout outil produisant un plan passe par ici, sans exception.
 * Ce que l'outil apporte, c'est la façon de FABRIQUER les opérations (le modèle les
 * dicte, ou un parcours les dérive) ; ce qu'il n'invente jamais, c'est le plan.
 *
 * FAIL-CLOSED inchangé : la première opération hors périmètre fait échouer la
 * préparation entière, et la garde reste celle de WorkspaceMutationService.
 */
final class PlanBuilder
{
    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly TokenAccountService $tokenAccountService,
        private readonly PlanEnAttente $planEnAttente,
    ) {
    }

    /**
     * Le VERROU anti-empilement, partagé par tous les outils de plan.
     *
     * Renvoie le refus structuré si un plan attend une décision et que l'utilisateur
     * n'a pas demandé à le remplacer ; null si la voie est libre (aucun plan en
     * attente, ou remplacement demandé — l'ancien plan est alors annulé, comme s'il
     * avait cliqué « Annuler »).
     *
     * @param string $outil nom de l'outil à rappeler pour un remplacement : la
     *                      consigne doit renvoyer vers CELUI qui a préparé le plan,
     *                      jamais vers un autre (il refuserait à son tour)
     */
    public function refusSiPlanEnAttente(AiScope $scope, bool $remplacer, string $outil): ?AiToolResult
    {
        $message = $this->planEnAttente->messageEnAttente($scope->conversation);
        if ($message === null) {
            return null;
        }

        $resume = $this->planEnAttente->resume(($message->getMeta() ?? [])['mutationPlan'] ?? []);

        if ($remplacer) {
            $this->planEnAttente->annulerLePlanEnAttente($scope->conversation);

            return null;
        }

        return AiToolResult::ok([
            'pret'                => false,
            'planEnAttente'       => true,
            'resumePlanEnAttente' => $resume,
            'note'                => sprintf(
                'REFUSÉ : un plan que tu as déjà présenté (%s) attend la décision de l’utilisateur — sa barre '
                . '« Valider et exécuter / Annuler » est encore affichée dans le fil. Ne prépare PAS un second '
                . 'plan : ce serait lui imposer deux validations. Explique-lui en une phrase qu’un plan est en '
                . 'attente et invite-le soit à le VALIDER, soit à l’ANNULER. S’il te dit qu’il veut CHANGER ce '
                . 'plan (autre montant, autre étendue, autre étape), rappelle %s avec '
                . 'remplacerPlanEnAttente=true : j’annulerai l’ancien et présenterai le nouveau. '
                . 'N’affiche AUCUN tableau de plan tant que celui-ci n’est pas tranché.',
                $resume,
                $outil,
            ),
        ]);
    }

    /**
     * Construit le résultat complet d'un plan : « pret: true » avec son tableau, son
     * budget et son action d'interface, ou « pret: false » avec les manquants /
     * blocages, ou un refus de périmètre.
     *
     * @param string $outilAppelant nom de l'outil à rappeler quand il manque des
     *                              informations — c'est lui qui sait re-fabriquer
     *                              les opérations, le générique ne le saurait pas
     */
    public function construire(MutationPlan $plan, AiScope $scope, string $outilAppelant): AiToolResult
    {
        if ($plan->estVide()) {
            return AiToolResult::introuvable('opérations');
        }

        $lignes = [];
        $manquants = [];
        $blocages = [];
        $facturables = [];
        $avertissements = [];
        $apercu = [];
        $omissions = [];
        // Registre PARTAGÉ par toutes les opérations du plan : c'est lui qui rend
        // validable en une seule fois un plan couvrant plusieurs entités dépendantes
        // (« @etiquette » vers une création précédente).
        $refs = MutationReferences::dryRun();
        $entitesInvalides = [];
        $n = 0;
        foreach ($plan->operations as $op) {
            $n++;
            $analyse = $this->mutationService->analyserOperation($op, $scope, $refs);

            // Fail-closed : toute opération hors périmètre fait échouer la préparation entière.
            if ($analyse['statut'] === 'hors_perimetre') {
                return AiToolResult::horsPerimetre($analyse['libelle']);
            }
            if ($analyse['statut'] === 'introuvable') {
                return AiToolResult::introuvable(sprintf('%s %s', $analyse['libelle'], $op->targetId ? '#' . $op->targetId : ''));
            }

            // Budget : chaque nœud écrit RÉELLEMENT (tête + enfants de collection,
            // TOUTES imbrications), étiqueté par son étape. Source UNIQUE du
            // chiffrage, partagée à l'identique avec le pré-vol de l'exécution.
            foreach ($this->mutationService->facturablesDetailles($op, $scope) as $facturable) {
                $facturables[] = $facturable;
            }

            $lignes[] = [
                'n'            => $n,
                'op'           => $op->op,
                'entite'       => $op->entityShortName,
                'libelle'      => $analyse['libelle'],
                'cible'        => $analyse['cible'],
                'etape'        => $op->etape,
                'ref'          => $op->ref,
                'champs'       => array_keys($op->fields),
                'collections'  => $this->resumerCollections($op),
                'impacts'      => $analyse['impacts'],
                'portefeuille' => $analyse['portefeuille'] ?? null,
            ];

            // Aperçu AUTORITAIRE destiné à l'utilisateur : ce que le plan fera
            // RÉELLEMENT, dérivé des opérations elles-mêmes. Il ne dépend pas de la
            // prose du modèle — c'est ce qui empêche qu'on valide un plan différent
            // de celui qu'on a lu.
            $apercu[] = $this->apercuOperation($op, $analyse);

            $nonCouvertes = $this->collectionsNonCouvertes($op);
            if ($nonCouvertes !== []) {
                $omissions[] = ['libelle' => $analyse['libelle'], 'elements' => array_values($nonCouvertes)];
                $avertissements[] = sprintf(
                    'L’opération « %s » ne couvre pas : %s. Demande à l’utilisateur, DANS LE MÊME MESSAGE que le '
                    . 'plan, s’il dispose de ces informations maintenant — pour tout regrouper ici plutôt que '
                    . 'd’avoir à valider un second plan plus tard.',
                    $analyse['libelle'],
                    implode(', ', $nonCouvertes),
                );
            }

            if ($analyse['statut'] === 'invalide') {
                foreach ($analyse['manquants'] as $champ => $msgs) {
                    $manquants[] = sprintf('#%d %s — %s : %s', $n, $analyse['libelle'], $champ, implode(' ', $msgs));
                }
                // Retenu pour l'inventaire embarqué ci-dessous : ce sont les entités
                // sur lesquelles l'utilisateur va devoir être questionné.
                $entitesInvalides[$op->entityShortName] = $op->isCreate() ? 'creation' : 'edition';
            }
            if ($analyse['statut'] === 'bloque') {
                foreach ($analyse['impacts'] as $impact) {
                    $blocages[] = sprintf('#%d %s : %s', $n, $analyse['libelle'], $impact);
                }
            }
        }

        // Informations incomplètes : Ket doit reposer des questions (aucun plan présenté).
        //
        // L'INVENTAIRE VOYAGE AVEC LE REFUS. Auparavant le modèle n'obtenait que la
        // liste des champs manquants, et devait dépenser un tour entier en
        // inventaire_champs pour savoir ce que ces champs acceptent (codes d'une
        // liste fermée, valeurs par défaut, entité cible d'une relation). Ce tour
        // réexpédiait tout le contexte pour une information que le serveur avait
        // déjà sous la main : on la joint donc au refus.
        if ($manquants !== []) {
            return AiToolResult::ok([
                'pret'       => false,
                'manquants'  => $manquants,
                'inventaire' => $this->inventairePour($entitesInvalides, $scope),
                'note'       => 'Informations incomplètes : demande à l\'utilisateur de fournir les champs manquants, '
                    . 'puis rappelle ' . $outilAppelant . '. N\'appelle PAS inventaire_champs : « inventaire » '
                    . 'ci-dessus porte déjà, pour chaque entité concernée, les champs obligatoires, facultatifs et '
                    . 'automatiques, avec leurs codes autorisés (« valeurs ») et leurs valeurs par défaut. '
                    . 'Un champ qui porte « valeurs » n\'accepte QUE ces codes : propose-les en clair à '
                    . 'l\'utilisateur et écris le CODE, jamais le libellé.',
            ]);
        }
        // Suppression bloquée par une contrainte : on ne présente pas de plan exécutable.
        if ($blocages !== []) {
            return AiToolResult::ok([
                'pret'     => false,
                'blocages' => $blocages,
                'note'     => 'Suppression impossible en l\'état : explique le blocage à l\'utilisateur (liens '
                    . 'obligatoires à détacher d\'abord). Ne présente pas de plan exécutable.',
            ]);
        }

        // Budget en tokens : chaque nœud écrit réellement (tête ET enfants de
        // collection) est facturé ; les suppressions sont gratuites. $facturables
        // a été agrégé sur tout l'arbre pendant l'analyse, étape par étape — le
        // budget couvre donc D'UN SEUL TENANT tout ce que l'utilisateur validera.
        $cout = $this->tokenAccountService->estimateWriteCost(array_column($facturables, 'fqcn'));
        $solde = $this->tokenAccountService->availableFor($scope->entreprise);
        $suffisant = $solde >= $cout;
        $requiresPassword = $plan->contientSuppression();

        $budget = [
            'coutEstime'      => $cout,
            'soldeDisponible' => $solde,
            'resteApres'      => max(0, $solde - $cout),
            'suffisant'       => $suffisant,
            'enregistrements' => count($facturables),
            'parEtape'        => $this->budgetParEtape($facturables, $plan),
        ];
        $etapes = $plan->etapes();

        return AiToolResult::ok(
            [
                'pret'             => true,
                'plan'             => $lignes,
                'etapes'           => $etapes,
                'budget'           => $budget,
                'requiresPassword' => $requiresPassword,
                'avertissements'   => $avertissements,
                // Rappel au modèle : l'utilisateur voit le plan RÉEL sous ta réponse.
                'controleCoherence' => 'L’interface affiche à l’utilisateur, sous ta réponse, la liste EXACTE de '
                    . 'ce que ce plan va écrire, et la liste de ce qu’il ne couvre pas. Ta prose doit y correspondre '
                    . 'EXACTEMENT : n’annonce aucune étape qui ne figure pas dans « plan » ci-dessus, et ne dis '
                    . 'jamais qu’un élément sera enregistré « automatiquement » — seul ce qui est dans le plan '
                    . 'sera écrit. Si une information demandée par l’utilisateur n’a pas pu entrer dans le plan, '
                    . 'DIS-LE explicitement au lieu de la passer sous silence.',
                'note'             => $suffisant
                    ? ('Présente le plan EN UNE FOIS : tableau des opérations (avec la colonne Étape), liste des '
                        . 'impacts, et tableau du budget ventilé par étape (« parEtape ») avec son total. Précise '
                        . 'que l\'utilisateur peut encore DÉCOCHER une étape facultative avant d\'exécuter — le '
                        . 'budget se réajuste — et qu\'une seule validation suffit pour l\'ensemble'
                        . ($requiresPassword ? ' (une suppression exige son mot de passe).' : '.')
                        . ($avertissements !== []
                            ? ' Signale AUSSI, en une phrase et UNE SEULE FOIS, les éléments listés dans '
                                . '« avertissements » : demande à l\'utilisateur s\'il dispose de ces informations '
                                . 'MAINTENANT, pour tout regrouper dans CE plan. S\'il ne les a pas, laisse le plan tel quel.'
                            : ''))
                    : ('Solde de tokens INSUFFISANT pour cette mission : présente le plan et le budget, puis propose '
                        . 'd\'acheter des tokens ou d\'abandonner. N\'exécute pas.'),
            ],
            uiAction: [
                'type'             => PlanEnAttente::ACTION_REVUE,
                'plan'             => $plan->toArray(),
                // `budget.parEtape` porte les étapes décochables (libellé, clé,
                // caractère obligatoire, coût) : source unique du sélecteur d'étendue.
                'budget'           => $budget,
                // Ce que le plan fera VRAIMENT, et ce qu'il ne fera PAS : affiché à
                // l'utilisateur au-dessus des boutons, indépendamment de la prose du
                // modèle (qui peut annoncer une étape que les opérations ne portent pas).
                'apercu'           => $apercu,
                'omissions'        => $omissions,
                'requiresPassword' => $requiresPassword,
                // Impacts agrégés (cascades de suppression) : alimentent la liste
                // d'éléments de la confirmation renforcée côté front.
                'impacts'          => array_values(array_merge(...array_map(
                    static fn (array $l) => $l['impacts'] ?? [],
                    $lignes,
                ) ?: [[]])),
            ],
        );
    }

    /**
     * Inventaires des entités dont une opération est incomplète — la même source
     * que l'outil inventaire_champs (WorkspaceMutationService::inventaireChamps),
     * jamais une seconde implémentation.
     *
     * Toujours en mode CRÉATION ici, y compris pour une édition incomplète : sans
     * la cible en main (l'analyse a échoué avant), un inventaire d'édition
     * afficherait des valeurs actuelles qu'on n'a pas. Mieux vaut la nature et les
     * codes autorisés des champs, qui eux sont exacts dans les deux cas.
     *
     * @param array<string, string> $entites nom court => 'creation' | 'edition'
     *
     * @return array<string, array>
     */
    private function inventairePour(array $entites, AiScope $scope): array
    {
        $inventaires = [];
        foreach (array_keys($entites) as $shortName) {
            if (!MutationAllowlist::autorise($shortName)) {
                continue;
            }
            $inventaires[$shortName] = $this->mutationService->inventaireChamps($shortName, $scope);
        }

        return $inventaires;
    }

    /**
     * Ventilation du budget PAR ÉTAPE : ce que coûte chacune des étapes que
     * l'utilisateur a acceptées. Aucun barème n'est recalculé ici — c'est le même
     * estimateWriteCost, appliqué à des sous-ensembles du MÊME jeu de facturables.
     *
     * @param array<int, array{fqcn: string, entite: string, etape: ?string}> $facturables
     *
     * @return array<int, array{cle: string, libelle: string, obligatoire: bool, enregistrements: int, cout: int}>
     */
    private function budgetParEtape(array $facturables, MutationPlan $plan): array
    {
        $obligatoires = [];
        foreach ($plan->etapes() as $etape) {
            $obligatoires[$etape['cle']] = $etape['obligatoire'];
        }

        $groupes = [];
        foreach ($facturables as $facturable) {
            $libelle = $facturable['etape'] ?? 'Sans étape';
            $cle = MutationPlan::cleEtape($libelle);
            $groupes[$cle] ??= ['cle' => $cle, 'libelle' => $libelle, 'fqcns' => []];
            $groupes[$cle]['fqcns'][] = $facturable['fqcn'];
        }

        $detail = [];
        foreach ($groupes as $cle => $groupe) {
            $detail[] = [
                'cle'             => $cle,
                'libelle'         => $groupe['libelle'],
                'obligatoire'     => $obligatoires[$cle] ?? true,
                'enregistrements' => count($groupe['fqcns']),
                'cout'            => $this->tokenAccountService->estimateWriteCost($groupe['fqcns']),
            ];
        }

        return $detail;
    }

    /**
     * Collections éditables du formulaire d'une CRÉATION qui ne sont pas couvertes
     * par le plan — c'est-à-dire tout ce que l'utilisateur pourrait renseigner
     * MAINTENANT, dans ce même plan, plutôt que dans une seconde validation.
     * Purement informatif : ne bloque jamais, ne modifie pas le plan. Restitué
     * TEL QUEL à l'utilisateur (« ce plan ne couvre pas… »), pour qu'une étape
     * qu'il croyait incluse ne puisse pas passer inaperçue.
     *
     * @return string[] libellés des entités enfant non couvertes
     */
    private function collectionsNonCouvertes(MutationOperation $op): array
    {
        if (!$op->isCreate()) {
            return [];
        }

        $manquantes = [];
        foreach ($this->mutationService->collectionsProposables($op->entityShortName) as $nom => $libelle) {
            if (!isset($op->collections[$nom])) {
                $manquantes[] = $libelle;
            }
        }

        return $manquantes;
    }

    /**
     * Ligne d'aperçu d'une opération pour la BARRE DE DÉCISION : l'action, la
     * cible, et le décompte de ce qui sera écrit dans chaque collection.
     *
     * C'est la seule description du plan que l'utilisateur puisse tenir pour vraie :
     * elle est dérivée des opérations qui seront RÉELLEMENT exécutées, pas du texte
     * rédigé par le modèle. Les deux peuvent diverger — une étape annoncée en prose
     * mais absente des opérations serait sinon validée sans que personne le voie.
     */
    private function apercuOperation(MutationOperation $op, array $analyse): array
    {
        $libellesEnfants = $this->mutationService->collectionsProposables($op->entityShortName);

        $details = [];
        foreach ($op->collections as $nom => $enfants) {
            $compte = ['create' => 0, 'edit' => 0, 'delete' => 0];
            foreach ($enfants as $enfant) {
                $compte[$enfant->op] = ($compte[$enfant->op] ?? 0) + 1;
            }
            $details[] = [
                'libelle'       => $libellesEnfants[$nom] ?? $nom,
                'creations'     => $compte['create'],
                'modifications' => $compte['edit'],
                'suppressions'  => $compte['delete'],
            ];
        }

        return [
            'op'      => $op->op,
            'libelle' => $analyse['libelle'],
            'cible'   => $analyse['cible'] ?? $this->cibleProposee($op),
            'etape'   => $op->etape,
            'details' => $details,
        ];
    }

    /** Libellé pressenti d'une création (aucune cible en base : on prend son nom dicté). */
    private function cibleProposee(MutationOperation $op): ?string
    {
        foreach (['nom', 'libelle', 'titre', 'reference', 'referencePolice'] as $champ) {
            $valeur = $op->fields[$champ] ?? null;
            if (is_string($valeur) && trim($valeur) !== '') {
                return trim($valeur);
            }
        }

        return null;
    }

    /**
     * Résumé lisible des sous-opérations de collection d'une opération (récursif),
     * pour la présentation du plan : nom de collection => liste { op, champs, id,
     * collections }. Le nom court d'entité enfant n'est pas exposé (déduit serveur).
     *
     * @return array<string, array<int, array>>
     */
    private function resumerCollections(MutationOperation $op): array
    {
        $resume = [];
        foreach ($op->collections as $nom => $enfants) {
            foreach ($enfants as $enfant) {
                $item = ['op' => $enfant->op, 'champs' => array_keys($enfant->fields)];
                if ($enfant->targetId !== null) {
                    $item['id'] = $enfant->targetId;
                }
                $sous = $this->resumerCollections($enfant);
                if ($sous !== []) {
                    $item['collections'] = $sous;
                }
                $resume[$nom][] = $item;
            }
        }

        return $resume;
    }
}
