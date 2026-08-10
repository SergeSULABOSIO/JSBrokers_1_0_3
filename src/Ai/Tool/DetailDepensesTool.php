<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Entity\DepenseCourtier;
use App\Repository\DepenseCourtierRepository;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil de données comptables : DÉTAIL des dépenses/achats du cabinet (charges
 * d'exploitation), ligne par ligne, avec par ligne le montant HT, le TTC et la
 * TVA DÉDUCTIBLE (récupérable) — plus une ventilation par compte OHADA (classe 6)
 * et des totaux. Là où document_comptable (suivi_fiscal) ne donne que l'AGRÉGAT
 * mensuel de TVA déductible, cet outil descend au niveau de chaque facture.
 *
 * FAIL-CLOSED : sans droit de lecture sur « Dépenses » (DepenseCourtier), les
 * données n'existent pas. Scopé à l'entreprise active. La TVA déductible d'une
 * ligne = TTC − HT (dérivée du taux de TVA saisi), même règle que la liste
 * Dépenses de l'espace et que le moteur d'écritures (compte 445).
 */
final class DetailDepensesTool implements AiToolInterface, AiToolConditionnel
{
    /** Lignes de détail restituées par page (sortie compacte). */
    private const MAX_LIGNES = 15;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly DepenseCourtierRepository $depenseRepository,
    ) {
    }

    public function name(): string
    {
        return 'detail_depenses';
    }

    public function description(): string
    {
        return 'Détail des DÉPENSES / achats / charges d\'exploitation du cabinet, ligne par ligne : '
            . 'pour chaque dépense, sa date, son tiers/fournisseur, son compte OHADA (classe 6), son '
            . 'montant HT, son montant TTC et sa TVA DÉDUCTIBLE (récupérable). Fournit aussi la '
            . 'ventilation par compte OHADA et les TOTAUX (HT, TVA déductible, TTC). À appeler pour : '
            . '« liste/détaille mes charges et achats », « le détail de la TVA déductible », « quelles '
            . 'dépenses par facture », « ventilation des charges par compte ». Filtrable par période '
            . '(du/au) et restreignable aux seules dépenses portant une TVA déductible. Complète '
            . 'document_comptable, qui ne donne que l\'AGRÉGAT mensuel de la TVA déductible (pas le '
            . 'détail par ligne). Ne concerne PAS les taxes sur la commission (TVA collectée) : ici '
            . 'c\'est la TVA en amont, supportée sur les achats du cabinet.';
    }

    public function aiguillage(): string
    {
        return 'Le DÉTAIL des dépenses / achats / charges d\'exploitation du cabinet, ligne par ligne : « TVA '
            . 'déductible par facture / par dépense », « liste/détaille/ventile mes charges », ventilation par '
            . 'compte OHADA. Par ligne : HT, TTC, TVA déductible, compte, tiers, plus les totaux. Ne réponds '
            . 'donc JAMAIS que « le détail n\'est pas exposé » : appelle-moi.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'du' => [
                    'type' => 'string',
                    'description' => 'Début de période (AAAA-MM-JJ) sur la date de dépense. Optionnel.',
                ],
                'au' => [
                    'type' => 'string',
                    'description' => 'Fin de période (AAAA-MM-JJ), incluse. Optionnel.',
                ],
                'tvaDeductibleSeulement' => [
                    'type' => 'boolean',
                    'description' => 'Ne garder que les dépenses portant une TVA déductible (taux > 0). Défaut : false (toutes les dépenses).',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page du détail (défaut 1, ' . self::MAX_LIGNES . ' lignes par page). Les totaux et la ventilation portent sur TOUTE la période, pas seulement la page.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Chemin simulé : « détaille / liste mes dépenses / charges / achats », « détail de la
     * TVA déductible », « ventilation des charges ». Le suivi fiscal AGRÉGÉ et la TVA
     * collectée/nette/à reverser restent le domaine de document_comptable.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $parleDeDepenses = (bool) preg_match('/\b(depenses?|achats?|charges? d.?exploitation|fournisseurs?)\b/', $normalized);
        $tvaDeductible = (bool) preg_match('/\btva\b.{0,20}\bdeductibles?\b|\bdeductibles?\b.{0,20}\btva\b/', $normalized);
        $veutDetail = (bool) preg_match('/\b(detail|detaill\w*|liste|lister|tableau|ventil\w*|ligne par ligne|par facture|par compte)\b/', $normalized);

        // Déclenche si on parle de dépenses/charges/achats (avec ou sans « détail »), OU si
        // on demande explicitement le DÉTAIL de la TVA déductible (sinon agrégat = document_comptable).
        if (!$parleDeDepenses && !($tvaDeductible && $veutDetail)) {
            return null;
        }

        $args = [];
        if ($tvaDeductible) {
            $args['tvaDeductibleSeulement'] = true;
        }

        return $args;
    }

    /** Miroir exact de la garde d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->canRead($scope->invite, 'DepenseCourtier');
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();

        // FAIL-CLOSED : sans droit de lecture explicite sur les Dépenses, rien n'existe.
        if (!$this->accessResolver->canRead($scope->invite, 'DepenseCourtier')) {
            return AiToolResult::horsPerimetre($labels['DepenseCourtier'] ?? 'Dépenses');
        }

        $du = $this->parseDate((string) ($args['du'] ?? ''), false);
        $au = $this->parseDate((string) ($args['au'] ?? ''), true);
        $tvaSeulement = (bool) ($args['tvaDeductibleSeulement'] ?? false);
        $page = max(1, (int) ($args['page'] ?? 1));

        /** @var DepenseCourtier[] $depenses */
        $depenses = $this->depenseRepository->findPourDetail(
            (int) $scope->entreprise->getId(),
            $du,
            $au,
            $tvaSeulement,
        );

        // Totaux et ventilation par compte OHADA sur TOUTE la période (pas seulement la page).
        $totHt = 0.0;
        $totTva = 0.0;
        $totTtc = 0.0;
        $parCompte = [];
        foreach ($depenses as $d) {
            $ht = $d->getMontantHtFloat();
            $tva = $d->getTvaDeductibleFloat();
            $ttc = $d->getMontantFloat();
            $totHt += $ht;
            $totTva += $tva;
            $totTtc += $ttc;

            $charge = $d->getCharge();
            $compte = $charge?->getCompteOhada() ?? '—';
            if (!isset($parCompte[$compte])) {
                $parCompte[$compte] = [
                    'compte' => $charge !== null ? sprintf('%s — %s', $charge->getCompteOhada(), $charge->getCompteOhadaLabel()) : '—',
                    'ht' => 0.0, 'tvaDeductible' => 0.0, 'ttc' => 0.0, 'nb' => 0,
                ];
            }
            $parCompte[$compte]['ht'] += $ht;
            $parCompte[$compte]['tvaDeductible'] += $tva;
            $parCompte[$compte]['ttc'] += $ttc;
            $parCompte[$compte]['nb']++;
        }

        // Ventilation triée par TTC décroissant, montants arrondis.
        $ventilation = array_values($parCompte);
        usort($ventilation, static fn (array $a, array $b) => $b['ttc'] <=> $a['ttc']);
        $ventilation = array_map(static fn (array $c) => [
            'compte' => $c['compte'],
            'ht' => round($c['ht'], 2),
            'tvaDeductible' => round($c['tvaDeductible'], 2),
            'ttc' => round($c['ttc'], 2),
            'nb' => $c['nb'],
        ], $ventilation);

        $total = count($depenses);
        $lignesPage = array_slice($depenses, ($page - 1) * self::MAX_LIGNES, self::MAX_LIGNES);

        return AiToolResult::ok(array_filter([
            'periode' => ($du || $au)
                ? trim(sprintf('%s%s', $du ? 'du ' . $du->format('Y-m-d') . ' ' : '', $au ? 'au ' . $au->format('Y-m-d') : ''))
                : 'toutes dates (dépenses non annulées)',
            'note' => 'TVA déductible = TVA récupérable supportée sur les achats du cabinet (TTC − HT), '
                . 'à ne pas confondre avec la TVA collectée sur les commissions.',
            'lignes' => array_map(fn (DepenseCourtier $d) => $this->projeter($d), $lignesPage),
            // Les trois montants d'une dépense ne se confondent pas : HT (la charge),
            // TVA déductible (récupérable) et TTC (le décaissement). On les affiche donc
            // côte à côte, chacun avec son propre total — et le taux de TVA reste en
            // POINTS, jamais additionné.
            'presentation' => $lignesPage === [] ? null : Colonnes::de([
                'date'          => Colonnes::DATE,
                'compte'        => Colonnes::TEXTE,
                'tiers'         => Colonnes::TEXTE,
                'ht'            => Colonnes::MONTANT,
                'tvaDeductible' => Colonnes::MONTANT,
                'ttc'           => Colonnes::MONTANT,
            ]),
            'ventilationParCompte' => $ventilation,
            'totaux' => [
                'ht' => round($totHt, 2),
                'tvaDeductible' => round($totTva, 2),
                'ttc' => round($totTtc, 2),
                'nb' => $total,
            ],
            'page' => $page,
            'total' => $total,
            'tronque' => $total > count($lignesPage) ? true : null,
        ], static fn ($v) => $v !== null));
    }

    /** Projection compacte d'une dépense (montants dérivés, mêmes règles que l'écran). */
    private function projeter(DepenseCourtier $d): array
    {
        $charge = $d->getCharge();

        return array_filter([
            'id' => $d->getId(),
            'date' => $d->getDateDepense()?->format('Y-m-d'),
            'compte' => $charge !== null ? sprintf('%s — %s', $charge->getCompteOhada(), $charge->getCompteOhadaLabel()) : null,
            'charge' => $charge?->getLibelle(),
            'tiers' => $d->getTiersLibelle(),
            'reference' => $d->getReference(),
            'statut' => $d->getStatutLabel(),
            'tauxTva' => $d->getTauxTvaFloat() > 0 ? $d->getTauxTvaFloat() : null,
            'ht' => round($d->getMontantHtFloat(), 2),
            'tvaDeductible' => round($d->getTvaDeductibleFloat(), 2),
            'ttc' => round($d->getMontantFloat(), 2),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Parse une date AAAA-MM-JJ ; borne de fin ramenée à 23:59:59 (inclusive).
     * Renvoie null si vide ou invalide (le filtre est alors ignoré).
     */
    private function parseDate(string $valeur, bool $finDeJournee): ?\DateTimeImmutable
    {
        $valeur = trim($valeur);
        if ($valeur === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        if ($date === false) {
            return null;
        }

        return $finDeJournee ? $date->setTime(23, 59, 59) : $date;
    }
}
