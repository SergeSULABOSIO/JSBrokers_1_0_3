<?php

namespace App\Services\Finance;

use App\Entity\Entreprise;
use App\Entity\NotificationSinistre;
use App\Services\DashboardDataProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ventilation des indicateurs financiers du cabinet selon UN axe au choix :
 * par assureur, risque, client/assuré, portefeuille, partenaire OU par mois.
 *
 * Deux mesures :
 *  - CHIFFRE D'AFFAIRES = commissions réellement ENCAISSÉES (HT = CA comptable ;
 *    TTC = cash perçu). Réutilise le moteur de production encaissée
 *    (DashboardDataProvider), déjà éprouvé et proportionnellement correct sur les
 *    axes multi-valués (risque via prorata). L'axe « partenaire » est M:N : un
 *    encaissement compte pour CHAQUE partenaire de l'affaire (les totaux peuvent
 *    donc se recouper — signalé par « chevauchement »).
 *  - COMPENSATIONS SINISTRES = indemnisations PAYABLE (convenue), PAYÉE (décaissée,
 *    via Paiement lié à une offre d'indemnisation) et SOLDE (reste à payer).
 *
 * Toutes les valeurs sont scopées à l'entreprise et à l'année demandée.
 */
class VentilationFinanciereService
{
    /** Axes de ventilation autorisés (mêmes libellés côté outil IA). */
    public const DIMENSIONS = ['assureur', 'risque', 'client', 'portefeuille', 'partenaire', 'mois'];

    private const MOIS_LABELS = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DashboardDataProvider $dashboard,
    ) {
    }

    // ── Chiffre d'affaires (commissions encaissées) ──────────────────────────

    /**
     * @return array{mesure: string, dimension: string, annee: int, lignes: array<int, array{libelle: string, caHt: float, caTtc: float}>, totalHt: float, totalTtc: float, chevauchement?: bool}
     */
    public function chiffreAffaires(Entreprise $entreprise, string $dimension, ?int $annee = null): array
    {
        $annee = $annee ?: (int) date('Y');

        if ($dimension === 'mois') {
            $table = $this->dashboard->getProductionTableData($entreprise, $annee);
            $taux  = (float) ($table['taxeAssureurTaux'] ?? 0.0);
            $lignes = [];
            foreach ($table['monthTotals'] as $mois => $t) {
                $ttc = (float) ($t['encaissements'] ?? 0.0);
                if ($ttc === 0.0) {
                    continue; // concision : seulement les mois mouvementés.
                }
                $lignes[$mois] = [
                    'libelle' => self::MOIS_LABELS[$mois] ?? ('Mois ' . $mois),
                    'caTtc'   => round($ttc, 2),
                    'caHt'    => $this->ht($ttc, $taux),
                ];
            }
            ksort($lignes);

            return $this->packCa($dimension, $annee, array_values($lignes), false);
        }

        if (\in_array($dimension, ['assureur', 'risque', 'partenaire'], true)) {
            $group = $this->dashboard->getProductionGroupData($entreprise, $annee);
            $taux  = (float) ($group['taxeAssureurTaux'] ?? 0.0);
            $rows  = match ($dimension) {
                'assureur'   => $group['byAssureur'],
                'risque'     => $group['byRisque'],
                'partenaire' => $group['byPartenaire'],
            };

            return $this->packCa($dimension, $annee, $this->mapCaRows($rows, $taux), $dimension === 'partenaire');
        }

        // client / portefeuille : mono-porteur (totaux cohérents avec le CA global).
        $table = $this->dashboard->getProductionTableData($entreprise, $annee);
        $taux  = (float) ($table['taxeAssureurTaux'] ?? 0.0);
        $rows  = $dimension === 'client'
            ? $this->dashboard->getProductionParClient($entreprise, $annee)
            : $this->dashboard->getProductionParPortefeuille($entreprise, $annee);

        return $this->packCa($dimension, $annee, $this->mapCaRows($rows, $taux), false);
    }

    /**
     * @param array<int, array<string, mixed>> $rows lignes du provider (encaissements = cash TTC)
     * @return array<int, array{libelle: string, caHt: float, caTtc: float}>
     */
    private function mapCaRows(array $rows, float $tauxAssureur): array
    {
        $lignes = [];
        foreach ($rows as $row) {
            $ttc = (float) ($row['encaissements'] ?? 0.0);
            if ($ttc === 0.0) {
                continue;
            }
            $lignes[] = [
                'libelle' => (string) ($row['label'] ?? $row['nom'] ?? '—'),
                'caTtc'   => round($ttc, 2),
                'caHt'    => $this->ht($ttc, $tauxAssureur),
            ];
        }

        return $lignes;
    }

    /** HT (CA comptable) = TTC / (1 + tauxAssureur/100). */
    private function ht(float $ttc, float $tauxAssureur): float
    {
        return round($tauxAssureur > 0.0 ? $ttc / (1.0 + $tauxAssureur / 100.0) : $ttc, 2);
    }

    private function packCa(string $dimension, int $annee, array $lignes, bool $chevauchement): array
    {
        $out = [
            'mesure'    => 'chiffre_affaires',
            'dimension' => $dimension,
            'annee'     => $annee,
            'lignes'    => $lignes,
            'totalHt'   => round(array_sum(array_column($lignes, 'caHt')), 2),
            'totalTtc'  => round(array_sum(array_column($lignes, 'caTtc')), 2),
        ];
        if ($chevauchement) {
            $out['chevauchement'] = true; // axe M:N : les lignes peuvent se recouper, le total n'est PAS le CA global.
        }

        return $out;
    }

    // ── Compensations sinistres (payable / payé / solde) ─────────────────────

    /**
     * @return array{mesure: string, dimension: string, annee: int, lignes: array<int, array{libelle: string, payable: float, paye: float, solde: float}>, totalPayable: float, totalPaye: float, totalSolde: float, chevauchement?: bool}
     */
    public function sinistres(Entreprise $entreprise, string $dimension, ?int $annee = null): array
    {
        $annee = $annee ?: (int) date('Y');
        $debut = new \DateTimeImmutable($annee . '-01-01 00:00:00');
        $fin   = new \DateTimeImmutable($annee . '-12-31 23:59:59');

        // Accumulateur : clé d'axe => ['libelle', 'payable', 'paye'].
        $acc = [];
        $add = static function (string $key, string $libelle, string $champ, float $montant) use (&$acc): void {
            if (!isset($acc[$key])) {
                $acc[$key] = ['libelle' => $libelle, 'payable' => 0.0, 'paye' => 0.0];
            }
            $acc[$key][$champ] += $montant;
        };

        // PAYABLE : indemnisations convenues sur les sinistres survenus dans l'année.
        // On PART de l'offre (côté propriétaire) et non de la collection inverse
        // ns.offreIndemnisationSinistres : cette dernière peut être vide sur une
        // NotificationSinistre déjà managée (le fetch-join ne repeuple pas la
        // collection d'une entité en identity-map dont seule la FK a été posée).
        $offres = $this->em->createQuery(
            'SELECT ois, ns, ass, assure, ris, pf
             FROM App\Entity\OffreIndemnisationSinistre ois
             JOIN ois.notificationSinistre ns
             LEFT JOIN ns.assureur ass
             LEFT JOIN ns.assure assure
             LEFT JOIN ns.risque ris
             LEFT JOIN assure.portefeuille pf
             WHERE ns.entreprise = :e AND ns.occuredAt >= :d AND ns.occuredAt <= :f'
        )->setParameter('e', $entreprise)->setParameter('d', $debut)->setParameter('f', $fin)->getResult();

        foreach ($offres as $ois) {
            $payable = (float) ($ois->getMontantPayable() ?? 0.0);
            if ($payable === 0.0) {
                continue;
            }
            $ns = $ois->getNotificationSinistre();
            if (!$ns) {
                continue;
            }
            foreach ($this->clesAxe($ns, $dimension, (int) $ns->getOccuredAt()?->format('n')) as [$key, $libelle]) {
                $add($key, $libelle, 'payable', $payable);
            }
        }

        // PAYÉ : indemnités réellement décaissées dans l'année (Paiement lié à une offre).
        $paiements = $this->em->createQuery(
            'SELECT p, ois, ns, ass, assure, ris, pf
             FROM App\Entity\Paiement p
             JOIN p.offreIndemnisationSinistre ois
             JOIN ois.notificationSinistre ns
             LEFT JOIN ns.assureur ass
             LEFT JOIN ns.assure assure
             LEFT JOIN ns.risque ris
             LEFT JOIN assure.portefeuille pf
             WHERE p.entreprise = :e AND p.paidAt >= :d AND p.paidAt <= :f'
        )->setParameter('e', $entreprise)->setParameter('d', $debut)->setParameter('f', $fin)->getResult();

        foreach ($paiements as $p) {
            $montant = (float) $p->getMontant();
            if ($montant === 0.0) {
                continue;
            }
            $ns = $p->getOffreIndemnisationSinistre()?->getNotificationSinistre();
            if (!$ns) {
                continue;
            }
            foreach ($this->clesAxe($ns, $dimension, (int) $p->getPaidAt()?->format('n')) as [$key, $libelle]) {
                $add($key, $libelle, 'paye', $montant);
            }
        }

        $lignes = [];
        foreach ($acc as $row) {
            $lignes[] = [
                'libelle' => $row['libelle'],
                'payable' => round($row['payable'], 2),
                'paye'    => round($row['paye'], 2),
                'solde'   => round($row['payable'] - $row['paye'], 2),
            ];
        }
        // Tri : mois par n° chronologique, sinon par payable décroissant.
        if ($dimension === 'mois') {
            usort($lignes, static fn ($a, $b) => array_search($a['libelle'], self::MOIS_LABELS, true) <=> array_search($b['libelle'], self::MOIS_LABELS, true));
        } else {
            usort($lignes, static fn ($a, $b) => $b['payable'] <=> $a['payable']);
        }

        $out = [
            'mesure'       => 'sinistres',
            'dimension'    => $dimension,
            'annee'        => $annee,
            'lignes'       => $lignes,
            'totalPayable' => round(array_sum(array_column($lignes, 'payable')), 2),
            'totalPaye'    => round(array_sum(array_column($lignes, 'paye')), 2),
            'totalSolde'   => round(array_sum(array_column($lignes, 'solde')), 2),
        ];
        if ($dimension === 'partenaire') {
            $out['chevauchement'] = true; // M:N : un sinistre compte pour chaque partenaire de l'assuré.
        }

        return $out;
    }

    /**
     * Clé(s) + libellé(s) de l'axe pour un sinistre. Liste (l'axe partenaire est
     * M:N) ; les autres axes renvoient une seule entrée.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function clesAxe(NotificationSinistre $ns, string $dimension, int $mois): array
    {
        switch ($dimension) {
            case 'mois':
                $m = $mois >= 1 && $mois <= 12 ? $mois : 0;
                return [['m' . $m, self::MOIS_LABELS[$m] ?? 'Mois inconnu']];
            case 'assureur':
                $a = $ns->getAssureur();
                return [[$a ? 'a' . $a->getId() : 'none', $a ? (string) $a->getNom() : 'Sans assureur']];
            case 'client':
                $c = $ns->getAssure();
                return [[$c ? 'c' . $c->getId() : 'none', $c ? (string) $c->getNom() : 'Sans client']];
            case 'risque':
                $r = $ns->getRisque();
                $lib = $r ? (string) ($r->getNomComplet() ?? $r->getCode() ?? 'Risque') : 'Sans risque';
                return [[$r ? 'r' . $r->getId() : 'none', $lib]];
            case 'portefeuille':
                $pf = $ns->getAssure()?->getPortefeuille();
                return [[$pf ? 'pf' . $pf->getId() : 'none', $pf ? (string) $pf->getNom() : 'Sans portefeuille']];
            case 'partenaire':
                $parts = $ns->getAssure()?->getPartenaires();
                if (!$parts || \count($parts) === 0) {
                    return [['none', 'Sans partenaire']];
                }
                $out = [];
                foreach ($parts as $par) {
                    $out[] = ['p' . $par->getId(), (string) $par->getNom()];
                }
                return $out;
            default:
                return [['none', '—']];
        }
    }
}
