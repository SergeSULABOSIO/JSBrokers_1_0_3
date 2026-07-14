<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Comptabilite\ComptaExportService;
use App\Entity\Note;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outil d'ACTION UI : lance le TÉLÉCHARGEMENT d'un état existant — classeur
 * comptable Excel (états SYSCOHADA + suivi fiscal) ou note / bordereau PDF.
 * L'outil ne génère AUCUN fichier : il émet une directive `open-url` vers une
 * route d'export EXISTANTE (liste fermée, URL générée côté serveur — jamais
 * une URL venant du modèle), route qui porte sa propre sécurité et, pour
 * l'Excel comptable, son propre métrage de tokens (pas de double facturation).
 *
 * FAIL-CLOSED : lecture exigée sur la pseudo-entité DocumentComptable (Excel)
 * ou sur Note (PDF) ; la note est résolue STRICTEMENT dans l'entreprise du
 * scope avant d'émettre l'URL.
 */
final class ExporterEtatTool implements AiToolInterface
{
    private const ETATS = ['document_comptable', 'note_pdf', 'bordereau_pdf'];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLibelle $libelleur,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function name(): string
    {
        return 'exporter_etat';
    }

    public function description(): string
    {
        return 'Lance le téléchargement d\'un état : « document_comptable » = classeur Excel des '
            . 'états comptables SYSCOHADA (journal, grand-livre, balance, résultat, TFR, bilan, '
            . 'TFT, suivi fiscal, ou all = classeur complet) ; « note_pdf » / « bordereau_pdf » = '
            . 'impression PDF d\'une note de débit/crédit (id requis — obtiens-le d\'abord via '
            . 'rechercher_entites). À appeler quand l\'utilisateur veut exporter, télécharger ou '
            . 'imprimer. Pour CONSULTER les chiffres d\'un état comptable, préférer document_comptable.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'etat' => [
                    'type' => 'string',
                    'enum' => self::ETATS,
                    'description' => 'document_comptable = classeur Excel comptable ; note_pdf = note '
                        . 'de débit/crédit PDF ; bordereau_pdf = bordereau PDF de la note.',
                ],
                'doc' => [
                    'type' => 'string',
                    'enum' => array_merge(array_keys(ComptaExportService::DOCUMENTS), [ComptaExportService::DOC_SUIVI_FISCAL, 'all']),
                    'description' => 'Document comptable à exporter (défaut all = classeur complet).',
                ],
                'exercice' => [
                    'type' => 'integer',
                    'description' => 'Exercice comptable, ex. 2026 (défaut : dernier exercice disponible).',
                ],
                'idNote' => [
                    'type' => 'integer',
                    'description' => 'Identifiant de la note (requis pour note_pdf / bordereau_pdf).',
                ],
            ],
            'required' => ['etat'],
        ];
    }

    /**
     * Chemin simulé : « exporte le classeur comptable / la balance en Excel ».
     * Les PDF exigent un id de note, irrésoluble par mots-clés (LLM réel).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(exporte[rz]?|telecharge[rz]?)\b/', $normalized)) {
            return null;
        }
        if (!preg_match('/\b(excel|classeur|comptab\w*|etats? financiers?)\b/', $normalized)) {
            return null;
        }

        return ['etat' => 'document_comptable', 'doc' => 'all'];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $etat = (string) ($args['etat'] ?? '');
        if (!in_array($etat, self::ETATS, true)) {
            return AiToolResult::introuvable($etat);
        }

        return $etat === 'document_comptable'
            ? $this->exportComptable($args, $scope)
            : $this->exportNotePdf($etat, $args, $scope);
    }

    private function exportComptable(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : même garde que la route d'export elle-même.
        if (!$this->accessResolver->canRead($scope->invite, 'DocumentComptable')) {
            return AiToolResult::horsPerimetre('Documents comptables');
        }

        $docsValides = array_merge(array_keys(ComptaExportService::DOCUMENTS), [ComptaExportService::DOC_SUIVI_FISCAL, 'all']);
        $doc = (string) ($args['doc'] ?? 'all');
        if (!in_array($doc, $docsValides, true)) {
            return AiToolResult::introuvable($doc);
        }

        $parametres = ['idEntreprise' => $scope->entreprise->getId(), 'doc' => $doc];
        $exercice = (int) ($args['exercice'] ?? 0);
        if ($exercice > 0) {
            $parametres['exercice'] = $exercice;
        }

        $libelle = $doc === 'all'
            ? 'classeur comptable complet'
            : (ComptaExportService::DOCUMENTS[$doc] ?? ComptaExportService::SUIVI_FISCAL_LABEL);

        return AiToolResult::ok(
            [
                'etat'    => 'document_comptable',
                'libelle' => $libelle,
                'format'  => 'Excel',
                'note'    => 'Le téléchargement s\'ouvre dans un nouvel onglet de l\'utilisateur '
                    . '(le fichier est généré par le circuit d\'export standard).',
            ],
            uiAction: [
                'type' => 'open-url',
                'url'  => $this->urlGenerator->generate('admin.documentcomptable.export', $parametres),
            ],
        );
    }

    private function exportNotePdf(string $etat, array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : imprimer une note = lire la rubrique Notes.
        $labels = $this->accessResolver->libellesEntites();
        if (!$this->accessResolver->canRead($scope->invite, 'Note')) {
            return AiToolResult::horsPerimetre($labels['Note'] ?? 'Notes');
        }

        $idNote = (int) ($args['idNote'] ?? 0);
        if ($idNote <= 0) {
            return AiToolResult::introuvable($labels['Note'] ?? 'Notes');
        }

        // Scoping OBLIGATOIRE : la note doit exister DANS l'entreprise du scope.
        $result = $this->searchService->search(Note::class, ['id' => $idNote], $scope->entreprise, null, 1, 1);
        $note = ($result['status']['code'] ?? 500) === 200 ? ($result['data'][0] ?? null) : null;
        if ($note === null) {
            return AiToolResult::introuvable(sprintf('%s #%d', $labels['Note'] ?? 'Note', $idNote));
        }

        $route = $etat === 'note_pdf' ? 'admin.etats.imprimer_note' : 'admin.etats.imprimer_bordereau_note';

        return AiToolResult::ok(
            [
                'etat'    => $etat,
                'libelle' => $etat === 'note_pdf' ? 'note (PDF)' : 'bordereau de note (PDF)',
                'cible'   => $this->libelleur->libelle($note, $this->libelleur->displayField(Note::class)),
                'note'    => 'Le PDF s\'ouvre dans un nouvel onglet de l\'utilisateur.',
            ],
            uiAction: [
                'type' => 'open-url',
                'url'  => $this->urlGenerator->generate($route, [
                    'idEntreprise' => $scope->entreprise->getId(),
                    'idNote'       => $idNote,
                    // Paramètre CATCH_ALL requis par la route : cible de repli du redirect d'échec.
                    'currentURL'   => 'admin',
                ]),
            ],
        );
    }
}
