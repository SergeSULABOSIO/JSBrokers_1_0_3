<?php

namespace App\Ai\Telemetrie;

use App\Ai\Debit\BudgetDebit;

/**
 * Dépouillement de la campagne de mesure : agrège les lignes JSON du canal
 * « assistant_tokens » et répond aux questions qui décident de la suite.
 *
 * La métrique centrale n'est PAS le coût d'un message, mais le PIC par minute
 * glissante, toutes conversations et tous invités confondus : le quota du
 * fournisseur se compte par minute et par modèle, pas par utilisateur. C'est ce
 * qui explique qu'un simple « essaie encore » relance le refus au lieu de le
 * contourner — la minute précédente n'est pas encore retombée.
 *
 * La projection, elle, tranche entre les deux seuls leviers réels : alléger le
 * contexte, ou relever le plafond. Elle rejoue la chronologie observée en
 * retirant une fraction du bloc invariant (prompt système + déclarations
 * d'outils, identiques à chaque tour) et compte les refus qui n'auraient plus
 * eu lieu.
 *
 * Classe pure (aucune E/S, aucune dépendance) : testable sur un échantillon figé.
 */
final class RapportTokens
{
    /**
     * Plafond de tokens d'entrée par minute et par modèle. Source unique :
     * BudgetDebit, qui l'oppose en temps réel au moteur — le rapport et le
     * garde-fou doivent parler du MÊME plafond, sinon la projection ci-dessous
     * décrirait un monde qui n'existe pas.
     */
    public const PLAFOND_ENTREE_PAR_MINUTE = BudgetDebit::PLAFOND_DEFAUT_PAR_MINUTE;

    /** Fenêtre du quota, en secondes. */
    private const FENETRE_SECONDES = BudgetDebit::FENETRE_SECONDES;

    private readonly int $plafond;

    /**
     * @param list<array<string, mixed>> $lignes  contextes des enregistrements, dans l'ordre du journal
     * @param int|null                   $plafond plafond effectif (GEMINI_TPM_PLAFOND) ; null = palier gratuit
     */
    public function __construct(private readonly array $lignes, ?int $plafond = null)
    {
        $this->plafond = $plafond !== null && $plafond > 0 ? $plafond : self::PLAFOND_ENTREE_PAR_MINUTE;
    }

    /** Plafond retenu pour ce dépouillement. */
    public function plafond(): int
    {
        return $this->plafond;
    }

    /** @return list<array<string, mixed>> */
    public function tours(): array
    {
        return array_values(array_filter($this->lignes, static fn (array $l) => ($l['evenement'] ?? null) === 'tour'));
    }

    /** @return list<array<string, mixed>> */
    public function messages(): array
    {
        return array_values(array_filter($this->lignes, static fn (array $l) => ($l['evenement'] ?? null) === 'message'));
    }

    /**
     * Ratio octets→tokens RÉELLEMENT observé, plutôt qu'une constante devinée :
     * le fournisseur compte lui-même les tokens, on connaît les octets envoyés.
     * Sert à convertir une réduction d'octets en économie de tokens.
     */
    public function ratioOctetsParToken(): ?float
    {
        $octets = 0;
        $tokens = 0;
        foreach ($this->tours() as $tour) {
            $octets += $this->octetsTotal($tour);
            $tokens += (int) ($tour['tokensEntree'] ?? 0);
        }

        return $tokens > 0 ? $octets / $tokens : null;
    }

    /**
     * Répartition des issues des messages (reponse, budget_atteint, quota_fournisseur…).
     *
     * @return array<string, int>
     */
    public function issues(): array
    {
        $issues = [];
        foreach ($this->messages() as $message) {
            $issue = (string) ($message['issue'] ?? 'inconnue');
            $issues[$issue] = ($issues[$issue] ?? 0) + 1;
        }
        arsort($issues);

        return $issues;
    }

    /** @return list<int> nombre de tours de chaque message */
    public function toursParMessage(): array
    {
        return array_map(static fn (array $m) => (int) ($m['tours'] ?? 0), $this->messages());
    }

    /** @return list<int> tokens d'entrée cumulés de chaque message */
    public function entreeParMessage(): array
    {
        return array_map(static fn (array $m) => (int) ($m['cumulEntree'] ?? 0), $this->messages());
    }

    /**
     * Part moyenne du bloc INVARIANT (prompt système + déclarations d'outils)
     * dans les octets envoyés. C'est le gisement d'un éventuel dégraissage.
     */
    public function partInvariante(): ?float
    {
        $invariant = 0;
        $total = 0;
        foreach ($this->tours() as $tour) {
            $invariant += $this->octetsInvariants($tour);
            $total += $this->octetsTotal($tour);
        }

        return $total > 0 ? $invariant / $total : null;
    }

    /**
     * Pic de tokens d'entrée sur une minute glissante, et instant du pic.
     *
     * @param float $reductionInvariant 0.0 = tel qu'observé ; 0.2 = bloc invariant allégé de 20 %
     *
     * @return array{pic: int, instant: ?string, depassements: int, messagesEnDepassement: int}
     */
    public function picParMinute(float $reductionInvariant = 0.0): array
    {
        $evenements = [];
        $ratio = $this->ratioOctetsParToken() ?? 3.7;
        foreach ($this->tours() as $tour) {
            $instant = $this->instant($tour);
            if ($instant === null) {
                continue;
            }
            // La réduction ne porte que sur le bloc invariant : l'historique et
            // les résultats d'outils, eux, ne bougeraient pas.
            $economie = (int) round($reductionInvariant * $this->octetsInvariants($tour) / $ratio);
            $evenements[] = [
                'instant' => $instant,
                'tokens'  => max(0, (int) ($tour['tokensEntree'] ?? 0) - $economie),
                'message' => $tour['messageId'] ?? null,
            ];
        }

        usort($evenements, static fn (array $a, array $b) => $a['instant'] <=> $b['instant']);

        $pic = 0;
        $instantDuPic = null;
        $depassements = 0;
        $messagesEnDepassement = [];
        $debut = 0;
        $fenetre = 0;

        foreach ($evenements as $i => $evenement) {
            $fenetre += $evenement['tokens'];
            while ($evenement['instant'] - $evenements[$debut]['instant'] > self::FENETRE_SECONDES) {
                $fenetre -= $evenements[$debut]['tokens'];
                ++$debut;
            }

            if ($fenetre > $pic) {
                $pic = $fenetre;
                $instantDuPic = $evenement['instant'];
            }
            if ($fenetre > $this->plafond) {
                ++$depassements;
                if ($evenement['message'] !== null) {
                    $messagesEnDepassement[$evenement['message']] = true;
                }
            }
            unset($i);
        }

        return [
            'pic'                   => $pic,
            'instant'               => $instantDuPic !== null ? date(\DateTimeInterface::ATOM, $instantDuPic) : null,
            'depassements'          => $depassements,
            'messagesEnDepassement' => \count($messagesEnDepassement),
        ];
    }

    /**
     * Outils par nombre d'apparitions et par coût induit : un outil qui pousse
     * le modèle à enchaîner les tours coûte bien plus qu'un schéma volumineux,
     * puisque CHAQUE tour réexpédie tout le contexte.
     *
     * @return list<array{outil: string, appels: int, messages: int, toursMoyens: float}>
     */
    public function outilsLesPlusCouteux(): array
    {
        $stats = [];
        foreach ($this->messages() as $message) {
            $tours = (int) ($message['tours'] ?? 0);
            $vus = [];
            foreach ((array) ($message['sequenceOutils'] ?? []) as $outil) {
                $outil = (string) $outil;
                $stats[$outil]['appels'] = ($stats[$outil]['appels'] ?? 0) + 1;
                if (!isset($vus[$outil])) {
                    $vus[$outil] = true;
                    $stats[$outil]['messages'] = ($stats[$outil]['messages'] ?? 0) + 1;
                    $stats[$outil]['toursCumules'] = ($stats[$outil]['toursCumules'] ?? 0) + $tours;
                }
            }
        }

        $resultat = [];
        foreach ($stats as $outil => $s) {
            $resultat[] = [
                'outil'       => $outil,
                'appels'      => $s['appels'],
                'messages'    => $s['messages'],
                'toursMoyens' => $s['messages'] > 0 ? $s['toursCumules'] / $s['messages'] : 0.0,
            ];
        }
        usort($resultat, static fn (array $a, array $b) => $b['toursMoyens'] <=> $a['toursMoyens']);

        return $resultat;
    }

    /**
     * Moteurs rencontrés dans la fenêtre. Un mélange signale que la campagne
     * n'est pas homogène : AiEngineResolver bascule sur Anthropic dès qu'une
     * clé est posée, et les mesures ne seraient plus comparables.
     *
     * @return array<string, int>
     */
    public function moteurs(): array
    {
        $moteurs = [];
        foreach ($this->lignes as $ligne) {
            $cle = trim(($ligne['moteur'] ?? '?') . ' / ' . ($ligne['modele'] ?? '?'));
            $moteurs[$cle] = ($moteurs[$cle] ?? 0) + 1;
        }
        arsort($moteurs);

        return $moteurs;
    }

    /** @param list<int|float> $valeurs */
    public static function percentile(array $valeurs, float $rang): ?float
    {
        if ($valeurs === []) {
            return null;
        }
        sort($valeurs);
        $position = $rang * (\count($valeurs) - 1);
        $bas = (int) floor($position);
        $haut = (int) ceil($position);

        return $bas === $haut
            ? (float) $valeurs[$bas]
            : $valeurs[$bas] + ($position - $bas) * ($valeurs[$haut] - $valeurs[$bas]);
    }

    private function octetsInvariants(array $tour): int
    {
        return (int) ($tour['octetsSysteme'] ?? 0) + (int) ($tour['octetsOutils'] ?? 0);
    }

    private function octetsTotal(array $tour): int
    {
        return $this->octetsInvariants($tour) + (int) ($tour['octetsHistorique'] ?? 0);
    }

    private function instant(array $tour): ?int
    {
        $horodatage = $tour['horodatage'] ?? null;
        if (!\is_string($horodatage)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $horodatage);
        if ($date !== false) {
            return $date->getTimestamp();
        }

        // Repli tolérant : un tour écarté pour cause d'horodatage inhabituel
        // (microsecondes, autre variante ISO) ferait BAISSER le pic calculé —
        // c'est le sens de l'erreur à éviter, puisqu'on s'en sert pour juger si
        // le plafond est franchi.
        $instant = strtotime($horodatage);

        return $instant === false ? null : $instant;
    }
}
