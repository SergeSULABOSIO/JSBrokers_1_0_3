<?php

namespace App\Ai\Tool;

use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Avenant\MarquageNonRenouvelableService;
use App\Services\JSBDynamicSearchService;

/**
 * Outil DÉDIÉ à la décision « cette police n'est PAS à renouveler ».
 *
 * Le courtier apprend l'information quand il l'apprend — le client annonce en mars qu'il
 * revend sa flotte en décembre. Il doit pouvoir le dire à Ket sur-le-champ, en une phrase,
 * et que la note soit consignée avec son auteur et sa date pour le collègue qui rouvrira le
 * dossier des mois plus tard. Sans cet outil, la police restait à jamais dans le chip
 * « Échus », la vigie et la boussole, au même rang qu'un vrai renouvellement en retard.
 *
 * AUCUNE LOGIQUE D'ÉCRITURE PROPRE. Il traduit le geste en une opération générique et
 * DÉLÈGUE à preparer_operations — donc au même WorkspaceMutationService : validation,
 * budget, verrou « un seul plan en attente », boutons de validation, exécution, journal.
 * DRY strict, même patron que PreparerMouvementAvenantTool.
 *
 * CE QU'IL NE FAIT PAS. Il ne résilie rien : la couverture court jusqu'à son terme et la
 * police reste ACTIVE (renewalStatus n'est pas touché). Il ne solde rien non plus — prime,
 * commissions, taxes et rétrocommissions restent à recouvrer, et le résultat les énonce
 * pour que Ket ne laisse jamais croire que le dossier est clos.
 */
final class PreparerMarquageNonRenouvelableTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    /** Nombre maximal de polices proposées quand la référence est ambiguë. */
    private const MAX_CANDIDATS = 8;

    /** Les trois gestes. Le motif n'est exigé que pour les deux premiers. */
    private const MODES = ['marquer', 'motif', 'lever'];

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly JSBDynamicSearchService $searchService,
        private readonly MarquageNonRenouvelableService $marquage,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'preparer_marquage_non_renouvelable';
    }

    public function description(): string
    {
        return 'SIGNALE qu\'une police ne sera PAS renouvelée, avec un MOTIF — « cette police n\'est pas à '
            . 'renouveler », « le client a vendu son véhicule », « il ne renouvellera pas », « il part à la '
            . 'concurrence », « ne la suis plus dans les échéances ». La police sort alors du suivi des '
            . 'échéances (chips, tableau de bord, vigie, programme du jour, boussole). '
            . 'À TOUT MOMENT de la vie de la police, même si elle couvre encore et n\'expire que dans des mois : '
            . 'ne demande JAMAIS d\'attendre l\'échéance. '
            . 'Le MOTIF est OBLIGATOIRE et tu ne l\'INVENTES jamais : s\'il ne l\'a pas donné, demande-le en une '
            . 'ligne. C\'est une note écrite pour le collègue qui rouvrira le dossier plus tard. '
            . 'mode="motif" corrige la note d\'un marquage existant (la date de décision ne bouge pas) ; '
            . 'mode="lever" RETIRE le marquage (« finalement il renouvelle », « remets-la dans les échéances ») '
            . 'et c\'est le seul mode sans motif. '
            . 'CE N\'EST NI UNE RÉSILIATION NI UNE ANNULATION : la couverture court jusqu\'à son terme et tout '
            . 'ce qui reste dû continue d\'être réclamé — pour mettre FIN à la couverture, utilise '
            . 'preparer_mouvement_avenant. L\'outil prépare un PLAN à valider (comme preparer_operations). '
            . 'N\'écrit rien.';
    }

    public function aiguillage(): string
    {
        return '« cette police n\'est pas à renouveler / le client a vendu / il ne renouvellera pas / il part à '
            . 'la concurrence / ne la suis plus dans les échéances », À TOUT MOMENT de la vie de la police '
            . '(même si elle couvre encore et n\'expire que dans des mois : ne demande JAMAIS d\'attendre '
            . 'l\'échéance). Le MOTIF est OBLIGATOIRE et ne s\'INVENTE jamais : s\'il ne l\'a pas donné, demande-le '
            . 'en une ligne — c\'est une note pour le collègue qui rouvrira le dossier. « finalement il '
            . 'renouvelle / remets-la dans les échéances » => le même outil avec mode="lever".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'avenantId' => [
                    'type' => 'integer',
                    'description' => 'Identifiant de la police (avenant). À privilégier : sans ambiguïté. '
                        . 'Obtenu par rechercher_entites ou vigie_echeances.',
                ],
                'police' => [
                    'type' => 'string',
                    'description' => 'À défaut d\'identifiant : la référence de police dictée par l\'utilisateur. '
                        . 'Si plusieurs polices correspondent, l\'outil renvoie la liste à départager.',
                ],
                'motif' => [
                    'type' => 'string',
                    'description' => 'POURQUOI cette police ne sera pas renouvelée, dans les mots de '
                        . 'l\'utilisateur (ex. « le client revend sa flotte en fin d\'année »). OBLIGATOIRE pour '
                        . 'mode="marquer" et mode="motif". Ne l\'invente JAMAIS et ne le déduis pas du contexte : '
                        . 'si l\'utilisateur ne l\'a pas dit, demande-le.',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => self::MODES,
                    'description' => '"marquer" (défaut) signale la police ; "motif" corrige la note d\'un '
                        . 'marquage existant sans toucher à sa date ni à son auteur ; "lever" retire le marquage '
                        . 'et remet la police dans le suivi des échéances.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que l\'utilisateur '
                        . 'demande de le CHANGER : le plan en attente sera annulé et remplacé.',
                ],
            ],
            'required' => [],
        ];
    }

    /** Chemin simulé neutralisé : une écriture relève du LLM réel (comme preparer_operations). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    /** Miroir exact de la garde d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->can($scope->invite, 'Avenant', Invite::ACCESS_MODIFICATION);
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // SÉCURITÉ DANS L'OUTIL, JAMAIS DANS LE PROMPT (fail-closed). Le moteur revérifiera
        // à l'analyse comme à l'exécution ; on refuse ici pour ne pas même laisser fuiter
        // l'existence de la police à qui n'a pas le droit de la modifier.
        if (!$this->accessResolver->can($scope->invite, 'Avenant', Invite::ACCESS_MODIFICATION)) {
            return AiToolResult::horsPerimetre('Avenants');
        }

        $mode = (string) ($args['mode'] ?? 'marquer');
        if (!in_array($mode, self::MODES, true)) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => sprintf('Geste inconnu. Valeurs acceptées : %s.', implode(', ', self::MODES)),
                'note'     => 'Rappelle cet outil AVEC l’une des valeurs acceptées, dans ce même tour. '
                    . 'Ne présente aucun plan et n’annonce aucun bouton.',
            ]);
        }

        // Police résolue STRICTEMENT dans l'entreprise du scope (fail-closed).
        $candidats = $this->candidats($args, $scope);
        if ($candidats === []) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => sprintf(
                    'Aucune police %s dans cet espace de travail.',
                    isset($args['police']) ? '« ' . trim((string) $args['police']) . ' »' : '#' . (int) ($args['avenantId'] ?? 0),
                ),
                'note'     => 'Dis-le en UNE phrase et demande la référence exacte (ou retrouve-la avec '
                    . 'rechercher_entites entite=Avenant). Ne présente AUCUN plan et n’annonce AUCUN bouton.',
            ]);
        }
        if (count($candidats) > 1) {
            return AiToolResult::ok([
                'pret'   => false,
                'ambigu' => array_map($this->resumer(...), $candidats),
                'note'   => 'Plusieurs polices correspondent à cette référence. Demande à l’utilisateur LAQUELLE, '
                    . 'en UNE ligne (liste courte), puis rappelle cet outil avec « avenantId ». Ne présente aucun '
                    . 'plan tant qu’il n’a pas tranché.',
            ]);
        }

        $base = $candidats[0];

        // Le geste doit s'accorder avec l'état réel de la police : sans ce contrôle, un
        // « lève le marquage » sur une police jamais marquée produirait un plan qui n'écrit
        // rien, et le modèle annoncerait une action accomplie.
        if ($etat = $this->incoherence($base, $mode)) {
            return $etat;
        }

        // LE MOTIF NE S'INVENTE PAS. C'est la seule chose que l'outil ait le droit de faire
        // demander — et il vaut mieux une question de plus qu'une note vide dans un dossier
        // rouvert dans huit mois.
        $motif = trim((string) ($args['motif'] ?? ''));
        if ($mode !== 'lever' && $motif === '') {
            return AiToolResult::ok([
                'pret'      => false,
                'police'    => $base->getReferencePolice(),
                'aDemander' => [[
                    'champ'    => 'motif',
                    'question' => sprintf(
                        'Pour quelle raison la police « %s » ne sera-t-elle pas renouvelée ?',
                        (string) $base->getReferencePolice(),
                    ),
                ]],
                'note' => 'Il manque le MOTIF, la SEULE information que tu aies le droit de demander ici. Pose la '
                    . 'question telle quelle, en UNE ligne. Ne l’invente pas, ne le déduis pas du contexte, et ne '
                    . 'présente aucun plan tant que l’utilisateur n’a pas répondu. Rappelle ensuite cet outil.',
            ]);
        }

        // Traduction en une opération générique + délégation au moteur unique : le verrou
        // anti-empilement de plans, le budget et les boutons « Valider et exécuter » /
        // « Annuler » viennent de là, jamais d'ici.
        //
        // nonRenouvelablePar est une relation to-one propriétaire : le moteur l'hydrate
        // depuis son id. C'est l'INVITÉ DU SCOPE, jamais un identifiant venu du modèle —
        // une décision doit engager celui qui la prend.
        $champs = $mode === 'lever'
            ? ['nonRenouvelable' => '0']
            : ['nonRenouvelable' => '1', 'nonRenouvelableMotif' => $motif];
        if ($mode === 'marquer') {
            $champs['nonRenouvelablePar'] = (string) $scope->invite->getId();
        }

        $resultat = $this->preparer->execute([
            'operations' => [[
                'op'     => 'edit',
                'entite' => 'Avenant',
                'id'     => $base->getId(),
                'champs' => $champs,
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        // LE REFUS DU MOTEUR PASSE AVANT TOUT, ET SEUL : ses refus sont des STATUS_OK
        // porteurs de « pret: false », et leur agrafer une consigne « présente le plan »
        // ferait rédiger au modèle un plan en prose sans bouton (plan fantôme).
        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        return AiToolResult::ok($this->enrichir($resultat->data, $base, $mode, $motif), $resultat->uiAction);
    }

    /**
     * Enrichit la réponse du moteur de ce que l'assistante doit ANNONCER : ce qui reste à
     * recouvrer malgré la décision, et le fait que la couverture n'est pas interrompue.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function enrichir(array $data, Avenant $base, string $mode, string $motif): array
    {
        $fin              = $base->getEndingAt();
        $couvertureEnCours = $fin !== null && $fin >= new \DateTimeImmutable('today');

        $data['avertissements'] = array_values(array_unique(array_merge(
            array_map(strval(...), (array) ($data['avertissements'] ?? [])),
            $this->marquage->avertissements($base),
        )));

        $consigne = match ($mode) {
            'lever' => 'Présente le plan et le budget comme pour toute écriture. Dis que la police va REVENIR '
                . 'dans le suivi des échéances, et que le motif consigné reste conservé dans son historique.',
            'motif' => 'Présente le plan et le budget comme pour toute écriture. Précise que SEUL le motif est '
                . 'réécrit : la date de la décision et son auteur restent ceux de l’origine.',
            default => 'Présente le plan et le budget comme pour toute écriture. ÉNONCE le motif tel que '
                . 'l’utilisateur l’a dit, et dis que la police quittera le suivi des échéances. '
                . ($couvertureEnCours
                    ? 'DIS EXPRESSÉMENT que la couverture N’EST PAS interrompue : elle court jusqu’au '
                        . $fin->format('d/m/Y') . ' et la police reste une police active. '
                    : '')
                . 'Si « avertissements » n’est pas vide, ÉNONCE-LES : ce qui reste dû sur cette police continue '
                . 'd’être réclamé, et l’utilisateur ne doit pas croire le dossier clos. Rappelle que la décision '
                . 'est réversible à tout moment.',
        };

        return $data + [
            'mode'              => $mode,
            'police'            => $base->getReferencePolice(),
            'motif'             => $motif !== '' ? $motif : null,
            'couvertureEnCours' => $couvertureEnCours,
            'finCouverture'     => $fin?->format('d/m/Y'),
            'consigne'          => $consigne,
        ];
    }

    /**
     * Le geste demandé est-il possible dans l'état actuel ? Corriger ou lever suppose un
     * marquage ; marquer suppose son absence. Chaque refus porte sa consigne de conduite —
     * un « non » nu laisse le modèle improviser.
     */
    private function incoherence(Avenant $base, string $mode): ?AiToolResult
    {
        $marquee = $base->isNonRenouvelable();

        if ($mode === 'marquer' && $marquee) {
            return AiToolResult::ok([
                'pret'        => false,
                'dejaMarquee' => true,
                'police'      => $base->getReferencePolice(),
                'motifActuel' => $base->getNonRenouvelableMotif(),
                'decideeLe'   => $base->getNonRenouvelableLe()?->format('d/m/Y'),
                'decideePar'  => $base->getNonRenouvelablePar()?->getNom(),
                'note' => 'Cette police est DÉJÀ signalée comme non renouvelable : il n’y a rien à écrire. Ne '
                    . 'prépare AUCUN plan et n’annonce aucun bouton. Dis-le en une phrase en citant le motif, sa '
                    . 'date et son auteur. Si l’utilisateur veut CHANGER la raison, rappelle cet outil avec '
                    . 'mode="motif" ; s’il veut la remettre dans les échéances, avec mode="lever".',
            ]);
        }

        if ($mode !== 'marquer' && !$marquee) {
            return AiToolResult::ok([
                'pret'      => false,
                'nonMarquee' => true,
                'police'    => $base->getReferencePolice(),
                'note' => 'Cette police n’est PAS signalée comme non renouvelable : il n’y a rien à corriger ni à '
                    . 'lever. Ne prépare AUCUN plan et n’annonce aucun bouton. Dis-le en une phrase. Si '
                    . 'l’utilisateur voulait au contraire la signaler, rappelle cet outil avec mode="marquer" et '
                    . 'un motif.',
            ]);
        }

        return null;
    }

    /**
     * Polices candidates dans l'entreprise du scope : par identifiant (une seule) ou par
     * référence de police (plusieurs possibles, à départager).
     *
     * @return array<int, Avenant>
     */
    private function candidats(array $args, AiScope $scope): array
    {
        $id = (int) ($args['avenantId'] ?? 0);
        if ($id > 0) {
            $result = $this->searchService->search(Avenant::class, ['id' => $id], $scope->entreprise, null, 1, 1);
        } else {
            $police = trim((string) ($args['police'] ?? ''));
            if ($police === '') {
                return [];
            }
            $result = $this->searchService->search(
                Avenant::class,
                ['referencePolice' => ['operator' => 'LIKE', 'value' => $police, 'mode' => 'contains']],
                $scope->entreprise,
                null,
                1,
                self::MAX_CANDIDATS,
            );

            // Une correspondance EXACTE tranche d'elle-même : « AXA-1 » ne doit pas rester
            // ambiguë au seul motif que « AXA-12 » existe aussi.
            $exacts = array_values(array_filter(
                array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant),
                static fn (Avenant $a) => mb_strtolower(trim((string) $a->getReferencePolice())) === mb_strtolower($police),
            ));
            if (count($exacts) === 1) {
                return $exacts;
            }
        }

        if (($result['status']['code'] ?? 500) !== 200) {
            return [];
        }

        return array_values(array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant));
    }

    /** @return array<string, mixed> Fiche courte d'une police, pour départager une ambiguïté. */
    private function resumer(Avenant $avenant): array
    {
        $piste = $avenant->getCotation()?->getPiste();

        return [
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
        ];
    }
}
