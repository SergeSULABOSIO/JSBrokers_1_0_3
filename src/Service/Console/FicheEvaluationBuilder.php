<?php

namespace App\Service\Console;

use App\Entity\Objectif;
use App\Entity\Utilisateur;
use App\Enum\ObjectifMode;
use App\Repository\ObjectifRepository;

/**
 * @file Construit la fiche d'évaluation d'un collaborateur (calcul à la volée).
 * @description Assemble, pour une période (année + trimestre), les objectifs avec
 * leur atteinte (manuelle ou auto), un score global pondéré, une mention, et
 * l'historique des périodes précédentes. Rien n'est stocké ici : seule la clôture
 * (Evaluation::scoreFige) fige le score, géré par le contrôleur.
 */
class FicheEvaluationBuilder
{
    public function __construct(
        private ObjectifRepository $objectifs,
        private EvaluationMetricProvider $metrics,
    ) {
    }

    /**
     * Fiche complète d'une période.
     *
     * @return array{annee:int, trimestre:int, periodeLabel:string, lignes:array<int, array{objectif:Objectif, atteinte:float, pourcentage:int}>, score:int, mention:string, mentionClass:string, historique:array<int, array{annee:int, trimestre:int, label:string, score:int}>}
     */
    public function build(Utilisateur $collaborateur, int $annee, int $trimestre): array
    {
        [$debut, $fin] = $this->bornes($annee, $trimestre);
        $objectifs = $this->objectifs->findForPeriode($collaborateur, $annee, $trimestre);

        $lignes = [];
        foreach ($objectifs as $objectif) {
            $atteinte = $this->atteinte($objectif, $collaborateur, $debut, $fin);
            $lignes[] = [
                'objectif'      => $objectif,
                'atteinte'      => $atteinte,
                'pourcentage'   => $objectif->pourcentagePour($atteinte),
                'metriqueLabel' => $objectif->getMode() === ObjectifMode::AUTO ? $this->metrics->label($objectif->getMetrique()) : null,
            ];
        }

        $score = $this->scorePondere($lignes);

        return [
            'annee'        => $annee,
            'trimestre'    => $trimestre,
            'periodeLabel' => $annee . ' · ' . ($trimestre === 0 ? 'Annuel' : 'T' . $trimestre),
            'lignes'       => $lignes,
            'score'        => $score,
            'mention'      => $this->mention($score),
            'mentionClass' => $this->mentionClass($score),
            'historique'   => $this->historique($collaborateur, $annee, $trimestre),
        ];
    }

    /** Score global pondéré seul (pour la liste de l'index, sans détail). */
    public function score(Utilisateur $collaborateur, int $annee, int $trimestre): int
    {
        [$debut, $fin] = $this->bornes($annee, $trimestre);
        $lignes = [];
        foreach ($this->objectifs->findForPeriode($collaborateur, $annee, $trimestre) as $objectif) {
            $atteinte = $this->atteinte($objectif, $collaborateur, $debut, $fin);
            $lignes[] = ['objectif' => $objectif, 'pourcentage' => $objectif->pourcentagePour($atteinte)];
        }

        return $this->scorePondere($lignes);
    }

    public function mention(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 70 => 'Atteint',
            $score >= 40 => 'Partiel',
            default      => 'Insuffisant',
        };
    }

    public function mentionClass(int $score): string
    {
        return match (true) {
            $score >= 70 => 'cs-badge--ok',
            $score >= 40 => 'cs-badge--warn',
            default      => 'cs-badge--danger',
        };
    }

    private function atteinte(Objectif $objectif, Utilisateur $collaborateur, \DateTimeImmutable $debut, \DateTimeImmutable $fin): float
    {
        if ($objectif->getMode() === ObjectifMode::AUTO && $objectif->getMetrique() !== null) {
            return $this->metrics->value($objectif->getMetrique(), $collaborateur, $debut, $fin);
        }

        return (float) ($objectif->getValeurManuelle() ?? 0.0);
    }

    /**
     * @param array<int, array{pourcentage:int}> $lignes
     */
    private function scorePondere(array $lignes): int
    {
        $totalPoids = 0;
        $cumul = 0.0;
        foreach ($lignes as $ligne) {
            $poids = $ligne['objectif']->getPoids();
            $totalPoids += $poids;
            $cumul += $ligne['pourcentage'] * $poids;
        }

        if ($totalPoids <= 0) {
            return 0;
        }

        return (int) round($cumul / $totalPoids);
    }

    /**
     * @return array<int, array{annee:int, trimestre:int, label:string, score:int}>
     */
    private function historique(Utilisateur $collaborateur, int $annee, int $trimestre): array
    {
        $historique = [];
        foreach ($this->objectifs->findPeriodes($collaborateur) as $p) {
            if ($p['annee'] === $annee && $p['trimestre'] === $trimestre) {
                continue; // on exclut la période courante
            }
            $historique[] = [
                'annee'     => $p['annee'],
                'trimestre' => $p['trimestre'],
                'label'     => $p['annee'] . ' · ' . ($p['trimestre'] === 0 ? 'Annuel' : 'T' . $p['trimestre']),
                'score'     => $this->score($collaborateur, $p['annee'], $p['trimestre']),
            ];
        }

        return $historique;
    }

    /**
     * Bornes temporelles d'une période. Trimestre 0 = année entière.
     *
     * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
     */
    private function bornes(int $annee, int $trimestre): array
    {
        if ($trimestre <= 0) {
            return [
                new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $annee)),
                new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $annee)),
            ];
        }

        $moisDebut = ($trimestre - 1) * 3 + 1;
        $debut = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $annee, $moisDebut));
        $fin = $debut->modify('+3 months')->modify('-1 second');

        return [$debut, $fin];
    }
}
