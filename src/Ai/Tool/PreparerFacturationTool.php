<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\Avenant;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\SourceDeFacturation;

/**
 * Outil d'ÉCRITURE : ÉMETTRE la note qui réclame une commission devenue exigible.
 *
 * ── POURQUOI IL EXISTE ──────────────────────────────────────────────────────
 * Ket poussait chaque jour vers un geste qu'elle était seule à ne pas savoir
 * poser. Sa boussole porte la chaîne « prime payée → commission exigible →
 * facturation en lot (note de débit) → recouvrement », son programme du jour
 * ouvre une section « commissions exigibles » commentée « il est temps de
 * facturer l'assureur » — et quand un courtier a demandé le plan correspondant,
 * elle a répondu qu'elle ne trouvait « aucune tranche de commission associée à
 * cette police ». L'écran, lui, l'affichait : « Commission exigible — 11,60 USD ».
 *
 * ── CE QU'IL N'EST PAS ──────────────────────────────────────────────────────
 * Il n'établit AUCUN chiffre. Savoir COMBIEN reste à facturer est une lecture,
 * et elle a son outil. Celui-ci ÉMET la pièce, et rien d'autre.
 *
 * ⚠ CETTE EXCLUSION EST ÉCRITE ICI, ET C'EST DÉLIBÉRÉ. Mesuré le 2026-09-27 : une
 * phrase qui EXCLUT fait perdre l'outil qui la porte, une phrase qui INVITE fait
 * gagner celui qu'elle nomme. Le renvoi doit donc s'écrire chez celui qu'on veut
 * voir reculer — ici, nous. L'écrire dans `suivi_impayes` avait envoyé sept
 * demandes de solde chez son concurrent.
 *
 * ── AUCUNE LOGIQUE D'ÉCRITURE PROPRE ────────────────────────────────────────
 * Il traduit ses arguments en opérations génériques et DÉLÈGUE à
 * `preparer_operations` : même dry-run, même budget, même barre de validation,
 * même exécution transactionnelle. Et il ne décide pas de ce qui est facturable :
 * cela vient de {@see SourceDeFacturation}, la règle que l'écran applique aussi.
 *
 * ── FAIL-CLOSED ─────────────────────────────────────────────────────────────
 * Écriture sur « Note », et LECTURE sur « Revenus » : sans le droit de voir les
 * revenus, on ne facture pas à l'aveugle.
 */
final class PreparerFacturationTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly SourceDeFacturation $sourceDeFacturation,
        private readonly PreparerOperationsTool $preparer,
    ) {
    }

    public function name(): string
    {
        return 'preparer_facturation';
    }

    public function description(): string
    {
        return 'Émet la NOTE qui réclame une commission de courtage : note de débit (on réclame) '
            . 'ou de crédit (on accorde un avoir), adressée à l\'ASSUREUR ou au CLIENT. '
            . 'Donne trancheId (l\'échéance à facturer) ou avenantId (la police entière) ; le '
            . 'destinataire, le type, l\'objet et les lignes se déduisent du dossier — ne les '
            . 'demande pas. Chaque ligne rattache un revenu à son échéance ; seuls les revenus '
            . 'dont il reste quelque chose à encaisser sont retenus. Prépare un PLAN + BUDGET à '
            . 'valider ; après validation, c\'est toi qui enregistres. '
            . 'N\'ÉTABLIT AUCUN CHIFFRE : pour savoir COMBIEN est exigible ou à recouvrer, '
            . 'c\'est suivi_impayes. Pour une note à un intermédiaire ou à une autorité fiscale, '
            . 'passe par preparer_operations.';
    }

    public function aiguillage(): string
    {
        return 'ÉMETTRE la note de débit ou de crédit d\'une commission (« facture cette commission à '
            . 'l\'assureur », « prépare la note de débit de cette échéance », « établis l\'avoir »). '
            . 'Jamais pour chiffrer ce qui est facturable.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trancheId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de l\'échéance (tranche) à facturer.',
                ],
                'avenantId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de la police, pour facturer toutes ses échéances '
                        . 'encore dues. À la place de trancheId.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['debit', 'credit'],
                    'description' => 'debit = on réclame un paiement (défaut) ; credit = on accorde un avoir.',
                ],
                'destinataire' => [
                    'type' => 'string',
                    'enum' => ['assureur', 'client'],
                    'description' => 'Qui doit payer. Omets-le : l\'assureur est le débiteur ordinaire '
                        . 'd\'une commission de courtage.',
                ],
                'objet' => [
                    'type' => 'string',
                    'description' => 'Objet de la note. Omets-le pour l\'objet déduit de la police.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Précision facultative portée sur la note.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que '
                        . 'l\'utilisateur demande de le CHANGER : le plan en attente est annulé et '
                        . 'remplacé. Sinon, tant qu\'un plan attend, la préparation est refusée.',
                ],
            ],
        ];
    }

    /**
     * Chemin simulé : « facture la commission de la tranche 74 ». L'identifiant doit
     * figurer dans la question — le moteur réel sait le chercher, le simulé non.
     *
     * ⚠ UNE QUESTION N'EST PAS UN ORDRE. « Combien puis-je facturer ? » est une
     * lecture, et elle appartient à suivi_impayes : le corpus de référence la fige
     * ainsi. On exige donc un VERBE À L'IMPÉRATIF suivi de ce qu'on facture.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // « combien / quelle somme / puis-je / est-ce que » : on chiffre, on n'émet pas.
        if (preg_match('/\b(combien|quelle?s? (somme|montant)|puis[- ]je|peux[- ]tu (me )?dire|liste)\b/', $normalized)) {
            return null;
        }

        $ordreDeFacturer = preg_match('/\b(factur|emets?|emettre|etabli[st]|prepare[rz]?)\w*\b/', $normalized)
            && preg_match('/\b(commission|note de debit|note de credit|avoir)\b/', $normalized);
        if (!$ordreDeFacturer) {
            return null;
        }

        if (preg_match('/\btranche\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            return ['trancheId' => (int) $m[1]];
        }
        if (preg_match('/\b(avenant|police)\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            return ['avenantId' => (int) $m[2]];
        }

        return null;
    }

    /** Miroir exact des gardes d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->can($scope->invite, 'Note', Invite::ACCESS_ECRITURE)
            && $this->accessResolver->can($scope->invite, 'RevenuPourCourtier', Invite::ACCESS_LECTURE);
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();

        // FAIL-CLOSED, dans l'ordre : émettre une note, c'est l'écrire.
        if (!$this->accessResolver->can($scope->invite, 'Note', Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre($labels['Note'] ?? 'Notes');
        }
        // Et facturer sans pouvoir lire les revenus reviendrait à facturer à l'aveugle.
        if (!$this->accessResolver->can($scope->invite, 'RevenuPourCourtier', Invite::ACCESS_LECTURE)) {
            return AiToolResult::horsPerimetre($labels['RevenuPourCourtier'] ?? 'Revenus');
        }

        $source = $this->resoudreLaCible($args, $scope);
        if ($source === null) {
            return AiToolResult::introuvable($this->cibleDemandee($args, $labels));
        }

        $type = ($args['type'] ?? 'debit') === 'credit'
            ? Note::TYPE_NOTE_DE_CREDIT
            : Note::TYPE_NOTE_DE_DEBIT;
        $addressedTo = ($args['destinataire'] ?? 'assureur') === 'client'
            ? Note::TO_CLIENT
            : Note::TO_ASSUREUR;

        $entete = $this->sourceDeFacturation->entetePour($source, $type, $addressedTo);
        if ($entete['cible'] === null) {
            return $this->rienAFacturer(sprintf(
                'Cette police n’a pas %s enregistré : je ne sais pas à qui adresser la note. '
                . 'Renseignez-le sur la proposition, ou dites-moi de l’adresser à l’autre partie.',
                $addressedTo === Note::TO_CLIENT ? 'de client' : 'd’assureur',
            ));
        }

        $tranche = $source instanceof Tranche ? $source : null;
        // ⚠ LE TYPE COMPTE DANS LE TRI, PAS SEULEMENT DANS L'EN-TÊTE. Un avoir ne porte
        // pas ce qui reste à facturer, mais ce qui l'a été : sans lui passer le type,
        // un revenu intégralement facturé rendrait une liste vide et aucun avoir ne
        // serait plus préparable.
        $pesee = $this->sourceDeFacturation->pesee(
            $scope->entreprise,
            $addressedTo,
            $entete['cible'],
            $tranche,
            $type,
        );
        if ($pesee['retenus'] === []) {
            return $this->rienAFacturer($this->pourquoiRien($source, $addressedTo, $pesee['ecartes']));
        }
        $facturables = array_map(
            static fn (array $retenu) => $retenu['revenu'],
            $pesee['retenus'],
        );

        // Une ligne par revenu encore dû, rattachée à l'échéance quand on en a une.
        // `quantite` à 1 comme le formulaire en création : on facture l'unité de ce
        // que le revenu porte, le montant se dérive.
        $lignes = [];
        foreach ($facturables as $revenu) {
            $champs = ['revenuFacture' => $revenu->getId(), 'quantite' => 1];
            if ($tranche !== null) {
                $champs['tranche'] = $tranche->getId();
            }
            $lignes[] = ['op' => 'create', 'champs' => $champs];
        }

        $champsNote = [
            'type' => $entete['type'],
            'addressedTo' => $entete['addressedTo'],
            $addressedTo === Note::TO_CLIENT ? 'client' : 'assureur' => $entete['cible'],
            'nom' => $this->objet($args, $entete['nom']),
            // ÉMETTRE, C'EST VALIDER. Une note naît non validée — c'est juste pour une
            // saisie de rubrique, qui se relit avant d'être envoyée. Mais facturer est
            // un acte achevé : la pièce part à l'assureur. Sans ce drapeau, la note
            // n'entrerait jamais au suivi du recouvrement, qui ne compte que les notes
            // validées — et ce suivi resterait vide, comme il l'est depuis toujours.
            'validated' => true,
        ];
        if (($args['description'] ?? null) !== null && $args['description'] !== '') {
            $champsNote['description'] = (string) $args['description'];
        }

        return $this->preparer->execute([
            'operations' => [[
                'op' => 'create',
                'entite' => 'Note',
                'champs' => $champsNote,
                'collections' => [[
                    'collection' => 'articles',
                    'elements' => $lignes,
                ]],
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);
    }

    /**
     * L'échéance ou la police visée, résolue STRICTEMENT dans l'entreprise du scope.
     * null quand rien n'est désigné, ou quand ce qui l'est n'appartient pas au cabinet.
     */
    private function resoudreLaCible(array $args, AiScope $scope): Tranche|Avenant|null
    {
        $trancheId = (int) ($args['trancheId'] ?? 0);
        if ($trancheId > 0) {
            return $this->unSeul(Tranche::class, $trancheId, $scope);
        }

        $avenantId = (int) ($args['avenantId'] ?? 0);
        if ($avenantId > 0) {
            return $this->unSeul(Avenant::class, $avenantId, $scope);
        }

        return null;
    }

    private function unSeul(string $fqcn, int $id, AiScope $scope): Tranche|Avenant|null
    {
        $resultat = $this->searchService->search($fqcn, ['id' => $id], $scope->entreprise, null, 1, 1);
        if (($resultat['status']['code'] ?? 500) !== 200) {
            return null;
        }

        $trouve = $resultat['data'][0] ?? null;

        return $trouve instanceof Tranche || $trouve instanceof Avenant ? $trouve : null;
    }

    /** @param array<string, string> $labels */
    private function cibleDemandee(array $args, array $labels): string
    {
        if ((int) ($args['trancheId'] ?? 0) > 0) {
            return sprintf('%s #%d', $labels['Tranche'] ?? 'Tranches', (int) $args['trancheId']);
        }
        if ((int) ($args['avenantId'] ?? 0) > 0) {
            return sprintf('%s #%d', $labels['Avenant'] ?? 'Avenants', (int) $args['avenantId']);
        }

        return 'l’échéance ou la police à facturer';
    }

    /**
     * UN REFUS QUI NOMME LA CELLULE, jamais un silence. « Rien à facturer » est une
     * information pour le courtier, pas une panne : elle veut dire que la commission
     * est déjà rentrée, ou que la prime ne l'est pas encore.
     */
    private function rienAFacturer(string $motif): AiToolResult
    {
        return AiToolResult::ok([
            'pret' => false,
            'bloquant' => $motif,
            'note' => 'Aucun plan n’a été préparé, et rien n’a été enregistré. Dis-le en une phrase, '
                . 'sans tableau ni bouton de validation.',
        ]);
    }

    /**
     * POURQUOI IL N'Y A RIEN À FACTURER — et, quand c'est le cas, QUELLE PIÈCE le
     * retient.
     *
     * Dire « rien à facturer » à un courtier dont l'écran annonce une commission
     * exigible est une énigme, pas une réponse : il va rouvrir le dossier, chercher,
     * et finir par redemander. On nomme donc la note qui consomme déjà le revenu —
     * le service nous la rend, il serait absurde de la jeter.
     *
     * @param list<array{revenu: mixed, note: ?Note}> $ecartes
     */
    private function pourquoiRien(Tranche|Avenant $source, int $addressedTo, array $ecartes = []): string
    {
        $qui = $addressedTo === Note::TO_CLIENT ? 'ce client' : 'cet assureur';
        $ou = $source instanceof Tranche ? 'cette échéance' : 'cette police';

        foreach ($ecartes as $ecarte) {
            $note = $ecarte['note'] ?? null;
            if ($note instanceof Note) {
                return sprintf(
                    'Il n’y a plus rien à facturer sur %s : la commission due par %s est déjà portée '
                    . 'par la note %s%s. Pour la refacturer, il faut d’abord l’annuler par un avoir, '
                    . 'ou corriger cette note.',
                    $ou,
                    $qui,
                    $note->getReference() ?? ('#' . $note->getId()),
                    $note->getSentAt() !== null ? ' du ' . $note->getSentAt()->format('d/m/Y') : '',
                );
            }
        }

        return sprintf(
            'Il n’y a plus rien à facturer sur %s : aucun revenu dû par %s n’y est rattaché.',
            $ou,
            $qui,
        );
    }

    private function objet(array $args, string $deduit): string
    {
        $dicte = trim((string) ($args['objet'] ?? ''));

        return $dicte !== '' ? $dicte : $deduit;
    }
}
