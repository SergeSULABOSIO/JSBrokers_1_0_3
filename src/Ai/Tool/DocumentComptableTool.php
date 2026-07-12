<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\CourtierSuiviFiscalService;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Lit les documents comptables SYSCOHADA du cabinet (générés à la volée par
 * CourtierEcritureComptableService, même moteur que la rubrique « Documents
 * comptables » du workspace) : trésorerie (TFT), compte de résultat, bilan,
 * formation du résultat (TFR), balance, journal. C'est l'outil des questions
 * financières d'ENTREPRISE (« quel est le solde de la trésorerie ? », « quel
 * est le résultat net ? ») — par opposition à indicateur_calcule, qui répond
 * sur UN client. Montants en USD (monnaie d'affichage, sans conversion).
 *
 * FAIL-CLOSED : même garde que la rubrique du workspace — lecture de la
 * pseudo-entité « DocumentComptable » (RolesEnFinance::accessDocumentComptable).
 * Restitution compacte (totaux et postes, jamais l'intégralité du journal)
 * pour maîtriser les tokens.
 */
final class DocumentComptableTool implements AiToolInterface
{
    /** Nom court de la pseudo-entité gouvernant l'accès (même clé que la rubrique). */
    private const ENTITY_SHORT_NAME = 'DocumentComptable';

    /** Plafond de lignes restituées pour les états par compte (balance). */
    private const MAX_LIGNES = 50;

    private const DOCUMENTS = [
        'tresorerie'         => 'Tableau de flux de trésorerie',
        'resultat'           => 'Compte de résultat',
        'bilan'              => 'Bilan comparatif',
        'formation_resultat' => 'Formation du résultat (TFR)',
        'balance'            => 'Balance générale',
        'journal'            => 'Journal (totaux)',
        'suivi_fiscal'       => 'Suivi fiscal (TVA et taxes)',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly CourtierEcritureComptableService $comptabilite,
        private readonly CourtierSuiviFiscalService $suiviFiscal,
    ) {
    }

    public function name(): string
    {
        return 'document_comptable';
    }

    public function description(): string
    {
        return "Lit un document comptable de l'entreprise (états SYSCOHADA générés en temps réel, "
            . 'montants en USD) : tresorerie (solde et flux de trésorerie), resultat (produits, '
            . 'charges, résultat net), bilan, formation_resultat (soldes intermédiaires), balance, '
            . 'journal (totaux). À appeler pour toute question sur les FINANCES DE L\'ENTREPRISE '
            . '(trésorerie, solde de caisse/banque, résultat, charges…) — pour les chiffres d\'un '
            . 'client précis, utiliser indicateur_calcule.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => [
                    'type' => 'string',
                    'enum' => array_keys(self::DOCUMENTS),
                    'description' => 'Document comptable à lire.',
                ],
                'exercice' => [
                    'type' => 'integer',
                    'description' => "Exercice (année civile). Défaut : le plus récent disponible.",
                ],
            ],
            'required' => ['document'],
        ];
    }

    /** Chemin simulé : vocabulaire financier d'entreprise => document correspondant. */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        foreach ([
            'suivi_fiscal'       => '/\btva\b|\bsuivi fiscal\b|\bfiscalite\b/',
            'formation_resultat' => '/\bformation du resultat\b|\btfr\b/',
            'bilan'              => '/\bbilan\b/',
            'balance'            => '/\bbalance\b/',
            'journal'            => '/\bjournal\b/',
            'resultat'           => '/\bresultat\b|\bcompte de resultat\b/',
            'tresorerie'         => '/\btresorerie\b|\bflux de tresorerie\b|\bsolde (de |en )?(caisse|banque|tresorerie)\b|\bcash\b/',
        ] as $document => $pattern) {
            if (preg_match($pattern, $normalized)) {
                return ['document' => $document];
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED : même pseudo-entité que la rubrique « Documents comptables ».
        if (!$this->accessResolver->canRead($scope->invite, self::ENTITY_SHORT_NAME)) {
            return AiToolResult::horsPerimetre('Documents comptables');
        }

        $document = (string) ($args['document'] ?? '');
        if (!isset(self::DOCUMENTS[$document])) {
            return AiToolResult::introuvable($document);
        }

        $exercices = $this->comptabilite->exercicesDisponibles($scope->entreprise);
        $exercice = (int) ($args['exercice'] ?? 0) ?: $exercices[0];

        // Suivi fiscal : service dédié (même rubrique, même garde) — totaux annuels
        // par redevable + détail des seuls mois mouvementés (compacité tokens).
        if ($document === 'suivi_fiscal') {
            $suivi = $this->suiviFiscal->suivi($scope->entreprise, $exercice);
            $donnees = [
                'tva' => [
                    'totaux' => $suivi['assureur']['totaux'],
                    'mois'   => array_values(array_filter(
                        $suivi['assureur']['lignes'],
                        static fn (array $l) => $l['collectee'] != 0.0 || $l['deductible'] != 0.0 || $l['reverse'] != 0.0,
                    )),
                ],
                'taxeCourtier' => [
                    'totaux' => $suivi['courtier']['totaux'],
                    'mois'   => array_values(array_filter(
                        $suivi['courtier']['lignes'],
                        static fn (array $l) => $l['du'] != 0.0 || $l['paye'] != 0.0,
                    )),
                ],
            ];
        } else {
            $donnees = $this->extraire($document, $this->comptabilite->documents($scope->entreprise, $exercice));
        }

        return AiToolResult::ok([
            'document'  => $document,
            'titre'     => self::DOCUMENTS[$document],
            'exercice'  => $exercice,
            'exercices' => $exercices,
            'monnaie'   => 'USD',
            'donnees'   => $donnees,
        ]);
    }

    /** Extraction compacte du document demandé (jamais l'intégralité du journal). */
    private function extraire(string $document, array $docs): array
    {
        return match ($document) {
            // TFT : déjà 7 scalaires — la réponse du besoin « solde de trésorerie ».
            'tresorerie' => $docs['tft'],
            'resultat' => $docs['resultat'],
            'bilan' => $docs['bilan'],
            'formation_resultat' => ['postes' => $docs['tfr']],
            'balance' => [
                'totaux' => $docs['balance']['totaux'],
                'lignes' => array_map(
                    static fn (array $l) => [
                        'compte'        => $l['compte'],
                        'libelle'       => $l['libelle'],
                        'soldeDebit'    => $l['cloD'],
                        'soldeCredit'   => $l['cloC'],
                    ],
                    array_slice($docs['balance']['lignes'], 0, self::MAX_LIGNES),
                ),
                'lignesTronquees' => count($docs['balance']['lignes']) > self::MAX_LIGNES,
            ],
            'journal' => [
                'nombreEcritures' => count($docs['journal']['ecritures']),
                'totalDebit'      => $docs['journal']['totalDebit'],
                'totalCredit'     => $docs['journal']['totalCredit'],
            ],
        };
    }
}
