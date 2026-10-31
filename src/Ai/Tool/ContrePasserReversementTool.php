<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Repository\ReversementRetroAgentRepository;
use App\Service\Retro\ContrePassationDeReversement;
use App\Service\Retro\LotDeVersement;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * CONTRE-PASSER UN VIREMENT DE RÉTROCOMMISSION — le pendant Ket de « Contre-passer le virement ».
 *
 * Un reversement enregistré est une pièce comptable (ReversementIntangible, Serge 2026-10-30) :
 * il ne se modifie ni ne se supprime. Une erreur se corrige en ANNULANT le virement entier par des
 * lignes de montant opposé, puis, si besoin, en enregistrant un nouveau versement.
 *
 * AUCUNE LOGIQUE D'ÉCRITURE PROPRE. L'outil traduit le geste en une opération générique
 * (`contrePasser: true` sur le reversement) et DÉLÈGUE à preparer_operations — donc au même
 * WorkspaceMutationService : plan à valider, boutons, re-vérification à l'exécution, journal. Le
 * moteur confie l'acte à ContrePassationDeReversement, le service même de l'écran.
 */
final class ContrePasserReversementTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    /** Nombre maximal de virements proposés quand la référence est ambiguë. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly LotDeVersement $lots,
        private readonly ContrePassationDeReversement $contrePassation,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'contre_passer_reversement';
    }

    public function description(): string
    {
        return 'ANNULE un reversement de rétrocommission déjà enregistré (agent ou partenaire) en le '
            . 'CONTRE-PASSANT : chaque échéance réglée par le virement reçoit une ligne de montant opposé, '
            . 'datée d’aujourd’hui ; le virement d’origine reste visible et le montant redevient dû au '
            . 'bénéficiaire. C’est la SEULE façon de corriger un reversement : il ne se modifie pas (montant, '
            . 'date, bénéficiaire, échéance, compte) et ne se supprime pas. Pour « corriger » un montant ou '
            . 'une date : contre-passe, puis enregistre un nouveau versement. Porte sur le VIREMENT ENTIER '
            . '(toutes ses échéances), une seule fois ; une contre-passation ne se contre-passe pas. Désigne '
            . 'le virement par l’identifiant de n’importe laquelle de ses lignes, ou par sa référence. L’outil prépare un PLAN '
            . 'à valider (comme preparer_operations). N’écrit rien.';
    }

    public function aiguillage(): string
    {
        return '« annule ce reversement / ce virement à l’agent était une erreur / contre-passe le versement / '
            . 'je me suis trompé de montant (ou de date, de bénéficiaire) sur un reversement » : un reversement '
            . 'ne se modifie ni ne se supprime, il se contre-passe — puis, si besoin, un nouveau versement.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reversementId' => [
                    'type' => 'integer',
                    'description' => 'Identifiant d’une ligne du virement (rechercher_entites entite=ReversementRetroAgent, '
                        . 'ou retrocommissions). À privilégier : sans ambiguïté.',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'À défaut d’identifiant : la référence du virement dictée par l’utilisateur. '
                        . 'Si plusieurs virements la portent, l’outil renvoie la liste à départager.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que l’utilisateur '
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

    /** Miroir de la garde du moteur : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->can($scope->invite, 'ReversementRetroAgent', Invite::ACCESS_ECRITURE);
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        if (!$this->estDisponible($scope)) {
            return AiToolResult::horsPerimetre('Rétros intermédiaires');
        }

        // Virement résolu STRICTEMENT dans le cabinet du scope (fail-closed) : un identifiant
        // d'un autre cabinet est « introuvable », jamais nommé.
        $candidats = $this->candidats($args, $scope);
        if ($candidats === []) {
            return AiToolResult::ok([
                'pret' => false,
                'bloquant' => sprintf(
                    'Aucun reversement %s dans cet espace de travail.',
                    isset($args['reference']) ? '« ' . trim((string) $args['reference']) . ' »' : '#' . (int) ($args['reversementId'] ?? 0),
                ),
                'note' => 'Dis-le en UNE phrase et demande la référence exacte du virement (ou retrouve-la avec '
                    . 'rechercher_entites entite=ReversementRetroAgent). Ne présente AUCUN plan et n’annonce AUCUN bouton.',
            ]);
        }
        if (count($candidats) > 1) {
            return AiToolResult::ok([
                'pret' => false,
                'ambigu' => array_map($this->resumer(...), $candidats),
                'note' => 'Plusieurs virements portent cette référence. Demande à l’utilisateur LEQUEL, en UNE ligne '
                    . '(liste courte : bénéficiaire, date, montant) — jamais par identifiant. Puis ARRÊTE-TOI : tu '
                    . 'rappelleras cet outil avec « reversementId » au message SUIVANT.',
            ]);
        }

        $reversement = $candidats[0];

        // LE REFUS SE DIT AVANT LE PLAN : déjà contre-passé, ou lui-même une contre-passation.
        if (($motif = $this->contrePassation->refus($reversement)) !== null) {
            return AiToolResult::ok([
                'pret' => false,
                'virement' => $this->resumer($reversement),
                'bloquant' => $motif,
                'note' => 'Il n’y a rien à contre-passer. Dis-le en une phrase, tel que « bloquant » le formule. '
                    . 'Ne prépare AUCUN plan et n’annonce aucun bouton.',
            ]);
        }

        // LE PORTEUR DU VIREMENT : la recherche du moteur lit la rubrique repliée (une ligne
        // par virement), où seul le porteur figure. L'acte vaut pour tout le virement.
        $porteur = $this->lots->porteurParmi($this->lots->membresDuLot($reversement)) ?? $reversement;
        $resultat = $this->preparer->execute([
            'operations' => [[
                'op' => 'edit',
                'entite' => 'ReversementRetroAgent',
                'id' => $porteur->getId(),
                'champs' => ['contrePasser' => true],
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        // Le refus du moteur passe avant tout, et seul (pas de plan fantôme).
        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        return AiToolResult::ok($resultat->data + [
            'virement' => $this->resumer($reversement),
            'consigne' => 'Présente le plan et le budget comme pour toute écriture. DIS que chaque échéance du '
                . 'virement recevra une ligne de montant opposé datée d’aujourd’hui, que le virement d’origine reste '
                . 'visible, que le montant redevient dû au bénéficiaire, et qu’une contre-passation ne s’annule pas. '
                . 'Si l’utilisateur voulait corriger un montant ou une date, propose ENSUITE d’enregistrer le bon '
                . 'versement.',
        ], $resultat->uiAction);
    }

    /**
     * Les virements candidats, un par LOT (une ligne représentante, le porteur).
     *
     * @return list<ReversementRetroAgent>
     */
    private function candidats(array $args, AiScope $scope): array
    {
        $id = (int) ($args['reversementId'] ?? 0);
        if ($id > 0) {
            $ligne = $this->reversements->findOneBy(['id' => $id, 'entreprise' => $scope->entreprise]);

            return $ligne instanceof ReversementRetroAgent ? [$ligne] : [];
        }

        $reference = trim((string) ($args['reference'] ?? ''));
        if ($reference === '') {
            return [];
        }
        $lignes = $this->reversements->findBy(['reference' => $reference, 'entreprise' => $scope->entreprise], ['id' => 'ASC'], 50);

        $parLot = [];
        foreach ($lignes as $ligne) {
            $parLot[$this->lots->cle($ligne)] ??= $ligne;
        }

        return array_slice(array_values($parLot), 0, self::MAX_CANDIDATS);
    }

    /** @return array<string, mixed> Ce qu'il faut dire d'un virement : bénéficiaire, date, total. */
    private function resumer(ReversementRetroAgent $reversement): array
    {
        $membres = $this->lots->membresDuLot($reversement);

        return [
            'id' => $reversement->getId(),
            'reference' => $reversement->getReference(),
            'beneficiaire' => $reversement->beneficiaireNom(),
            'versele' => $reversement->getPaidAt()?->format('d/m/Y'),
            'echeances' => count($membres),
            'total' => round(array_sum(array_map(static fn (ReversementRetroAgent $r) => (float) $r->getMontant(), $membres)), 2),
            'contrePassation' => $reversement->estUneContrePassation(),
        ];
    }
}
