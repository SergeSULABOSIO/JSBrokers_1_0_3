<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Fichier\ConversationFichierResolver;
use App\Ai\Fichier\PieceSourceRattachement;
use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\AssistantConversationFichier;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Repository\InviteRepository;
use App\Repository\PartenaireRepository;
use App\Service\Retro\DefautsDuVersement;
use App\Service\Retro\JustificatifExige;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil d'ÉCRITURE : enregistre le reversement d'une rétrocommission à un INTERMÉDIAIRE —
 * agent interne ou partenaire externe — sur une échéance, ou plusieurs d'un seul geste (LOT).
 *
 * Il n'introduit AUCUNE logique d'écriture : il TRADUIT ses arguments en opérations
 * génériques (create ReversementRetroAgent) et DÉLÈGUE à preparer_operations — donc au même
 * WorkspaceMutationService : validation, budget, plan à valider, exécution transactionnelle,
 * journal. DRY strict, même pattern que SignalerPaiementPrimeTool.
 *
 * ── DEUX FAMILLES, UN SEUL CIRCUIT ──────────────────────────────────────────────────
 * Le partenaire externe passait autrefois par une note de crédit ; il facture désormais le
 * cabinet par sa NOTE DE DÉBIT, et se règle en clair comme un agent, pièce conservée. Les
 * deux familles écrivent donc le même enregistrement, avec le même justificatif obligatoire
 * — seuls le champ bénéficiaire (XOR agent/partenaire), la garde d'accès et le compte
 * SYSCOHADA (6611 pour un salarié, 632 pour un intermédiaire externe) les distinguent.
 *
 * ── LA MAILLE EST L'ÉCHÉANCE, PAS L'AFFAIRE ─────────────────────────────────────────
 * La prime et la commission se paient par TRANCHE : c'est à ce rythme que l'intermédiaire
 * est rémunéré. L'outil règle donc des échéances. Régler l'affaire aurait obligé à répartir
 * ensuite le versement sur ses tranches, selon une règle que personne n'a écrite.
 *
 * ── LE LOT EST NATIF ────────────────────────────────────────────────────────────────
 * `lignes` est une LISTE. Une entrée = un reversement isolé ; N entrées = N opérations dans
 * UN SEUL plan, partageant une référence de lot. L'utilisateur voit les N lignes et le total
 * avant de valider ; un seul budget, une seule confirmation. En comptabilité, le lot
 * n'émettra qu'UNE écriture — celle du virement réel — alors que le solde reste exact
 * échéance par échéance.
 *
 * ── CE QUI EST PROPOSÉ, ET CE QUI EST REFUSÉ ────────────────────────────────────────
 * Le montant par défaut est le solde EXIGIBLE de l'échéance, jamais son simple dû : payer un
 * intermédiaire avant que le cabinet ait encaissé sa commission, c'est avancer sa trésorerie
 * sur une créance non recouvrée. Une échéance sans solde exigible est refusée avec la raison
 * — pas écartée en silence.
 *
 * ── FAIL-CLOSED, ET PLUS STRICT QUE LA LECTURE ──────────────────────────────────────
 * Consulter ses propres rétrocommissions est un droit ; se les VERSER n'en est pas un.
 * Payer un AGENT exige donc canManageInvites() — personne ne se paie soi-même. Payer un
 * PARTENAIRE, qui n'est pas un invité, exige le droit d'ÉCRITURE sur la rubrique qui le
 * gouverne : exactement la garde de l'écran. Les échéances proposées, enfin, ne sont jamais
 * cherchées librement : elles sortent du rapport DU bénéficiaire, lui-même résolu dans
 * l'entreprise du scope.
 */
final class SignalerReversementRetroAgentTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly InviteRepository $inviteRepository,
        private readonly PartenaireRepository $partenaireRepository,
        private readonly PreparerOperationsTool $preparer,
        // LA MATIÈRE DU VERSEMENT vient du MÊME service que le picker : les échéances
        // réglables, leur exigible et leur police. Rien n'est recalculé ici.
        private readonly RapportProductionAgentBuilder $rapportBuilder,
        // PARITÉ AVEC L'ÉCRAN : la référence proposée et le compte débité par défaut
        // ne se recopient pas ici, ils se demandent.
        private readonly DefautsDuVersement $defautsDuVersement,
        // LE JUSTIFICATIF. La règle d'exigence et le classement de la pièce sont tous
        // deux partagés : rien de tout cela ne s'écrit ici une seconde fois.
        private readonly JustificatifExige $justificatifExige,
        private readonly ConversationFichierResolver $fichiers,
        private readonly PieceSourceRattachement $rattachement,
    ) {
    }

    public function name(): string
    {
        return 'signaler_reversement_retro_agent';
    }

    public function description(): string
    {
        return 'Enregistre le REVERSEMENT d\'une rétrocommission à un intermédiaire du cabinet — '
            . 'AGENT INTERNE (agentId) ou PARTENAIRE EXTERNE (partenaireId), jamais les deux — '
            . 'sur une ou PLUSIEURS échéances à la fois. Le partenaire facture le cabinet par sa '
            . 'NOTE DE DÉBIT : il se règle en clair comme un agent, et le cabinet garde la pièce. '
            . 'Fournis le bénéficiaire et `lignes` : une entrée par ÉCHÉANCE réglée, chacune avec '
            . 'son trancheId (ou, à défaut, un avenantId qui règle toutes les échéances exigibles '
            . 'de la police) et, si l\'utilisateur le précise, son montant — sinon le solde '
            . 'EXIGIBLE de l\'échéance s\'applique. Omets `lignes` pour tout régler. '
            . 'C\'est bien par ÉCHÉANCE : la prime et la commission se paient par tranche, donc '
            . 'l\'intermédiaire est rémunéré à ce rythme. Plusieurs lignes = UN SEUL virement : '
            . 'elles partagent une référence de lot, et la comptabilité n\'émettra qu\'une écriture '
            . '— charges de personnel (SYSCOHADA 6611) pour un agent, rétrocommissions (632) pour '
            . 'un partenaire. '
            . 'Le versement est débité du compte bancaire proposé par défaut — le même que '
            . 'l\'écran de reversement ; précise compteBancaireId pour en choisir un autre, '
            . 'ou 0 si l\'utilisateur dit payer en ESPÈCES. '
            . 'À appeler quand l\'utilisateur veut payer, verser ou régler la rétrocommission d\'un '
            . 'agent OU d\'un partenaire. NE PAS utiliser ouvrir_dialogue avec '
            . 'l\'entité Paiement : Paiement = encaissement du courtier. L\'outil prépare un PLAN '
            . '+ BUDGET à valider ; après validation, c\'est TOI qui enregistres. Pour seulement '
            . 'CONSULTER ce qui est dû ou déjà versé, utiliser retrocommissions. '
            . 'UN VERSEMENT NE S\'ENREGISTRE PAS SANS JUSTIFICATIF : fournis fichierId, la '
            . 'pièce de la conversation qui prouve le virement (bordereau, reçu, ou la note de '
            . 'débit du partenaire). Si l\'utilisateur n\'en a joint aucune, demande-la-lui AVANT '
            . 'de m\'appeler — je refuserai sinon.';
    }

    public function aiguillage(): string
    {
        return 'VERSER une rétrocommission à un intermédiaire — agent interne OU partenaire externe '
            . '(« paie à Alice ce qu\'on lui doit », « règle les trois polices en attente de Bruno », '
            . '« reverse à ce partenaire sa commission d\'apport »). Ne touche pas à l\'entité Paiement, '
            . 'qui est l\'encaissement du courtier.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agentId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de l\'AGENT INTERNE bénéficiaire (un invité du '
                        . 'cabinet). Exclusif de partenaireId : un versement va à l\'un OU à l\'autre.',
                ],
                'partenaireId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant du PARTENAIRE EXTERNE bénéficiaire (un intermédiaire '
                        . 'apporteur). Il facture le cabinet par sa note de débit, le cabinet lui '
                        . 'reverse et garde la pièce — même circuit que pour un agent. Exclusif de agentId.',
                ],
                'lignes' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Les ÉCHÉANCES réglées par ce versement. Une seule entrée '
                        . 'pour un reversement isolé ; plusieurs pour un virement unique en couvrant '
                        . 'plusieurs. Omets `lignes` pour régler TOUT ce qui est exigible.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'trancheId' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'description' => 'Identifiant de l\'ÉCHÉANCE (tranche) réglée — la '
                                    . 'maille exacte du versement, celle que propose l\'écran.',
                            ],
                            'avenantId' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'description' => 'À défaut de trancheId : identifiant de l\'avenant '
                                    . '(la police). Toutes ses échéances exigibles sont alors réglées.',
                            ],
                            'montant' => [
                                'type' => 'number',
                                'description' => 'Montant versé sur cette échéance. Omets-le pour '
                                    . 'son solde exigible (versements partiels possibles). Un montant '
                                    . 'sur un avenantId à plusieurs échéances est refusé : rien ne dit '
                                    . 'comment le répartir.',
                            ],
                        ],
                    ],
                ],
                'paidAt' => [
                    'type' => 'string',
                    'description' => 'Date de sortie des fonds (AAAA-MM-JJ). Omets-la pour aujourd\'hui.',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'Référence du virement. Omets-la pour une référence auto-générée.',
                ],
                'fichierId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'OBLIGATOIRE : la pièce jointe de la conversation qui '
                        . 'justifie ce virement (bordereau, reçu signé), lue dans la section '
                        . 'PIÈCES JOINTES. Une seule suffit, même si le versement solde '
                        . 'plusieurs affaires : elle vaut pour tout le virement.',
                ],
                'compteBancaireId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Compte bancaire débité. Omets-le pour le compte proposé '
                        . 'par défaut (le même que l\'écran de reversement). Mets 0 pour un '
                        . 'versement en ESPÈCES, comptabilisé en caisse.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Précision facultative sur ce versement.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que '
                        . 'l\'utilisateur demande de le CHANGER : le plan en attente est annulé et '
                        . 'remplacé. Sinon, tant qu\'un plan attend, la préparation est refusée.',
                ],
            ],
            // agentId n'est PAS requis : le bénéficiaire est agentId OU partenaireId, et
            // JSON Schema ne sait pas exprimer ce OU exclusif. L'outil le vérifie lui-même,
            // et refuse avec le motif — un schéma qui exigerait agentId aurait purement et
            // simplement rendu le partenaire inatteignable.
            'required' => ['fichierId'],
        ];
    }

    /**
     * Chemin simulé : « verse la rétrocommission de l'agent 7 », « paie le partenaire 3 ».
     * L'id doit figurer dans la question (le LLM réel sait le chercher, pas le simulé).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $veutVerser = preg_match('/\b(verse[rsz]?|paie|paye[rsz]?|regle[rsz]?|reverse[rsz]?)\b/', $normalized);
        $parleDeRetro = preg_match('/\b(retrocom\w*|retro\s*commission\w*)\b/', $normalized)
            && preg_match('/\b(agents?|partenaires?|apporteur\w*|interne\w*|externe\w*)\b/', $normalized);
        if (!$veutVerser || !$parleDeRetro) {
            return null;
        }

        // Le partenaire est testé D'ABORD : « la rétro du partenaire 3 » nomme aussi
        // « agent » dans certaines tournures, et le bénéficiaire le plus précis l'emporte.
        if (preg_match('/\bpartenaires?\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            return ['partenaireId' => (int) $m[1]];
        }

        if (preg_match('/\bagent\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            return ['agentId' => (int) $m[1]];
        }

        return null;
    }

    /**
     * Miroir exact de la garde d'execute() : ne pas décrire un outil qui refusera.
     *
     * Deux familles, deux gardes — l'outil est donc offert dès que l'UNE des deux est
     * satisfaite. Le refuser tant que les DEUX ne le sont pas aurait caché le versement aux
     * partenaires à quiconque ne gère pas les invités, alors que l'écran, lui, le propose.
     */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->canManageInvites($scope->invite)
            || $this->accessResolver->can($scope->invite, 'ReversementRetroAgent', Invite::ACCESS_ECRITURE);
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $partenaireId = (int) ($args['partenaireId'] ?? 0);
        $agentId = (int) ($args['agentId'] ?? 0);

        // LE BÉNÉFICIAIRE EST L'UN OU L'AUTRE, jamais les deux : c'est l'invariant de
        // l'entité, et le refuser ICI évite de préparer un plan que l'écriture rejettera.
        if ($agentId <= 0 && $partenaireId <= 0) {
            return AiToolResult::introuvable(
                'Bénéficiaire du versement',
                'Dis À QUI le cabinet reverse : agentId pour un agent interne, partenaireId '
                . 'pour un partenaire externe. Le schéma ne peut pas exiger l\'un OU l\'autre, '
                . 'c\'est donc ici que ça se vérifie.',
            );
        }
        if ($agentId > 0 && $partenaireId > 0) {
            return AiToolResult::introuvable(
                'Bénéficiaire ambigu',
                'Un versement va à un agent interne OU à un partenaire externe, pas aux deux : '
                . 'ne fournis que agentId, ou que partenaireId.',
            );
        }

        // LA GARDE SUIT LA FAMILLE, comme à l'écran. Verser à un AGENT relève de la gestion
        // de l'espace — personne ne se paie soi-même, et l'agent est un salarié. Un
        // partenaire externe n'est pas un invité : lui régler sa facture relève du droit
        // d'écriture sur la rubrique, celui-là même qui la gouverne.
        $gardeSatisfaite = $partenaireId > 0
            ? $this->accessResolver->can($scope->invite, 'ReversementRetroAgent', Invite::ACCESS_ECRITURE)
            : $this->accessResolver->canManageInvites($scope->invite);
        if (!$gardeSatisfaite) {
            return AiToolResult::horsPerimetre('Rétros intermédiaires');
        }

        $partenaire = $partenaireId > 0
            ? $this->partenaireRepository->findOneBy(['id' => $partenaireId, 'entreprise' => $scope->entreprise])
            : null;
        if ($partenaireId > 0 && $partenaire === null) {
            return AiToolResult::introuvable('Partenaire #' . $partenaireId);
        }

        $agent = $partenaire === null && $agentId > 0
            ? $this->inviteRepository->findOneBy(['id' => $agentId, 'entreprise' => $scope->entreprise])
            : null;
        if ($partenaire === null && $agent === null) {
            return AiToolResult::introuvable('Agent #' . $agentId);
        }

        $cible = $partenaire ?? $agent;
        $beneficiaire = $this->rapportBuilder->beneficiaire($cible);
        $beneficiaireNom = $beneficiaire->nom();

        // LES ÉCHÉANCES RÉGLABLES, à la maille où l'argent circule réellement : la prime et
        // la commission se paient par TRANCHE, donc l'intermédiaire est rémunéré à ce
        // rythme. La liste est celle du picker, mot pour mot — c'est la parité écran/Ket.
        $echeances = $this->rapportBuilder->echeancesAVerser($beneficiaire, $scope->entreprise);
        if ($echeances === []) {
            return AiToolResult::introuvable(
                'Échéances à régler pour ' . $beneficiaireNom,
                'Aucune échéance de ce bénéficiaire n\'a de solde exigible : une rétrocommission ne '
                . 'devient réclamable qu\'une fois la commission de courtage encaissée par le cabinet.',
            );
        }

        $selection = $this->selectionner($args, $echeances);
        if ($selection['lignes'] === []) {
            return AiToolResult::introuvable(
                'Échéances réglables pour ' . $beneficiaireNom,
                $selection['refus'] === []
                    ? 'Rien à régler dans ce que tu as demandé.'
                    : implode(' ', $selection['refus']),
            );
        }
        $lignesDemandees = $selection['lignes'];

        // PAS DE VERSEMENT SANS PREUVE — mais APRÈS avoir vérifié qu'il y a de quoi
        // verser. Réclamer un bordereau pour un virement impossible serait absurde : on
        // dit d'abord qu'il n'y a rien à payer, et la pièce ne se demande que lorsque le
        // versement, lui, a un sens.
        //
        // Le refus vient tout de même AVANT le plan : préparer puis refuser à
        // l'exécution aurait fait valider une écriture qui n'aurait jamais lieu. La règle
        // et son message sont ceux de l'écran — un utilisateur ne doit pas apprendre deux
        // formulations d'une contrainte unique.
        $piece = $this->pieceJustificative($args, $scope);
        if ($piece instanceof AiToolResult) {
            return $piece;
        }

        $paidAt = $this->resoudrePaidAt($args);
        $reference = $this->resoudreReference($args, $paidAt);
        $compteId = $this->resoudreCompte($args, $scope);
        // Un lot n'existe qu'à partir de DEUX lignes : un reversement isolé garde
        // lotReference vide, pour ne jamais être fondu dans le lot d'un autre.
        $lotReference = count($lignesDemandees) > 1 ? $reference : null;

        $operations = [];
        $refuses = $selection['refus'];
        foreach ($lignesDemandees as $ligne) {
            $montant = $ligne['montant'] !== null
                ? round((float) $ligne['montant'], 2)
                : $ligne['exigible'];

            if ($montant <= 0.0) {
                $refuses[] = sprintf(
                    'Échéance « %s » de la police %s : rien d\'exigible (la commission de cette '
                    . 'échéance n\'est pas encore encaissée).',
                    $ligne['trancheNom'],
                    $ligne['reference'],
                );
                continue;
            }

            // LE BÉNÉFICIAIRE EST L'UN OU L'AUTRE — le XOR de l'entité, respecté à la source.
            $champs = [
                ($partenaire !== null ? 'partenaire' : 'agent') => $cible->getId(),
                'tranche'   => $ligne['trancheId'],
                'montant'   => $montant,
                'paidAt'    => $paidAt,
                'reference' => $reference,
            ];
            // L'AFFAIRE dit SUR QUOI porte le versement, l'échéance dit QUAND. Une tranche
            // sans avenant existe (cotation non encore éditée) : on ne pose le lien que
            // s'il y en a un, l'invariant de l'entité tolérant l'absence.
            if ($ligne['avenantId'] !== null) {
                $champs['avenant'] = $ligne['avenantId'];
            }
            // Le compte débité manquait ICI, et nulle part ailleurs : tout reversement
            // demandé à Ket partait donc EN CAISSE, quand le même geste à l'écran
            // passait par la banque. Deux comptabilités pour un seul acte.
            if ($compteId !== null) {
                $champs['compteBancaire'] = $compteId;
            }
            if ($lotReference !== null) {
                $champs['lotReference'] = $lotReference;
            }
            if (($args['description'] ?? null) !== null && $args['description'] !== '') {
                $champs['description'] = (string) $args['description'];
            }

            $operations[] = [
                'op'     => 'create',
                'entite' => 'ReversementRetroAgent',
                'champs' => $champs,
                // Une étape par affaire : l'aperçu du plan nomme la police réglée, plutôt
                // que d'afficher N lignes indiscernables.
                'etape'  => sprintf(
                    'Reversement à %s — police %s, échéance %s',
                    $beneficiaireNom,
                    $ligne['reference'],
                    $ligne['trancheNom'],
                ),
            ];
        }

        if ($operations === []) {
            return AiToolResult::introuvable(
                'Échéances réglables pour ' . $beneficiaireNom,
                implode(' ', $refuses),
            );
        }

        // LA PIÈCE EST CLASSÉE DANS LE MÊME PLAN, SUR LA PREMIÈRE LIGNE.
        //
        // Une seule opération Document, donc un seul fichier en base — la consigne du
        // courtier. La première ligne créée est celle qui recevra le plus petit
        // identifiant, donc le PORTEUR du lot au sens de LotDeVersement : la même ligne
        // que celle sur laquelle l'écran dépose sa pièce. Les autres lignes du virement
        // la voient par la lecture du lot.
        $operations = $this->classerLaPiece($operations, $piece);

        return $this->preparer->execute([
            'operations' => $operations,
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);
    }

    /**
     * La pièce qui justifie le virement, ou le refus déjà rédigé.
     *
     * Deux refus distincts, et il importe de les distinguer : AUCUN fichierId donné (la
     * règle n'est pas connue du modèle, on la lui apprend) et un fichierId qui ne désigne
     * rien (le modèle s'est trompé, le résolveur lui liste ce qui existe).
     *
     * @return AssistantConversationFichier|AiToolResult
     */
    private function pieceJustificative(array $args, AiScope $scope): object
    {
        $id = (int) ($args['fichierId'] ?? 0);
        if (!$this->justificatifExige->estSatisfait($id > 0)) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => $this->justificatifExige->messageAssistant(),
                'note'     => 'Dis-le en UNE phrase, et demande la pièce. Ne présente AUCUN plan et '
                    . 'n\'annonce AUCUN bouton.',
            ]);
        }

        $piece = $this->fichiers->trouver($id, $scope);
        if ($piece === null) {
            return AiToolResult::introuvable(
                'Pièce jointe #' . $id,
                'Relis la section PIÈCES JOINTES et reprends l\'identifiant exact du bordereau.',
            );
        }

        return $piece;
    }

    /**
     * Ajoute au plan la création du Document, sur la PREMIÈRE ligne du virement.
     *
     * Le « où » n'est pas décidé ici : PieceSourceRattachement le dérive des formulaires et
     * des métadonnées Doctrine, comme pour attacher_fichier. On ne fait que poser le
     * fragment sur la bonne opération — la première, qui portera le plus petit identifiant.
     *
     * @param array<int, array> $operations
     *
     * @return array<int, array>
     */
    private function classerLaPiece(array $operations, AssistantConversationFichier $piece): array
    {
        $descripteur = $this->rattachement->resoudre('ReversementRetroAgent');
        $fragment = $this->rattachement->fragmentGabarit(
            $descripteur,
            ConversationFichierRef::marqueur((int) $piece->getId()),
            $piece->getNomOriginal() ?: 'Justificatif du virement',
            // La cible n'existe pas encore : le renvoi vaut « la tête du plan », que le
            // moteur résout après création.
            '@socle',
        );
        if ($fragment === null || $fragment['cible'] !== 'collections') {
            // Le rattachement d'un reversement passe par sa collection « Documents ». Si
            // ce n'était plus le cas, mieux vaut un plan SANS pièce — refusé plus haut par
            // la garde — qu'un plan qui range le fichier on ne sait où.
            return $operations;
        }

        $operations[0]['collections'] = array_merge(
            $operations[0]['collections'] ?? [],
            [$fragment['fragment']],
        );

        return $operations;
    }
    /**
     * LES ÉCHÉANCES EFFECTIVEMENT VISÉES, choisies dans celles que le bénéficiaire peut
     * réclamer — jamais construites à côté.
     *
     * C'est le point de scoping : la liste de départ vient des cotations DU bénéficiaire,
     * lui-même résolu dans l'entreprise du scope. Un identifiant dicté qui n'y figure pas
     * est refusé AVEC SON MOTIF, pas écarté en silence — l'ancienne version interrogeait le
     * moteur de recherche avenant par avenant, ce qui laissait passer une police du bon
     * cabinet mais d'un autre bénéficiaire.
     *
     * Trois façons de désigner, toutes ramenées à la même matière :
     *   - rien du tout  → tout ce qui est exigible (« paie à Alice ce qu'on lui doit ») ;
     *   - `trancheId`   → cette échéance précise, la maille du picker ;
     *   - `avenantId`   → toutes les échéances exigibles de cette police.
     *
     * @param array<int, array<string, mixed>> $echeances
     *
     * @return array{lignes: array<int, array<string, mixed>>, refus: array<int, string>}
     */
    private function selectionner(array $args, array $echeances): array
    {
        $fournies = $args['lignes'] ?? null;
        if (!is_array($fournies) || $fournies === []) {
            return ['lignes' => array_map(
                static fn (array $e) => $e + ['montant' => null],
                $echeances,
            ), 'refus' => []];
        }

        $parTranche = [];
        $parAvenant = [];
        foreach ($echeances as $echeance) {
            $parTranche[(int) $echeance['trancheId']] = $echeance;
            if ($echeance['avenantId'] !== null) {
                $parAvenant[(int) $echeance['avenantId']][] = $echeance;
            }
        }

        $lignes = [];
        $refus = [];
        foreach ($fournies as $ligne) {
            $montant = isset($ligne['montant']) && $ligne['montant'] !== null && $ligne['montant'] !== ''
                ? (float) $ligne['montant']
                : null;

            $trancheId = (int) ($ligne['trancheId'] ?? 0);
            if ($trancheId > 0) {
                if (!isset($parTranche[$trancheId])) {
                    $refus[] = sprintf(
                        'Échéance #%d : elle n\'est pas réclamable par ce bénéficiaire (hors de son '
                        . 'périmètre, ou commission pas encore encaissée).',
                        $trancheId,
                    );
                    continue;
                }
                $lignes[] = $parTranche[$trancheId] + ['montant' => $montant];
                continue;
            }

            $avenantId = (int) ($ligne['avenantId'] ?? 0);
            if ($avenantId <= 0) {
                continue;
            }
            if (!isset($parAvenant[$avenantId])) {
                $refus[] = sprintf(
                    'Police #%d : aucune de ses échéances n\'est réclamable par ce bénéficiaire.',
                    $avenantId,
                );
                continue;
            }

            $desiree = $parAvenant[$avenantId];
            // UN MONTANT DICTÉ SUR UNE POLICE À PLUSIEURS ÉCHÉANCES SERAIT AMBIGU : il
            // faudrait inventer une clé de répartition, et personne n'en a écrit. On
            // refuse en nommant l'alternative plutôt que de trancher à la place du courtier.
            if ($montant !== null && count($desiree) > 1) {
                $refus[] = sprintf(
                    'Police #%d : elle a %d échéances exigibles, un montant global ne dit pas comment '
                    . 'les répartir. Indique une ligne par trancheId.',
                    $avenantId,
                    count($desiree),
                );
                continue;
            }
            foreach ($desiree as $echeance) {
                $lignes[] = $echeance + ['montant' => $montant];
            }
        }

        return ['lignes' => $lignes, 'refus' => $refus];
    }

    /** Date fournie, sinon maintenant — format attendu par DateTimeType single_text. */
    private function resoudrePaidAt(array $args): string
    {
        $brut = trim((string) ($args['paidAt'] ?? ''));
        try {
            $date = $brut !== '' ? new \DateTimeImmutable($brut) : new \DateTimeImmutable('now');
        } catch (\Throwable) {
            $date = new \DateTimeImmutable('now');
        }

        return $date->format('Y-m-d\TH:i:s');
    }

    /**
     * Référence fournie, sinon celle que l'écran proposerait — la formule vit dans
     * DefautsDuVersement, elle n'est pas recopiée ici. Elle l'était, et le commentaire
     * promettait « le même schéma que le picker » : une promesse qu'aucun test ne
     * tenait, et que le premier changement de format aurait rompue en silence.
     */
    private function resoudreReference(array $args, string $paidAt): string
    {
        $ref = trim((string) ($args['reference'] ?? ''));
        if ($ref !== '') {
            return $ref;
        }

        try {
            return $this->defautsDuVersement->reference(new \DateTimeImmutable($paidAt));
        } catch (\Throwable) {
            return $this->defautsDuVersement->reference(new \DateTimeImmutable('now'));
        }
    }

    /**
     * Le compte débité : celui qu'on dicte, le compte proposé à défaut, ou AUCUN si
     * l'utilisateur a dit « en espèces » (compteBancaireId = 0).
     *
     * Un identifiant hors de l'entreprise du scope est ignoré et retombe sur le
     * compte proposé : on ne débite pas le compte d'un autre cabinet sur un id dicté.
     */
    private function resoudreCompte(array $args, AiScope $scope): ?int
    {
        if (array_key_exists('compteBancaireId', $args) && (int) $args['compteBancaireId'] === 0) {
            return null;
        }

        $dicte = (int) ($args['compteBancaireId'] ?? 0);
        if ($dicte > 0) {
            foreach ($this->defautsDuVersement->comptes($scope->entreprise) as $compte) {
                if ($compte->getId() === $dicte) {
                    return $dicte;
                }
            }
        }

        return $this->defautsDuVersement->comptePropose($scope->entreprise)?->getId();
    }
}
