<?php

namespace App\Ai\Mutation;

use App\Ai\Resolution\Reference;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\TokenAccountService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
    /**
     * Inventaires de champs déjà lus, par entité : un plan porte volontiers
     * plusieurs opérations d'une même entité, et l'inventaire coûte une inspection
     * de formulaire complète.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $champsParEntite = [];

    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly TokenAccountService $tokenAccountService,
        private readonly PlanEnAttente $planEnAttente,
        // Traduit les noms dictés en identifiants : c'est ce qui dispense le modèle
        // d'aller les chercher, et donc d'enchaîner les tours.
        private readonly ResolveurDeReferences $resolveur,
        // Traduit les dates dictées (« 11/08/2026 ») dans le format du formulaire.
        // Même principe que le résolveur : ce que le serveur sait faire seul ne se
        // demande pas au modèle.
        private readonly NormaliseurDeDates $normaliseurDeDates,
        // Ce que le dossier dit déjà : les champs obligatoires DÉDUCTIBLES du plan
        // lui-même, plutôt que redemandés à un utilisateur qui vient de les donner.
        private readonly DefautsContextuels $defautsContextuels = new DefautsContextuels(),
        // Ramène un champ dicté au nom RÉEL du formulaire (« nom » → « nomComplet »)
        // et écarte, en le disant, ce qui ne correspond à rien.
        private readonly AliasDeChamps $aliasDeChamps = new AliasDeChamps(),
        // Un refus de dry-run n'était tracé NULLE PART : le 2026-08-12, il a été
        // impossible de savoir quelle opération le modèle avait mal formée.
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Ramène les champs dictés aux noms RÉELS du formulaire, et écarte ce qui ne
     * correspond à rien. Seules les opérations de TÊTE sont traitées : les champs
     * d'un enfant de collection sont validés par le FormType de la collection, dont
     * l'inventaire n'est pas adressable par nom court à ce stade.
     *
     * @return array{plan: MutationPlan, alias: list<string>, ignores: list<string>}
     */
    private function normaliserLesChamps(MutationPlan $plan, AiScope $scope): array
    {
        $alias = [];
        $ignores = [];
        $operations = [];

        foreach ($plan->operations as $op) {
            $connus = $this->champsConnus($op->entityShortName, $scope);
            $issue = $this->aliasDeChamps->normaliser(
                $op->fields,
                $connus,
                $this->libellesConnus($op->entityShortName, $scope),
            );

            foreach ($issue['alias'] as $ligne) {
                $alias[] = sprintf('%s — %s', $op->entityShortName, $ligne);
            }
            foreach ($issue['ignores'] as $champ) {
                $ignores[] = sprintf('%s — « %s » n’existe pas sur cette entité : rien ne sera écrit dans ce champ.', $op->entityShortName, $champ);
            }

            $operations[] = $issue['champs'] === $op->fields ? $op : new MutationOperation(
                op: $op->op,
                entityShortName: $op->entityShortName,
                targetId: $op->targetId,
                fields: $issue['champs'],
                collections: $op->collections,
                ref: $op->ref,
                etape: $op->etape,
            );
        }

        return ['plan' => new MutationPlan($operations), 'alias' => $alias, 'ignores' => $ignores];
    }

    /**
     * Les noms de champs que le formulaire de cette entité expose réellement.
     * Mémoïsé : un plan porte volontiers plusieurs opérations d'une même entité.
     *
     * @return list<string>
     */
    private function champsConnus(string $entite, AiScope $scope): array
    {
        $inventaire = $this->inventaire($entite, $scope);
        $noms = [];
        foreach (['obligatoires', 'facultatifs', 'auto'] as $groupe) {
            foreach ((array) ($inventaire[$groupe] ?? []) as $item) {
                $champ = is_array($item) ? ($item['champ'] ?? null) : null;
                if (is_string($champ) && $champ !== '') {
                    $noms[] = $champ;
                }
            }
        }

        return array_values(array_unique($noms));
    }

    /**
     * Les LIBELLÉS d'écran de ces mêmes champs. C'est sous cette forme que le modèle
     * a lu la fiche, et donc sous cette forme qu'il redonne parfois un champ
     * (« tauxCommission » pour « Taux de commission ») : sans eux, AliasDeChamps ne
     * peut rattacher que par préfixe.
     *
     * @return array<string, string> champ => libellé
     */
    private function libellesConnus(string $entite, AiScope $scope): array
    {
        $inventaire = $this->inventaire($entite, $scope);
        $libelles = [];
        foreach (['obligatoires', 'facultatifs', 'auto'] as $groupe) {
            foreach ((array) ($inventaire[$groupe] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $champ = $item['champ'] ?? null;
                $libelle = $item['libelle'] ?? null;
                if (is_string($champ) && $champ !== '' && is_string($libelle) && $libelle !== '') {
                    $libelles[$champ] = $libelle;
                }
            }
        }

        return $libelles;
    }

    /**
     * L'inventaire des champs d'une entité, lu UNE SEULE FOIS par plan.
     *
     * Deux consommateurs le demandent — la normalisation des noms de champs, et
     * l'inventaire embarqué avec un refus « manquants ». L'inspection d'un
     * formulaire n'est pas gratuite, et la relire deux fois par entité serait payer
     * deux fois la même chose ; c'est d'ailleurs ce qu'un test de parité vérifie.
     *
     * @return array<string, mixed>
     */
    private function inventaire(string $entite, AiScope $scope): array
    {
        return $this->champsParEntite[$entite]
            ??= $this->mutationService->inventaireChamps($entite, $scope);
    }

    /**
     * LE REFUS D'UN PLAN QUI N'ÉCRIRAIT RIEN.
     *
     * L'INCIDENT DU 2026-08-13. « Modifie le taux de commission à 20 % » : le modèle
     * dicte `tauxCommission`, le formulaire du risque nomme ce champ
     * `pourcentageCommissionSpecifiqueHT`, le champ inconnu est écarté — et
     * l'opération continue son chemin, VIDE. Le plan s'affiche (budget 0, aucune
     * valeur), l'utilisateur valide, l'exécuteur écrit consciencieusement rien du
     * tout et journalise « ok ». Ket annonce alors « Détails mis à jour — taux de
     * commission : 20 % ». Le taux était resté vide en base, et il a fallu que
     * l'utilisateur demande lui-même « c'est bien enregistré ? » pour l'apprendre.
     *
     * Ce refus est le filet de dernier recours, et il est volontairement placé très
     * bas : quelle que soit la raison pour laquelle il ne reste rien à écrire — nom
     * de champ inconnu, paramètre omis par le modèle, dialecte de schéma —, une
     * création ou une modification sans le moindre champ ni la moindre sous-opération
     * ne devient jamais un plan. Une SUPPRESSION, elle, agit par elle-même : elle
     * n'a aucun champ à porter.
     *
     * @param list<string> $ignores les champs dictés qu'on n'a pas su rattacher
     */
    private function refusSiRienAEcrire(
        MutationPlan $plan,
        array $ignores,
        AiScope $scope,
        string $outilAppelant,
    ): ?AiToolResult {
        $sansEffet = [];
        $entites = [];
        $n = 0;
        foreach ($plan->operations as $op) {
            $n++;
            if ($op->ecritQuelqueChose()) {
                continue;
            }
            $sansEffet[] = sprintf(
                '#%d %s de %s — aucun champ à écrire.',
                $n,
                $op->isCreate() ? 'création' : 'modification',
                $op->entityShortName,
            );
            $entites[$op->entityShortName] = $op->isCreate() ? 'creation' : 'edition';
        }

        if ($sansEffet === []) {
            return null;
        }

        $this->logger->warning('Assistant IA : opération sans aucun champ à écrire, plan refusé.', [
            'outil'     => $outilAppelant,
            'sansEffet' => $sansEffet,
            'ignores'   => $ignores,
        ]);

        return AiToolResult::ok([
            'pret'          => false,
            'sansEffet'     => $sansEffet,
            'champsIgnores' => $ignores,
            'inventaire'    => $this->inventairePour($entites, $scope),
            'note'          => 'Ce plan n\'écrirait RIEN : ' . implode(' ', $sansEffet)
                . ($ignores !== []
                    ? ' Les champs que tu as dictés ne portent pas le nom que le formulaire leur donne : '
                        . implode(' ', $ignores)
                    : ' Tu n\'as dicté aucun champ pour cette opération.')
                . ' Ne présente aucun plan, n\'annonce aucun enregistrement et ne dis pas que c\'est fait. '
                . '« inventaire » ci-dessus donne, pour chaque entité concernée, le NOM EXACT de chaque champ '
                . 'et son libellé à l\'écran : reprends le nom exact et rappelle ' . $outilAppelant
                . ' au message SUIVANT. Si la valeur à écrire te manque, demande-la à l\'utilisateur.',
        ]);
    }

    /**
     * CE QU'IL FAUT FAIRE quand une opération est « introuvable », en toutes lettres.
     *
     * Deux causes, deux remèdes — et dans les deux cas l'interdiction d'inventer un
     * incident technique, qui est ce que le modèle a fait faute de consigne.
     */
    private function quoiFaireSiIntrouvable(MutationOperation $op, array $analyse, int $position): string
    {
        $commun = ' N\'affiche AUCUN plan, n\'annonce AUCUN « ajustement technique » et ne propose PAS de '
            . 'créer les enregistrements « par étape » : dis en une phrase ce qui bloque, puis reprends '
            . 'l\'appel corrigé — ou pose la question qui manque.';

        if ($op->isCreate()) {
            return sprintf(
                'L\'opération #%d (création de « %s ») est mal formée : une CRÉATION ne porte jamais '
                . 'd\'identifiant de cible. Retire « id »/« cibleId » de cette opération et rappelle l\'outil. '
                . 'Pour rattacher une création à une AUTRE création du même plan, n\'utilise pas d\'identifiant : '
                . 'pose « ref » sur celle qui est créée d\'abord et donne au champ de relation la valeur '
                . '"@etiquette".%s',
                $position,
                $analyse['libelle'],
                $commun,
            );
        }

        return sprintf(
            'L\'opération #%d (%s de « %s ») vise l\'enregistrement #%d, qui n\'existe pas dans cette '
            . 'entreprise. Vérifie l\'identifiant (rechercher_entites) — et s\'il s\'agit en réalité d\'un '
            . 'enregistrement à CRÉER, remplace cette opération par une création.%s',
            $position,
            $op->op === MutationOperation::OP_DELETE ? 'suppression' : 'modification',
            $analyse['libelle'],
            (int) $op->targetId,
            $commun,
        );
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

        // LES RELATIONS SE DONNENT PAR LEUR NOM. Le modèle écrit « SUNU », « Mme
        // Marlette » ; le serveur en fait des identifiants. C'est ce qui lui évite
        // d'aller les chercher lui-même, tour après tour — le motif qui a consommé
        // 188 000 tokens en un seul message le 2026-08-10.
        [$plan, $questions] = $this->resoudreLesNoms($plan, $scope);
        if ($questions !== []) {
            return $this->demander($questions, $outilAppelant);
        }

        // CE QUE LE DOSSIER DIT DÉJÀ. Avant de réclamer un champ obligatoire, on
        // regarde si le plan ne le porte pas ailleurs : le nom d'une piste se déduit
        // de son client et de son risque, la durée d'une proposition de la période de
        // son avenant. Le 2026-08-12, Ket a demandé cinq précisions de ce genre sur
        // un dossier complet — dont elle venait elle-même de proposer la plupart.
        // Les valeurs déduites entrent dans les CHAMPS de l'opération : elles
        // figurent donc dans le tableau du plan que l'utilisateur relit, et la liste
        // « defauts » les lui fait annoncer. Jamais de valeur posée en silence.
        // UN CHAMP DICTÉ QUI N'EXISTE PAS. « nom » sur un Risque — qui, seul de tout
        // le périmètre, appelle ce champ « nomComplet » — était jeté sans un mot, puis
        // réclamé à l'utilisateur qui l'avait déjà donné, tout en FIGURANT dans le
        // tableau du plan. On rattrape ce qui est sans ambiguïté, et on retire (en le
        // disant) ce qu'on ne sait pas rattacher : le plan ne doit afficher que ce
        // qu'il écrira.
        ['plan' => $plan, 'alias' => $alias, 'ignores' => $ignores] = $this->normaliserLesChamps($plan, $scope);

        ['plan' => $plan, 'defauts' => $defauts] = $this->defautsContextuels->appliquer($plan);
        $defauts = array_merge($alias, $defauts);

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

        // DEUX ORDRES, ET C'EST INDISPENSABLE.
        //
        // L'ANALYSE suit l'ordre d'EXÉCUTION (créations d'abord) : c'est lui qui
        // décide quand chaque « @etiquette » devient disponible. L'analyser dans
        // l'ordre DÉCLARÉ faisait diverger le dry-run de l'exécution — un plan qui
        // édite un enregistrement en renvoyant à une création déclarée PLUS BAS
        // était refusé (« référence inconnue ») alors que l'exécution, elle, aurait
        // créé d'abord et réussi. Le courtier voyait un refus incompréhensible sur
        // un plan parfaitement valide.
        //
        // La PRÉSENTATION, elle, reste dans l'ordre DÉCLARÉ : c'est l'ordre métier
        // que l'utilisateur a dicté et qu'il relira, numéros « #N » compris.
        $analyses = [];
        foreach ($plan->indicesOrdonnes() as $i) {
            $op = $plan->operations[$i];
            $analyse = $this->mutationService->analyserOperation($op, $scope, $refs);

            // Fail-closed : toute opération hors périmètre fait échouer la préparation entière.
            if ($analyse['statut'] === 'hors_perimetre') {
                return AiToolResult::horsPerimetre($analyse['libelle']);
            }
            if ($analyse['statut'] === 'introuvable') {
                // Le refus JOURNALISÉ et EXPLIQUÉ. Le 2026-08-12, « Avenants #1 » est
                // remonté seul jusqu'au modèle, qui n'avait rien pour se corriger :
                // il a annoncé un « ajustement technique » imaginaire et l'utilisateur
                // est resté sans dossier. On journalise la forme de l'opération (jamais
                // les valeurs, qui sont les données du courtier) et on rend au modèle
                // la marche à suivre.
                $this->logger->warning('Assistant IA : opération introuvable au dry-run.', [
                    'outil'      => $outilAppelant,
                    'position'   => $i + 1,
                    'op'         => $op->op,
                    'entite'     => $op->entityShortName,
                    'cible'      => $op->targetId,
                    'champsCles' => array_keys($op->fields),
                    'ref'        => $op->ref,
                ]);

                return AiToolResult::introuvable(
                    sprintf('%s %s', $analyse['libelle'], $op->targetId ? '#' . $op->targetId : ''),
                    $this->quoiFaireSiIntrouvable($op, $analyse, $i + 1),
                );
            }

            $analyses[$i] = $analyse;
        }

        $n = 0;
        foreach ($plan->operations as $i => $op) {
            $n++;
            $analyse = $analyses[$i];

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
                'note'       => 'Informations incomplètes : demande à l\'utilisateur de fournir les champs manquants '
                    . '— NOMME-les un par un, jamais « il manque un élément » —, puis ARRÊTE-TOI : tu rappelleras '
                    . $outilAppelant . ' au message SUIVANT, avec ses réponses, pas dans ce tour-ci. '
                    . 'N\'appelle PAS inventaire_champs : « inventaire » '
                    . 'ci-dessus porte déjà, pour chaque entité concernée, les champs obligatoires, facultatifs et '
                    . 'automatiques, avec leurs codes autorisés (« valeurs ») et leurs valeurs par défaut. '
                    . 'Un champ qui porte « valeurs » n\'accepte QUE ces codes : propose-les en clair à '
                    . 'l\'utilisateur et écris le CODE, jamais le libellé.',
            ]);
        }
        // UNE ÉCRITURE QUI N'ÉCRIT RIEN N'EST PAS UN PLAN. Le contrôle vient ici, et
        // pas plus haut, pour deux raisons : les défauts contextuels ont pu compléter
        // l'opération (elle a alors bel et bien quelque chose à écrire), et une
        // CRÉATION à qui il manque des champs obligatoires mérite le refus
        // « manquants », qui NOMME ce qu'il faut demander — plus utile que celui-ci.
        $refusSansEffet = $this->refusSiRienAEcrire($plan, $ignores, $scope, $outilAppelant);
        if ($refusSansEffet !== null) {
            return $refusSansEffet;
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
                // Ce que le SERVEUR a déduit à la place de l'utilisateur : à ANNONCER,
                // jamais à taire — c'est une écriture qu'il n'a pas dictée lui-même.
                'defauts'          => $defauts,
                // Ce que le modèle a dicté et que le formulaire ne connaît pas : le
                // plan ne l'écrira pas, donc il ne doit pas figurer dans la prose.
                'champsIgnores'    => $ignores,
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
                    . 'DIS-LE explicitement au lieu de la passer sous silence.'
                    . ($ignores !== []
                        ? ' EN PARTICULIER : ' . implode(' ', $ignores) . ' Dis-le à l’utilisateur en une '
                            . 'phrase — n’annonce jamais une valeur que ce plan n’écrira pas.'
                        : ''),
                'note'             => $suffisant
                    ? ('Présente le plan EN UNE FOIS : tableau des opérations (avec la colonne Étape), liste des '
                        . 'impacts, et tableau du budget ventilé par étape (« parEtape ») avec son total. '
                        . ($defauts !== []
                            ? 'ANNONCE aussi, en une ligne, les valeurs de « defauts » : ce sont des champs que le '
                                . 'serveur a DÉDUITS du dossier à la place de l\'utilisateur — il doit pouvoir les '
                                . 'corriger d\'une phrase avant de valider. Ne les présente jamais comme des '
                                . 'informations qu\'il aurait fournies. '
                            : '')
                        . 'Précise '
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
     * Remplace, dans TOUTES les opérations du plan (collections imbriquées
     * comprises), les relations données par un NOM par leur identifiant.
     *
     * Ce qui n'est jamais touché : les valeurs numériques (déjà des identifiants),
     * les étiquettes « @nom » qui renvoient à une création du même plan, et les
     * marqueurs « @fichier:<id> » d'une pièce jointe. Y toucher casserait le
     * chaînage interne du plan.
     *
     * @return array{0: MutationPlan, 1: array<int, array<string, mixed>>} le plan
     *         résolu, et les questions restées sans réponse
     */
    private function resoudreLesNoms(MutationPlan $plan, AiScope $scope): array
    {
        $questions = [];
        $operations = [];
        foreach ($plan->toArray() as $operation) {
            $operations[] = $this->resoudreOperation($operation, $scope, $questions);
        }

        return [MutationPlan::fromArray($operations), $questions];
    }

    /**
     * @param array<string, mixed>                  $operation
     * @param array<int, array<string, mixed>>      $questions
     *
     * @return array<string, mixed>
     */
    private function resoudreOperation(array $operation, AiScope $scope, array &$questions): array
    {
        $entite = (string) ($operation['entite'] ?? '');

        // DEUX FORMES COEXISTENT, et les confondre coûte les champs du plan :
        // « champs » est la clé exposée au modèle, « fields » celle de la
        // sérialisation interne (MutationOperation::toArray). On écrit dans CELLE
        // QUI EXISTE — poser un « champs » vide à côté d'un « fields » rempli le
        // ferait gagner à la relecture, et le plan repartirait sans aucune valeur.
        $cle = array_key_exists('champs', $operation) ? 'champs'
            : (array_key_exists('fields', $operation) ? 'fields' : null);

        if ($entite !== '' && $cle !== null && is_array($operation[$cle])) {
            $operation[$cle] = $this->resoudreChamps($entite, $operation[$cle], $scope, $questions);
        }

        // Les collections suivent la même dualité : une LISTE de
        // {collection, elements} côté modèle, une MAP nom => opérations côté
        // sérialisation. Chaque enfant porte son propre « entite », qui fait
        // autorité — inutile de le redemander au formulaire.
        foreach ($operation['collections'] ?? [] as $cleCollection => $collection) {
            if (isset($collection['elements']) && is_array($collection['elements'])) {
                foreach ($collection['elements'] as $j => $element) {
                    $operation['collections'][$cleCollection]['elements'][$j]
                        = $this->resoudreOperation($this->avecEntite($element, $entite, (string) ($collection['collection'] ?? '')), $scope, $questions);
                }
                continue;
            }
            if (is_array($collection)) {
                foreach ($collection as $j => $element) {
                    if (is_array($element)) {
                        $operation['collections'][$cleCollection][$j]
                            = $this->resoudreOperation($this->avecEntite($element, $entite, (string) $cleCollection), $scope, $questions);
                    }
                }
            }
        }

        return $operation;
    }

    /**
     * Complète un élément de collection avec le nom court de son entité, quand il
     * ne le porte pas lui-même (forme dictée par le modèle). La source est le
     * FORMULAIRE du parent — parité avec l'écran, jamais une convention de nommage.
     *
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    private function avecEntite(array $element, string $entiteParent, string $collection): array
    {
        if (($element['entite'] ?? '') !== '' || $collection === '') {
            return $element;
        }
        $enfant = $this->mutationService->entiteDeCollection($entiteParent, $collection);
        if ($enfant !== null) {
            $element['entite'] = $enfant;
        }

        return $element;
    }

    /**
     * @param array<string, mixed>             $champs
     * @param array<int, array<string, mixed>> $questions
     *
     * @return array<string, mixed>
     */
    private function resoudreChamps(string $entite, array $champs, AiScope $scope, array &$questions): array
    {
        // LES DATES DICTÉES d'abord : « 11/08/2026 » devient « 2026-08-11 » (ou
        // « 2026-08-11T00:00 » si le champ est un horodatage). Sans cette passe, le
        // formulaire répond « Veuillez entrer une date valide », le plan n'est jamais
        // prêt, aucun bouton n'apparaît — et l'utilisateur redonne indéfiniment une
        // date qu'il avait parfaitement écrite (incident du 2026-08-11).
        $champs = $this->normaliseurDeDates->normaliserChamps(
            $champs,
            $this->mutationService->champsTemporelsDe($entite),
        );

        $relations = $this->mutationService->relationsDe($entite);

        foreach ($champs as $champ => $valeur) {
            $cible = $relations[$champ] ?? null;
            if ($cible === null) {
                continue;
            }

            if (is_array($valeur)) { // relation MULTIPLE : une liste d'identifiants
                $resolus = [];
                foreach ($valeur as $element) {
                    $resolu = $this->resoudreValeur($cible, $element, $scope, $questions);
                    if ($resolu !== null) {
                        $resolus[] = $resolu;
                    }
                }
                $champs[$champ] = $resolus;
                continue;
            }

            $resolu = $this->resoudreValeur($cible, $valeur, $scope, $questions);
            if ($resolu !== null) {
                $champs[$champ] = $resolu;
            }
        }

        return $champs;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     *
     * @return int|string|null l'identifiant résolu, la valeur d'origine si elle ne
     *                         doit pas être touchée, ou null si elle est à écarter
     */
    private function resoudreValeur(string $cible, mixed $valeur, AiScope $scope, array &$questions): int|string|null
    {
        if (!is_string($valeur) && !is_int($valeur)) {
            return null;
        }
        $texte = trim((string) $valeur);
        if ($texte === '') {
            return null;
        }
        // Chaînage interne du plan et pièces jointes : intouchables.
        if (str_starts_with($texte, '@')) {
            return $texte;
        }
        // Déjà un identifiant : on le rend TEL QUEL, sans même le convertir. Cette
        // passe ne traduit que des NOMS ; normaliser au passage le type d'une valeur
        // qui fonctionne déjà changerait le format des plans stockés sans rien
        // apporter — un plan en attente relu après coup doit rester lisible.
        if (ctype_digit($texte)) {
            return $valeur;
        }

        $reference = $this->resolveur->resoudre($cible, $texte, $scope);
        if ($reference->estResolue()) {
            return $reference->id;
        }

        $questions[] = $reference->question();

        return null;
    }

    /**
     * Refus structuré quand un nom n'a pas pu être résolu : une question groupée,
     * avec de quoi y répondre. Jamais un identifiant deviné.
     *
     * @param array<int, array<string, mixed>> $questions
     */
    private function demander(array $questions, string $outilAppelant): AiToolResult
    {
        return AiToolResult::ok([
            'pret'      => false,
            'aDemander' => array_values($questions),
            // LA TROISIÈME ISSUE, celle qui manquait. Quand un nom introuvable désigne
            // une entité que Ket sait CRÉER, choisir entre les enregistrements
            // existants n'est pas la seule sortie — et c'était la seule proposée. Le
            // 2026-08-11, « Loyken Motors » étant inconnu, Ket a offert le seul autre
            // fournisseur du cabinet ; l'utilisateur a dû dicter lui-même la marche à
            // suivre (« créons d'abord ce fournisseur »), et quatre messages plus tard
            // la dépense n'était toujours pas enregistrée. La création manquante fait
            // partie du travail : elle se dit dans le MÊME message que la question.
            'creationsPossibles' => $this->creationsPossibles($questions),
            'note'      => 'Je n’ai pas pu identifier avec certitude un ou plusieurs enregistrements que tu as '
                . 'nommés, et je ne devine jamais une donnée du courtier. Pose à l’utilisateur, EN UN SEUL '
                . 'MESSAGE et en liste courte, les questions correspondant à « aDemander » : quand des '
                . '« valeurs » sont fournies, PROPOSE-LES en clair plutôt qu’une question ouverte. '
                . 'ET quand l’enregistrement figure dans « creationsPossibles », propose-lui AUSSI, dans la '
                . 'même phrase, de le CRÉER — c’est souvent ce qu’il veut : un nom introuvable est le plus '
                . 'souvent un nouveau fournisseur, un nouveau client, une nouvelle charge. S’il accepte, '
                . 'rappelle ' . $outilAppelant . ' avec DEUX opérations dans le MÊME plan : la création '
                . 'étiquetée (ref:"x"), puis l’opération d’origine dont le champ vaut "@x" — une seule '
                . 'validation pour l’ensemble. S’il tient à valider les deux temps SÉPARÉMENT, c’est '
                . 'preparer_programme (deux étapes, « ref »/« @ref »). '
                . 'N’appelle PAS rechercher_entites : les candidats sont déjà ci-dessus.',
        ]);
    }

    /**
     * Parmi les références non résolues, celles qui désignent une entité que Ket a le
     * droit de CRÉER — donc celles dont l'absence n'est pas une impasse.
     *
     * L'appartenance vient de l'allowlist de mutation, jamais d'une liste recopiée :
     * une entité qu'on ne peut pas écrire ne doit surtout pas être proposée à la
     * création, ce serait promettre une capacité inexistante.
     *
     * @param array<int, array<string, mixed>> $questions
     *
     * @return array<int, array{entite: string, libelle: string, terme: string}>
     */
    private function creationsPossibles(array $questions): array
    {
        $possibles = [];
        foreach ($questions as $question) {
            $entite = trim((string) ($question['champ'] ?? ''));
            $terme = trim((string) ($question['terme'] ?? ''));
            if ($entite === '' || $terme === '' || !MutationAllowlist::autorise($entite)) {
                continue;
            }
            // Seul un nom INTROUVABLE appelle une création : une ambiguïté, elle, se
            // tranche par un choix — en créer un de plus ne ferait qu'aggraver le
            // doublon.
            if (($question['probleme'] ?? '') !== Reference::INTROUVABLE) {
                continue;
            }
            $possibles[] = [
                'entite'  => $entite,
                'libelle' => (string) ($question['libelle'] ?? $entite),
                'terme'   => $terme,
            ];
        }

        return $possibles;
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
            $inventaires[$shortName] = $this->inventaire($shortName, $scope);
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
