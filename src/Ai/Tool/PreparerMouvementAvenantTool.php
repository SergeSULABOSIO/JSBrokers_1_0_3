<?php

namespace App\Ai\Tool;

use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Mouvement\MouvementAvenant;
use App\Ai\Mouvement\MouvementAvenantBuilder;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Services\AvenantRenouvellementResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantSuccessionScope;

/**
 * Outil DÉDIÉ aux quatre MOUVEMENTS d'une police existante : renouvellement,
 * prorogation, annulation, résiliation.
 *
 * Sa raison d'être est de supprimer les questions. Par le chemin générique, un
 * renouvellement « à l'identique » obligeait l'assistant à dérouler le parcours
 * de saisie, l'inventaire des champs et un jeu de questions/réponses — alors que
 * la réponse à CHACUNE de ces questions est déjà dans la police en cours. Ici,
 * un seul argument suffit (la police), le serveur calcule le décalque complet,
 * et l'utilisateur n'a plus qu'à valider. Les trois autres mouvements exigent
 * une date : c'est la SEULE information que l'assistant ait le droit de demander.
 *
 * Il n'introduit AUCUNE logique d'écriture : MouvementAvenantBuilder traduit le
 * mouvement en opérations génériques, et l'outil DÉLÈGUE à preparer_operations
 * (donc au même WorkspaceMutationService : validation, budget, verrou de plan,
 * boutons de validation, exécution, journal). DRY strict.
 *
 * Comme preparer_operations, il n'écrit rien : il prépare un PLAN + un BUDGET
 * que l'utilisateur valide ou annule.
 */
final class PreparerMouvementAvenantTool implements AiToolProduisantUnPlan, AiToolEcriture
{
    /** Nombre maximal de polices proposées quand la référence est ambiguë. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly MouvementAvenantBuilder $builder,
        private readonly JSBDynamicSearchService $searchService,
        private readonly AvenantRenouvellementResolver $renouvellementResolver,
        // Source unique « un nom dicté → un identifiant » : c'est par elle qu'un nom de
        // CLIENT devient une police, sans second tour d'outils.
        private readonly ResolveurDeReferences $resolveur,
    ) {
    }

    public function name(): string
    {
        return 'preparer_mouvement_avenant';
    }

    public function description(): string
    {
        return 'Fait ÉVOLUER une police EXISTANTE — les quatre mouvements du métier : '
            . '"renouvellement" (« renouvelle / reconduis cette police », « à l\'identique », « refais la même '
            . 'police pour l\'année prochaine »), "prorogation" (« proroge / prolonge de 20 jours »), '
            . '"annulation" (« annule cet avenant au 15 juin 2026 ») et "resiliation" (« résilie au 30 janvier »). '
            . 'Désigne la police par avenantId, ou par "police" (la référence dictée par l\'utilisateur). '
            . 'RENOUVELLEMENT : ne demande RIEN à l\'utilisateur — période, prime, composition, échéancier, '
            . 'commission, assureur, référence, partenaires et conditions de partage sont tous dérivés de la '
            . 'police de base par le serveur. PROROGATION / ANNULATION / RÉSILIATION : fournis la durée '
            . '(dureeJours) ou la date d\'effet (dateEffet) — c\'est la seule chose à demander, et seulement si '
            . 'l\'utilisateur ne l\'a pas déjà dite. N\'appelle NI parcours_saisie NI inventaire_champs pour un '
            . 'mouvement. Si l\'utilisateur annonce un écart (« la prime passe à 12 000 », « à effet du 1er août », '
            . '« chez SUNU »), passe-le dans le MÊME appel. L\'outil prépare un PLAN + BUDGET à valider (comme '
            . 'preparer_operations) ; après validation, c\'est TOI qui enregistres. N\'écrit rien.';
    }

    public function aiguillage(): string
    {
        return '« renouvelle / reconduis / proroge / prolonge / annule / résilie cette police » : les quatre '
            . 'actes qui font évoluer une police EXISTANTE. C\'est moi qui ÉCRIS ces mouvements — la vigie des '
            . 'échéances, elle, ne fait qu\'observer.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mouvement' => [
                    'type' => 'string',
                    'enum' => MouvementAvenant::valeurs(),
                    'description' => 'Le mouvement à préparer. "renouvellement" reconduit la police à l\'identique '
                        . 'sur la période suivante ; "prorogation" prolonge la couverture en cours ; "annulation" et '
                        . '"resiliation" y mettent fin à une date donnée.',
                ],
                'avenantId' => [
                    'type' => 'integer',
                    'description' => 'Identifiant de la police (avenant) à faire évoluer. À privilégier : sans '
                        . 'ambiguïté. Obtenu par rechercher_entites ou vigie_echeances.',
                ],
                'police' => [
                    'type' => 'string',
                    'description' => 'À défaut d\'identifiant : la référence de police dictée par l\'utilisateur. '
                        . 'Si plusieurs polices correspondent, l\'outil renvoie la liste à départager.',
                ],
                'client' => [
                    'type' => 'string',
                    'description' => 'À défaut d\'identifiant ET de référence : le NOM du client dicté par '
                        . 'l\'utilisateur (« Kibali Goldmines SA », « Mme Marlette »). L\'outil retrouve seul ses '
                        . 'polices et retient celle qui est EN VIGUEUR. Ne fais JAMAIS une recherche préalable '
                        . 'pour obtenir un identifiant : donne le nom tel qu\'il a été dit.',
                ],
                'dureeJours' => [
                    'type' => 'integer',
                    'description' => 'PROROGATION : nombre de jours de prolongation au-delà de l\'échéance actuelle.',
                ],
                'dateEffet' => [
                    'type' => 'string',
                    'description' => 'ANNULATION / RÉSILIATION : date de prise d\'effet, au format AAAA-MM-JJ.',
                ],
                'dateDebut' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : prise d\'effet imposée (AAAA-MM-JJ), au lieu du lendemain '
                        . 'de l\'échéance. Ne le renseigne QUE si l\'utilisateur l\'a dicté.',
                ],
                'dateFin' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : échéance imposée (AAAA-MM-JJ), au lieu d\'une durée '
                        . 'identique à la police de base.',
                ],
                'referencePolice' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : nouvelle référence de police (par défaut, celle de la '
                        . 'police de base est reconduite).',
                ],
                'numero' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : numéro d\'avenant imposé (par défaut, celui de la police '
                        . 'de base est incrémenté).',
                ],
                'assureurId' => [
                    'type' => 'integer',
                    'description' => 'ÉCART facultatif : autre assureur que celui de la police de base.',
                ],
                'composantes' => [
                    'type' => 'array',
                    'description' => 'ÉCART facultatif : composition de la prime dictée par l\'utilisateur, qui '
                        . 'REMPLACE celle de la police de base. Ne la renseigne que s\'il a donné des montants.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nom' => ['type' => 'string', 'description' => 'Libellé de la composante (ex. "Prime nette", "TVA").'],
                            'montant' => ['type' => 'number', 'description' => 'Montant de la composante.'],
                        ],
                        'required' => ['nom', 'montant'],
                    ],
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que l\'utilisateur '
                        . 'demande de le CHANGER : le plan en attente sera annulé et remplacé.',
                ],
                'abandonnerMouvementExistant' => [
                    'type' => 'boolean',
                    'description' => 'ABANDON d\'un mouvement amorcé. Ne mets true QUE si l\'outil t\'a déjà '
                        . 'répondu « mouvementAmorce » ET que l\'utilisateur a explicitement demandé de repartir '
                        . 'de zéro (« abandonne ce renouvellement », « supprime cette opportunité », « recommence »). '
                        . 'Prépare alors un plan de SUPPRESSION de l\'opportunité dérivée — la police de base est '
                        . 'conservée, ses quatre mouvements redeviennent disponibles. Le plan est soumis à '
                        . 'validation et au mot de passe, comme toute suppression. Ne le mets JAMAIS de ta propre '
                        . 'initiative : c\'est une destruction de données.',
                ],
            ],
            'required' => ['mouvement'],
        ];
    }

    /** Chemin simulé neutralisé : un mouvement relève du LLM réel (comme preparer_operations). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // AUCUNE SORTIE SANS CONSIGNE DE CONDUITE. Un « INTROUVABLE » nu ne dit au modèle
        // que ce qui manque, jamais ce qu'il doit faire : livré seul, il laissait la porte
        // ouverte à l'improvisation (excuses, promesse de rappeler l'outil, voire plan
        // inventé). Chaque refus de cet outil porte donc sa note, comme les autres.
        $mouvement = MouvementAvenant::depuis((string) ($args['mouvement'] ?? ''));
        if ($mouvement === null) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => sprintf(
                    'Mouvement inconnu. Valeurs acceptées : %s.',
                    implode(', ', MouvementAvenant::valeurs()),
                ),
                'note'     => 'Rappelle cet outil AVEC l’une des valeurs acceptées, dans ce même tour. '
                    . 'Ne présente aucun plan et n’annonce aucun bouton.',
            ]);
        }

        // Police résolue STRICTEMENT dans l'entreprise du scope (fail-closed).
        $designation = $this->designation($args);
        $candidats = $this->candidats($args, $scope);
        if ($candidats === []) {
            return AiToolResult::ok([
                'pret'      => false,
                'bloquant'  => sprintf('Aucune police rattachée à %s dans cet espace de travail.', $designation),
                'cherchePar' => $this->critairesEssayes($args),
                'note'      => 'Dis-le en UNE phrase, en NOMMANT ce qui a été cherché (« cherchePar ») pour que '
                    . 'l’utilisateur voie où l’on s’est trompé, puis demande UNE précision utile : la référence '
                    . 'exacte de la police, OU le nom exact du client. Ne présente AUCUN plan, n’annonce AUCUN '
                    . 'bouton, ne relance AUCUN outil et n’invoque aucune panne technique.',
            ]);
        }

        $choix = $this->departager($candidats, $designation);
        if (isset($choix['ambigu'])) {
            return AiToolResult::ok([
                'pret'   => false,
                'ambigu' => array_map($this->resumer(...), $choix['ambigu']),
                'note'   => 'Plusieurs polices correspondent, et le serveur n’a pas pu trancher seul. Demande à '
                    . 'l’utilisateur LAQUELLE, en UNE ligne : présente-les par leur PÉRIODE, leur client et leur '
                    . 'assureur (jamais par leur identifiant, qu’il ne connaît pas), et dis leur statut de '
                    . 'renouvellement. Puis ARRÊTE-TOI : ne relance aucun outil, ne pose aucune autre question et '
                    . 'ne présente aucun plan tant qu’il n’a pas tranché.',
            ]);
        }

        /** @var Avenant $base */
        $base = $choix['police'];
        $defautsDeChoix = $choix['defauts'];

        // DÉCISION EXPLICITE DE NE PAS RENOUVELER : un collègue a écrit noir sur blanc que
        // cette police n'aurait pas de suite. La reconduire en silence effacerait sa
        // décision — et, s'il s'agit d'une annulation ou d'une résiliation, l'acte serait
        // fondé sur une lecture périmée du dossier. On refuse, en NOMMANT la décision et sa
        // sortie : le marquage est réversible, mais c'est à l'utilisateur de le lever.
        if ($base->isNonRenouvelable()) {
            return AiToolResult::ok([
                'pret'             => false,
                'nonRenouvelable'  => true,
                'police'           => $base->getReferencePolice(),
                'motif'            => $base->getNonRenouvelableMotif(),
                'decideeLe'        => $base->getNonRenouvelableLe()?->format('d/m/Y'),
                'decideePar'       => $base->getNonRenouvelablePar()?->getNom(),
                'note' => 'Cette police a été SIGNALÉE comme non renouvelable. Ne prépare AUCUN plan et n’annonce '
                    . 'aucun bouton. Dis-le en une phrase en citant le motif, sa date et son auteur, puis DEMANDE '
                    . 'à l’utilisateur s’il veut lever ce marquage — si oui, appelle '
                    . 'preparer_marquage_non_renouvelable avec mode="lever", et seulement APRÈS, reviens à ce '
                    . 'mouvement.',
            ]);
        }

        // Idempotence : une police ne porte qu'un mouvement à la fois. Sans cette
        // garde, redemander « renouvelle-la » créerait un second jeu d'écritures.
        if ($base->getPisteDeRenouvellement() !== null) {
            return ($args['abandonnerMouvementExistant'] ?? false) === true
                ? $this->planDAbandon($base, $args, $scope)
                : $this->mouvementDejaEnCours($base);
        }

        $decalque = $this->builder->construire($mouvement, $base, $args, $scope);

        if (isset($decalque['bloquant'])) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => $decalque['bloquant'],
                'note'     => 'Explique ce blocage à l’utilisateur en une phrase et propose la correction. '
                    . 'Ne présente aucun plan.',
            ]);
        }

        if (isset($decalque['aDemander'])) {
            return AiToolResult::ok([
                'pret'      => false,
                'aDemander' => $decalque['aDemander'],
                'source'    => $decalque['source'] ?? null,
                'note'      => 'Il manque la SEULE information que tu aies le droit de demander pour ce mouvement. '
                    . 'Pose la question telle quelle, en UNE ligne, sans rien demander d’autre (ni période, ni '
                    . 'prime, ni assureur : tout le reste est dérivé de la police). NOMME au passage la police '
                    . 'concernée (« source » : client, référence, période) pour que l’utilisateur voie que tu l’as '
                    . 'bien trouvée. Puis ARRÊTE-TOI : ne rappelle aucun outil dans ce tour, sa réponse rouvrira '
                    . 'un cycle complet.',
            ]);
        }

        // Traduction en opérations génériques + délégation au moteur unique : le
        // verrou anti-empilement de plans, le budget et les boutons « Valider et
        // exécuter » / « Annuler » viennent de là, jamais d'ici.
        $resultat = $this->preparer->execute([
            'operations'             => $decalque['operations'],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        // LE REFUS DU MOTEUR PASSE AVANT TOUT, ET SEUL. Ses refus (informations
        // manquantes, blocages, verrou « un seul plan en attente ») sont des STATUS_OK
        // porteurs de « pret: false » : ne tester que le statut les laissait filer, et le
        // bloc ci-dessous leur agrafait une « consigne : Présente le plan et le budget »
        // en CONTRADICTION FRONTALE avec leur propre note « n'affiche AUCUN tableau de
        // plan », défauts et écarts à l'appui. Le modèle, recevant les deux ordres et la
        // matière d'un plan, rédigeait un plan en prose — sans bouton, puisque aucune
        // uiAction ne l'accompagnait. C'est le « plan fantôme » vu par l'utilisateur.
        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        // Le contexte du décalque enrichit la réponse du moteur : c'est ce que
        // l'assistant doit ANNONCER (défauts appliqués, écarts, ce qui est
        // reconduit) plutôt que de le demander.
        $data = $resultat->data;
        $data['avertissements'] = array_values(array_unique(array_merge(
            array_map(strval(...), (array) ($data['avertissements'] ?? [])),
            $decalque['avertissements'],
        )));
        // Le choix de la police EST une hypothèse, au même titre que la période ou la
        // prime reconduites : il se dit, il ne se subit pas. Les DÉDUCTIONS DE
        // PLANBUILDER arrivent déjà dans `defauts` — l'union « += » ci-dessous
        // garderait la sienne et TAIRAIT celles-ci en silence, comme pour les
        // avertissements juste au-dessus. On compose donc les listes.
        $data['defauts'] = array_merge(
            (array) ($data['defauts'] ?? []),
            $defautsDeChoix,
            $decalque['defauts'],
        );
        $data += [
            'mouvement'        => $mouvement->value,
            'libelleMouvement' => $mouvement->libelle(),
            'source'           => $decalque['source'],
            'ecarts'           => $decalque['ecarts'],
            'reconduit'        => $decalque['reconduit'],
            'consigne'         => 'Présente le plan et le budget comme pour toute écriture, et ÉNONCE les '
                . '« defauts » : ce sont les hypothèses que tu as appliquées à la place de questions. Si « ecarts » '
                . 'n’est pas vide, dis-le explicitement — ce n’est alors plus « à l’identique ». Ne redemande '
                . 'AUCUNE des informations dérivées. Les tâches et comptes-rendus de la police de base ne sont pas '
                . 'repris ; la tâche de suivi du paiement ajoutée par le plan est décochable.',
        ];

        return AiToolResult::ok($data, $resultat->uiAction);
    }

    /**
     * ABANDONNER UN MOUVEMENT AMORCÉ : la seule façon de rouvrir une police bloquée.
     *
     * On ne supprime rien ici : on PRÉPARE un plan de suppression de l'opportunité
     * dérivée, soumis au même circuit que toute écriture — revue, validation
     * explicite, et mot de passe puisque le plan contient une suppression. La police
     * de base est CONSERVÉE : le moteur coupe le lien `Piste::avenantDeBase` avant de
     * supprimer (cf. LiensProteges), faute de quoi la cascade Doctrine emporterait la
     * police elle-même.
     *
     * REFUS quand le sort est SCELLÉ : un avenant successeur a été émis, la couverture
     * repose sur lui. Supprimer l'opportunité détruirait une police vivante — jamais
     * par ce chemin.
     */
    private function planDAbandon(Avenant $base, array $args, AiScope $scope): AiToolResult
    {
        $derivee = $base->getPisteDeRenouvellement();
        $suite = $this->renouvellementResolver->resoudre($base);

        if (AvenantSuccessionScope::estScelle($suite['code'])) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => sprintf(
                    'Le sort de cette police est SCELLÉ (%s) : son opportunité dérivée porte une suite réelle. '
                    . 'L’abandonner détruirait une police vivante — cet outil ne le fera pas.',
                    $suite['statut'],
                ),
                'note'     => 'Explique-le en une phrase. Ne prépare AUCUN plan. Si l’utilisateur veut vraiment '
                    . 'défaire cette suite, il doit passer par la fiche, où chaque suppression est confirmée '
                    . 'séparément.',
            ]);
        }

        $resultat = $this->preparer->execute([
            'operations' => [[
                'op'     => 'delete',
                'entite' => 'Piste',
                'id'     => $derivee->getId(),
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        return AiToolResult::ok($resultat->data + [
            'abandon'      => true,
            'police'       => $base->getReferencePolice(),
            'pisteDeriveeId' => $derivee->getId(),
            'consigne'     => 'C’est un plan de SUPPRESSION. AVANT toute autre chose, préviens l’utilisateur en '
                . 'clair : nomme l’opportunité dérivée qui disparaîtra, ÉNONCE les « impacts » du plan (ce que la '
                . 'cascade emporte : propositions, échéanciers, paiements déclarés…), et dis que c’est '
                . 'IRRÉVERSIBLE et que son mot de passe lui sera demandé. Précise aussi ce qui est CONSERVÉ : la '
                . 'police « ' . $base->getReferencePolice() . ' » elle-même, qui retrouvera ses quatre mouvements '
                . 'une fois le plan exécuté. N’enjolive pas et ne minimise pas la portée.',
        ], $resultat->uiAction);
    }

    /**
     * LA POLICE PORTE DÉJÀ UN MOUVEMENT — MAIS CE N'EST PAS LA MÊME CHOSE SELON QU'IL EST
     * ABOUTI OU EN PLAN.
     *
     * Cette garde d'idempotence est nécessaire (sans elle, redemander « renouvelle-la »
     * créerait un second jeu d'écritures), mais elle était une IMPASSE SANS SORTIE : elle
     * refusait à vie, sans jamais dire ce qu'il restait à faire. Une police dont le
     * renouvellement était AMORCÉ — opportunité dérivée créée, mais aucun avenant émis —
     * ne pouvait plus être renouvelée par l'assistant, et la rubrique lui retirait ses
     * quatre boutons de mouvement (condition « hasPisteDerivee: false »). L'utilisateur
     * redemandait donc le renouvellement, encore et encore, et aucun bouton n'apparaissait
     * jamais : ni celui du mouvement, ni celui d'un plan. Trois polices étaient dans cet
     * état en production.
     *
     * Or il RESTE une écriture à faire, et c'est une écriture ORDINAIRE que le moteur
     * générique sait produire : faire valider la proposition de renouvellement (créer
     * l'avenant successeur), ou monter cette proposition si elle manque. On la NOMME donc,
     * avec les identifiants nécessaires, pour que l'assistant enchaîne sur un vrai plan —
     * donc un vrai bouton.
     *
     * Le cas SCELLÉ (avenant successeur émis, annulation, résiliation) reste un refus sec :
     * là, il n'y a réellement plus rien à écrire.
     */
    private function mouvementDejaEnCours(Avenant $base): AiToolResult
    {
        $derivee = $base->getPisteDeRenouvellement();
        // On ne dit pas seulement QU'un mouvement existe, on dit ce que la police est
        // DEVENUE : « déjà renouvelée par l'avenant #120 » plutôt que « porte une
        // opportunité dérivée ».
        $suite = $this->renouvellementResolver->resoudre($base);

        $commun = [
            'pret'                 => false,
            'dejaTraite'           => true,
            'police'               => $base->getReferencePolice(),
            'mouvementExistant'    => $derivee->getNom(),
            'pisteDeriveeId'       => $derivee->getId(),
            'statutRenouvellement' => $suite['statut'],
            'suiteDeLaPolice'      => $suite['phrase'],
        ];

        if (AvenantSuccessionScope::estScelle($suite['code'])) {
            return AiToolResult::ok($commun + [
                'note' => 'Le sort de cette police est SCELLÉ : il n’y a plus rien à écrire. Ne prépare AUCUN '
                    . 'plan et n’annonce aucun bouton. Dis à l’utilisateur ce que la police est DEVENUE, en '
                    . 'reprenant « suiteDeLaPolice » : si un avenant lui succède, NOMME-le (numéro et période). '
                    . 'Propose d’ouvrir la fiche s’il veut la modifier.',
            ]);
        }

        // Mouvement AMORCÉ : les propositions de la piste dérivée encore sans avenant sont
        // exactement ce qu'il reste à faire valider.
        $propositions = [];
        foreach ($derivee->getCotations() as $cotation) {
            if ($cotation->getAvenants()->count() === 0) {
                $propositions[] = [
                    'cotationId' => $cotation->getId(),
                    'nom'        => $cotation->getNom(),
                    'assureur'   => $cotation->getAssureur()?->getNom(),
                ];
            }
        }

        $etape = $propositions === []
            ? sprintf(
                'L’opportunité de renouvellement #%d n’a AUCUNE proposition : il faut d’abord en monter une '
                . '(créer une Cotation rattachée à cette opportunité), puis la faire valider.',
                $derivee->getId(),
            )
            : sprintf(
                'La proposition de renouvellement existe déjà (%s) : pour que la police soit reconduite, il '
                . 'faut la FAIRE VALIDER, c’est-à-dire créer l’avenant sur cette cotation.',
                implode(', ', array_map(
                    static fn (array $p): string => sprintf('#%d « %s »', $p['cotationId'], $p['nom']),
                    $propositions,
                )),
            );

        return AiToolResult::ok($commun + [
            'mouvementAmorce'       => true,
            'propositionsEnAttente' => $propositions,
            'prochaineEtape'        => $etape,
            'note' => 'Ce mouvement est AMORCÉ MAIS PAS ABOUTI : la police n’est PAS reconduite, et cet outil '
                . 'ne peut pas en préparer un second (ce serait un doublon). Ne dis donc PAS que c’est fait, et '
                . 'n’annonce SURTOUT pas un bouton ici. Mais ne t’arrête pas là : énonce « prochaineEtape » en '
                . 'une phrase, puis PROPOSE de la réaliser. S’il accepte — ou s’il a déjà dit « renouvelle-la », '
                . 'ce qui vaut acceptation — appelle preparer_operations DANS LE MÊME TOUR pour cette écriture '
                . '(créer l’Avenant sur la cotation indiquée, en reprenant la période qui suit l’échéance de la '
                . 'police de base ; ou créer la Cotation sur l’opportunité #' . $derivee->getId() . ' si aucune '
                . 'proposition n’existe). C’est CE plan-là qui portera le bouton « Valider et exécuter ». '
                . 'Alternative à mentionner s’il préfère repartir de zéro : supprimer l’opportunité dérivée '
                . 'depuis la fiche de la police, ce qui rouvre les quatre mouvements.',
        ]);
    }

    /**
     * Polices candidates dans l'entreprise du scope.
     *
     * TROIS DÉSIGNATIONS, PAS UNE. L'identifiant est le cas idéal ; la référence de
     * police le cas courant ; le NOM DU CLIENT le cas réel. « Kibali Goldmines SA a
     * donné l'ordre de renouveler leur police » ne contient AUCUNE référence — et
     * l'outil, qui ne savait chercher que sur `referencePolice`, répondait « aucune
     * police » à un client qui en avait sept. Le modèle ne pouvait pas corriger : aller
     * chercher le client puis ses polices, c'est un second tour d'outils, que
     * l'architecture interdit. Le serveur fait donc ce chemin lui-même.
     *
     * Un terme donné en « police » mais qui ne ressemble à aucune référence est
     * réessayé comme nom de client : l'utilisateur ne classe pas ses mots, et le modèle
     * remplit l'argument qu'il croit bon.
     *
     * @return array<int, Avenant>
     */
    private function candidats(array $args, AiScope $scope): array
    {
        $id = (int) ($args['avenantId'] ?? 0);
        if ($id > 0) {
            $result = $this->searchService->search(Avenant::class, ['id' => $id], $scope->entreprise, null, 1, 1);
            if (($result['status']['code'] ?? 500) !== 200) {
                return [];
            }

            return array_values(array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant));
        }

        $police = trim((string) ($args['police'] ?? ''));
        if ($police !== '') {
            $parReference = $this->parReferencePolice($police, $scope);
            if ($parReference !== []) {
                return $parReference;
            }
        }

        // Le nom du client, qu'il ait été donné comme tel ou glissé dans « police ».
        foreach ([trim((string) ($args['client'] ?? '')), $police] as $nomClient) {
            if ($nomClient === '') {
                continue;
            }
            $parClient = $this->parClient($nomClient, $scope);
            if ($parClient !== []) {
                return $parClient;
            }
        }

        return [];
    }

    /** @return array<int, Avenant> */
    private function parReferencePolice(string $police, AiScope $scope): array
    {
        $result = $this->searchService->search(
            Avenant::class,
            ['referencePolice' => ['operator' => 'LIKE', 'value' => $police, 'mode' => 'contains']],
            $scope->entreprise,
            null,
            1,
            self::MAX_CANDIDATS,
        );
        if (($result['status']['code'] ?? 500) !== 200) {
            return [];
        }

        $trouves = array_values(array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant));

        // Une correspondance EXACTE tranche d'elle-même : « AXA-1 » ne doit pas
        // rester ambiguë au seul motif que « AXA-12 » existe aussi.
        $exacts = array_values(array_filter(
            $trouves,
            static fn (Avenant $a) => mb_strtolower(trim((string) $a->getReferencePolice())) === mb_strtolower($police),
        ));

        return count($exacts) === 1 ? $exacts : $trouves;
    }

    /**
     * Les polices d'un CLIENT désigné par son nom. Le nom est résolu par la source
     * unique (donc scopée à l'entreprise et fail-closed), puis les avenants sont joints
     * au client par TOUS les chemins possibles — un avenant tient son client de sa
     * cotation, mais aussi de l'opportunité dérivée qui l'a fait naître.
     *
     * @return array<int, Avenant>
     */
    private function parClient(string $nom, AiScope $scope): array
    {
        $reference = $this->resolveur->resoudre('Client', $nom, $scope);
        if (!$reference->estResolue()) {
            return [];
        }

        $result = $this->searchService->search(
            Avenant::class,
            [JSBDynamicSearchService::LIEN_MULTI_CHEMINS => [
                'paths' => ['cotation.piste.client', 'pisteDeRenouvellement.client'],
                'id'    => (int) $reference->id,
            ]],
            $scope->entreprise,
            null,
            1,
            self::MAX_CANDIDATS,
        );
        if (($result['status']['code'] ?? 500) !== 200) {
            return [];
        }

        return array_values(array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant));
    }

    /**
     * DÉPARTAGER PLUSIEURS POLICES SANS POSER DE QUESTION QUAND C'EST POSSIBLE.
     *
     * Un client a rarement une seule police, et un renouvellement en engendre une
     * chaîne : sept avenants portaient la même référence chez le même client. Rendre
     * les sept au modèle, c'était lui faire poser une question à laquelle l'utilisateur
     * ne peut pas répondre — il ne connaît pas les identifiants internes.
     *
     * Deux tamis, dans cet ordre, et chacun est ANNONCÉ (jamais subi) :
     *  1. le sort DÉJÀ RÉGLÉ — une police qui porte déjà un mouvement n'en recevra pas
     *     un second. Si toutes sont dans ce cas, on n'écarte rien : le refus
     *     « dejaTraite », avec sa vraie explication, vaut mieux qu'une liste ;
     *  2. la police EN VIGUEUR aujourd'hui. C'est celle dont parle un courtier quand il
     *     dit « leur police », et c'est précisément la précision qui manquait.
     * À défaut, la plus récemment échue — la seule qu'on puisse encore reconduire.
     *
     * @param array<int, Avenant> $candidats
     *
     * @return array{police: Avenant, defauts: array<int, string>}|array{ambigu: array<int, Avenant>}
     */
    private function departager(array $candidats, string $designation): array
    {
        if (count($candidats) === 1) {
            return ['police' => $candidats[0], 'defauts' => []];
        }

        $defauts = [];

        $disponibles = array_values(array_filter(
            $candidats,
            static fn (Avenant $a) => $a->getPisteDeRenouvellement() === null && !$a->isNonRenouvelable(),
        ));
        if ($disponibles !== [] && count($disponibles) < count($candidats)) {
            $defauts[] = sprintf(
                '%d police(s) de %s ont été écartées : leur sort est déjà réglé (mouvement en cours, '
                . 'suite émise ou décision de non-renouvellement).',
                count($candidats) - count($disponibles),
                $designation,
            );
        }
        $restants = $disponibles !== [] ? $disponibles : $candidats;

        if (count($restants) === 1) {
            return ['police' => $restants[0], 'defauts' => $defauts];
        }

        $aujourdhui = new \DateTimeImmutable('today');
        $enVigueur = array_values(array_filter(
            $restants,
            static fn (Avenant $a) => $a->getStartingAt() !== null
                && $a->getEndingAt() !== null
                && $a->getStartingAt() <= $aujourdhui
                && $a->getEndingAt() >= $aujourdhui,
        ));

        if (count($enVigueur) === 1) {
            $defauts[] = sprintf(
                'Police retenue : celle de %s qui est EN VIGUEUR aujourd’hui (%s → %s). %d autre(s) '
                . 'police(s) existaient, hors couverture à ce jour.',
                $designation,
                $enVigueur[0]->getStartingAt()?->format('d/m/Y') ?? '?',
                $enVigueur[0]->getEndingAt()?->format('d/m/Y') ?? '?',
                count($restants) - 1,
            );

            return ['police' => $enVigueur[0], 'defauts' => $defauts];
        }

        return ['ambigu' => $enVigueur !== [] ? $enVigueur : $restants];
    }

    /** Comment l'utilisateur a désigné la police, dans SES mots — pour le lui redire. */
    private function designation(array $args): string
    {
        $client = trim((string) ($args['client'] ?? ''));
        $police = trim((string) ($args['police'] ?? ''));
        $id     = (int) ($args['avenantId'] ?? 0);

        return match (true) {
            $client !== '' => 'au client « ' . $client . ' »',
            $police !== '' => '« ' . $police . ' »',
            $id > 0        => 'la police #' . $id,
            default        => 'la police demandée',
        };
    }

    /**
     * CE QUI A ÉTÉ CHERCHÉ, ET OÙ. Un « aucune police » nu laisse l'utilisateur
     * deviner s'il s'est trompé de nom, de rubrique ou de périmètre. Nommer les pistes
     * suivies transforme une impasse en question à laquelle il peut répondre.
     *
     * @return array<int, string>
     */
    private function critairesEssayes(array $args): array
    {
        $essais = [];
        if ((int) ($args['avenantId'] ?? 0) > 0) {
            $essais[] = sprintf('identifiant de police #%d', (int) $args['avenantId']);
        }
        if (($police = trim((string) ($args['police'] ?? ''))) !== '') {
            $essais[] = sprintf('référence de police contenant « %s »', $police);
            $essais[] = sprintf('nom de client « %s »', $police);
        }
        if (($client = trim((string) ($args['client'] ?? ''))) !== '') {
            $essais[] = sprintf('nom de client « %s »', $client);
        }

        return array_values(array_unique($essais));
    }

    /** @return array<string, mixed> Fiche courte d'une police, pour départager une ambiguïté. */
    private function resumer(Avenant $avenant): array
    {
        $piste = $avenant->getCotation()?->getPiste();
        $suite = $this->renouvellementResolver->resoudre($avenant);

        return array_filter([
            'id'       => $avenant->getId(),
            'police'   => $avenant->getReferencePolice(),
            'numero'   => $avenant->getNumero(),
            'client'   => $piste?->getClient()?->getNom(),
            'assureur' => $avenant->getCotation()?->getAssureur()?->getNom(),
            'periode'  => sprintf(
                '%s → %s',
                $avenant->getStartingAt()?->format('d/m/Y') ?? '?',
                $avenant->getEndingAt()?->format('d/m/Y') ?? '?',
            ),
            // Sans ces deux-là, l'utilisateur choisit à l'aveugle entre des lignes qui se
            // ressemblent — c'est exactement ce qui rend une question inutilisable.
            'enVigueur'            => $this->estEnVigueur($avenant) ? true : null,
            'statutRenouvellement' => $suite['statut'],
        ], static fn ($v) => $v !== null);
    }

    /** La couverture court-elle aujourd'hui ? (La question que pose vraiment « leur police ».) */
    private function estEnVigueur(Avenant $avenant): bool
    {
        $debut = $avenant->getStartingAt();
        $fin   = $avenant->getEndingAt();
        if ($debut === null || $fin === null) {
            return false;
        }
        $aujourdhui = new \DateTimeImmutable('today');

        return $debut <= $aujourdhui && $fin >= $aujourdhui;
    }
}
