<?php

namespace App\Services\Tranche;

use App\Ai\Finance\EconomieTranche;
use App\Echange\Etat\MouvementDeReglement;
use App\Echange\Etat\PiecesDeReglement;
use App\Entity\Taxe;
use App\Entity\Tranche;
use Psr\Log\LoggerInterface;

/**
 * LE RELEVÉ FINANCIER D'UNE TRANCHE, FAMILLE PAR FAMILLE — ce que lit le gestionnaire de
 * compte dans les onglets Prime, Commission, Rétrocommissions et Taxes de la fiche.
 *
 * ── DEUX SOURCES, ET AUCUN CALCUL ICI ───────────────────────────────────────────────
 *  - LA SYNTHÈSE (dû, exigible, payé, solde) est LUE sur les indicateurs que
 *    TrancheIndicatorStrategy pose sur la tranche, nommés par EconomieTranche : le chiffre
 *    de l'onglet est celui de la fiche, de la barre des totaux et de Ket, au centime.
 *  - LES LIGNES viennent de PiecesDeReglement, qui emprunte les chemins exacts du calcul.
 *
 * ── LE PIED ÉGALE LE « PAYÉ » ───────────────────────────────────────────────────────
 * Chaque part est arrondie au centime, et la DERNIÈRE ligne absorbe l'écart d'arrondi
 * (33,33 × 3 = 99,99 pour 100,00). Cette absorption est bornée à ce qu'un arrondi peut
 * produire — un demi-centime par ligne. Au-delà, ce n'est plus un arrondi mais une
 * divergence entre les lignes et l'indicateur : on ne la maquille pas, on la journalise,
 * et le test de parité la fait échouer.
 */
final class ReleveDeTranche
{
    public const FAMILLES = ['prime', 'commission', 'retrocommission', 'taxe'];

    public function __construct(
        private readonly TranchePaiementService $paiements,
        private readonly PiecesDeReglement $pieces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{cle: string, titre: string, chiffres: list<array{libelle: string, valeur: float|string|null, ton: ?string, nature: string}>, lignes: list<MouvementDeReglement>, total: float, totalLibelle: string}>
     */
    public function pour(Tranche $tranche, string $famille): array
    {
        // Hydrater d'abord : sans la passe de calcul, les indicateurs valent null.
        $this->paiements->chargerIndicateurs([$tranche]);
        $eco = EconomieTranche::depuis($tranche);

        $blocs = match ($famille) {
            'prime' => [$this->bloc(
                'prime', 'Prime de la tranche',
                [
                    $this->montant('Prime due', $tranche->primeTranche),
                    $this->montant('Payée', $tranche->primePayee),
                    $this->solde('Solde', $tranche->primeSoldeDue),
                    ['libelle' => 'Échéance', 'valeur' => $tranche->getEcheanceAt()?->format('d/m/Y'), 'ton' => null, 'nature' => 'texte'],
                ],
                $this->pieces->lignesPrime($tranche),
                (float) $tranche->primePayee,
                'Total payé',
                (float) $tranche->primeTranche,
            )],
            'commission' => [$this->bloc(
                'commission', 'Commission de courtage (TTC)',
                [
                    $this->montant('Due', $eco['commissionTtc'] ?? 0.0),
                    $this->montant('Exigible', $eco['commissionExigible'] ?? 0.0),
                    $this->montant('Encaissée', $tranche->montant_paye),
                    $this->solde('Solde', $tranche->solde_restant_du),
                ],
                $this->pieces->lignesCommission($tranche),
                (float) $tranche->montant_paye,
                'Total encaissé',
                (float) ($eco['commissionTtc'] ?? 0.0),
            )],
            'retrocommission' => [
                $this->bloc(
                    'retro-partenaire', 'Rétrocommission du partenaire',
                    [
                        $this->montant('Due', $eco['retroCommission'] ?? 0.0),
                        $this->montant('Exigible', $eco['retroAPayer'] ?? 0.0),
                        $this->montant('Versée', $tranche->retroCommissionReversee),
                        $this->solde('Solde', $tranche->retroCommissionSolde),
                    ],
                    $this->pieces->lignesRetro($tranche, false),
                    (float) $tranche->retroCommissionReversee,
                    'Total versé',
                    (float) ($eco['retroCommission'] ?? 0.0),
                ),
                $this->bloc(
                    'retro-agent', 'Rétrocommission de l\'agent',
                    [
                        $this->montant('Due', $eco['retroAgentDue'] ?? 0.0),
                        $this->montant('Exigible', $eco['retroAgentExigible'] ?? 0.0),
                        $this->montant('Versée', $tranche->retroAgentReversee),
                        $this->solde('Solde', $tranche->retroAgentSolde),
                    ],
                    $this->pieces->lignesRetro($tranche, true),
                    (float) $tranche->retroAgentReversee,
                    'Total versé',
                    (float) ($eco['retroAgentDue'] ?? 0.0),
                ),
            ],
            'taxe' => [
                $this->blocTaxe($tranche, $eco, true),
                $this->blocTaxe($tranche, $eco, false),
            ],
            default => throw new \InvalidArgumentException("Famille de relevé inconnue : $famille"),
        };

        // UN BLOC SANS DÛ ET SANS LIGNE N'A RIEN À DIRE : une affaire sans partenaire n'a
        // pas de rétrocommission à afficher à zéro. Mais un versement fait SANS dû reste
        // montré — le cacher rendrait invisible l'anomalie qu'il signale.
        return array_values(array_filter(
            $blocs,
            static fn (array $bloc): bool => $bloc['du'] > 0.0 || $bloc['lignes'] !== [] || $bloc['cle'] === 'prime',
        ));
    }

    /** @param array<string, mixed> $eco */
    private function blocTaxe(Tranche $tranche, array $eco, bool $assureur): array
    {
        $cle = $assureur ? 'taxeAssureur' : 'taxeCourtier';
        $taux = $eco['tauxTaxe' . ($assureur ? 'Assureur' : 'Courtier')] ?? null;
        $titre = ($assureur ? 'Taxe due par l\'assureur' : 'Taxe due par le courtier')
            . ($taux !== null ? sprintf(' (%s %%)', rtrim(rtrim(number_format((float) $taux, 2, ',', ''), '0'), ',')) : '');

        return $this->bloc(
            $assureur ? 'taxe-assureur' : 'taxe-courtier',
            $titre,
            [
                $this->montant('Due', $eco[$cle] ?? 0.0),
                $this->montant('Exigible', $eco[$cle . 'Exigible'] ?? 0.0),
                $this->montant('Payée', $assureur ? $tranche->taxeAssureurPayee : $tranche->taxeCourtierPayee),
                $this->solde('Solde', $assureur ? $tranche->taxeAssureurSolde : $tranche->taxeCourtierSolde),
            ],
            $this->pieces->lignesTaxe($tranche, $assureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER),
            (float) ($assureur ? $tranche->taxeAssureurPayee : $tranche->taxeCourtierPayee),
            'Total payé',
            (float) ($eco[$cle] ?? 0.0),
        );
    }

    /** @param list<MouvementDeReglement> $lignes */
    private function bloc(string $cle, string $titre, array $chiffres, array $lignes, float $cible, string $totalLibelle, float $du): array
    {
        $lignes = $this->arrondir($lignes, $cible, $cle);

        return [
            'cle'          => $cle,
            'titre'        => $titre,
            'chiffres'     => $chiffres,
            'lignes'       => $lignes,
            'total'        => round(array_sum(array_map(static fn (MouvementDeReglement $l) => $l->montant, $lignes)), 2),
            'totalLibelle' => $totalLibelle,
            'du'           => round($du, 2),
        ];
    }

    /**
     * Arrondit chaque part au centime ; la dernière ligne reprend l'écart d'ARRONDI.
     *
     * @param list<MouvementDeReglement> $lignes
     *
     * @return list<MouvementDeReglement>
     */
    private function arrondir(array $lignes, float $cible, string $cle): array
    {
        if ($lignes === []) {
            return [];
        }

        $arrondies = array_map(static fn (MouvementDeReglement $l) => $l->avecMontant(round($l->montant, 2)), $lignes);
        $somme = array_sum(array_map(static fn (MouvementDeReglement $l) => $l->montant, $arrondies));
        $ecart = round(round($cible, 2) - $somme, 2);
        if ($ecart === 0.0 || $ecart === -0.0) {
            return $arrondies;
        }

        // Un arrondi ne peut décaler la somme que d'un demi-centime par ligne.
        if (abs($ecart) <= 0.005 * count($arrondies) + 1e-9) {
            $dernier = array_key_last($arrondies);
            $arrondies[$dernier] = $arrondies[$dernier]->avecMontant(round($arrondies[$dernier]->montant + $ecart, 2));

            return $arrondies;
        }

        $this->logger->warning('[ReleveDeTranche] Les lignes du relevé ne totalisent pas l\'indicateur.', [
            'bloc' => $cle, 'cible' => round($cible, 2), 'somme' => round($somme, 2),
        ]);

        return $arrondies;
    }

    private function montant(string $libelle, ?float $valeur): array
    {
        return ['libelle' => $libelle, 'valeur' => round((float) $valeur, 2), 'ton' => null, 'nature' => 'montant'];
    }

    /**
     * LE SOLDE BRUT, SIGNE COMPRIS. Positif : il reste dû (teinte « dû ») ; nul : réglé
     * (teinte « soldé ») ; NÉGATIF : il a été versé plus que dû — un trop-perçu, que le
     * signe moins dit seul, sans teinte. L'écrêter à zéro, comme le fait la projection
     * de l'assistant, afficherait « réglé » sur un versement fait sans aucun dû : c'est
     * précisément l'anomalie que le relevé doit laisser voir.
     */
    private function solde(string $libelle, ?float $valeur): array
    {
        $valeur = round((float) $valeur, 2);
        $ton = match (true) {
            $valeur > 0.0 => 'du',
            $valeur < 0.0 => null,
            default => 'solde',
        };

        return ['libelle' => $libelle, 'valeur' => $valeur + 0.0, 'ton' => $ton, 'nature' => 'montant'];
    }
}
