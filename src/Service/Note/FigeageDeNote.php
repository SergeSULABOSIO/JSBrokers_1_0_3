<?php

namespace App\Service\Note;

use App\Entity\Note;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;

/**
 * FIGER LES MONTANTS D'UNE NOTE : l'intangibilité d'une pièce émise (règle OHADA,
 * décision de Serge du 2026-10-15).
 *
 * Le montant d'une note n'était stocké nulle part : il se recalculait à chaque lecture
 * depuis le revenu, la prime, le taux, la part de l'échéance. Une prime corrigée après
 * coup changeait donc une facture déjà envoyée — son PDF, son solde, son écriture au
 * journal. Figée, la note garde à jamais les montants de son émission ; un écart
 * ultérieur se règle par un avoir.
 *
 * ── UNE SEULE SOURCE ────────────────────────────────────────────────────────────────
 * On fige ce que rendent les fonctions qui calculaient déjà ces montants
 * (`getArticleMontant`, `getArticleMontantHT`, montants du bordereau, nom de la taxe) —
 * jamais un second calcul. Elles lisent ensuite le figé, d'abord : tous leurs lecteurs
 * suivent sans changer.
 *
 * QUAND : à la validation ({@see EmissionDeNote}), et à l'import d'une note de reprise.
 */
final class FigeageDeNote
{
    public function __construct(private readonly IndicatorCalculationHelper $calcul)
    {
    }

    /**
     * Calcule et pose les montants de chaque ligne et de la note. À appeler AVANT que la
     * note ne soit figée : le calcul lit encore les sources.
     *
     * @return array{ht: float, ttc: float} les montants figés de la note
     */
    public function figer(Note $note): array
    {
        if ($note->estFigee()) {
            return ['ht' => (float) $note->getMontantHtFige(), 'ttc' => (float) $note->getMontantTtcFige()];
        }

        $ht = 0.0;
        $ttc = 0.0;
        $montants = [];
        foreach ($note->getArticles() as $article) {
            $ligneTtc = $this->calcul->getArticleMontant($article);
            $ligneHt = $this->calcul->getArticleMontantHT($article);
            $montants[] = [$article, $ligneHt, $ligneTtc];
            $ht += $ligneHt;
            $ttc += $ligneTtc;
        }

        // Une note de bordereau n'a pas de ligne : ses montants sont ceux du bordereau.
        if ($note->getArticles()->isEmpty() && $note->getBordereau() !== null) {
            $ht = (float) ($note->getBordereau()->getMontantComHtPayableNow() ?? 0.0);
            $ttc = $ht + (float) ($note->getBordereau()->getMontantTaxePayableNow() ?? 0.0);
        }

        $taxe = $ttc - $ht;
        $libelle = $note->getArticles()->isEmpty() && $note->getBordereau() !== null
            ? 'Taxe'
            : $this->calcul->getNoteNomTaxe($note);
        $taux = ($ht > 0.0 && $taxe > 0.0) ? $taxe / $ht * 100 : 0.0;

        // Les lignes d'abord, la note ensuite : figée, elle ne relirait plus ses sources.
        foreach ($montants as [$article, $ligneHt, $ligneTtc]) {
            $article->figer($ligneHt, $ligneTtc);
        }
        $note->figer($ht, $ttc, $libelle, $taux, new \DateTimeImmutable());

        return ['ht' => $ht, 'ttc' => $ttc];
    }
}
