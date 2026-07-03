<?php

namespace App\Comptabilite;

use App\Entity\Depense;
use App\Entity\ReglementTaxe;
use App\Entity\TokenPurchase;
use App\Repository\DepenseRepository;
use App\Repository\PlateformeParametresRepository;
use App\Repository\ReglementTaxeRepository;
use App\Repository\TokenPurchaseRepository;
use App\Services\ServiceTaxesVente;
use App\Token\ParametresTokenService;

/**
 * @file Génération « à la volée » des écritures comptables de JS Brokers.
 * @description JS Brokers ne tient pas de comptabilité en partie double stockée :
 * ce service la DÉRIVE des données transactionnelles (ventes de tokens, dépenses,
 * capital social) via un plan comptable SYSCOHADA déterministe (cf. PlanComptable).
 * Source unique de tous les documents financiers (journal, grand livre, balance,
 * compte de résultat, TFR, bilan, TFT). Lecture seule, aucune persistance.
 *
 * Cohérence garantie avec les KPI du tableau de bord : produit = revenu HT
 * (ServiceTaxesVente), charges = HT des dépenses non annulées, trésorerie =
 * encaissements − décaissements payés. Chaque écriture est équilibrée
 * (Σ débit = Σ crédit) → le bilan s'équilibre toujours.
 *
 * La construction des sept documents à partir des écritures est déléguée au
 * moteur générique DocumentsComptablesBuilder (partagé avec la comptabilité
 * du courtier) : ce service ne conserve que la COLLECTE propre à la plateforme.
 */
class EcritureComptableService
{
    /** Cache des écritures générées (toutes périodes), construit une seule fois. */
    private ?array $ecritures = null;

    public function __construct(
        private TokenPurchaseRepository $purchaseRepository,
        private DepenseRepository $depenseRepository,
        private PlateformeParametresRepository $parametresRepository,
        private ServiceTaxesVente $taxesVente,
        private ParametresTokenService $parametresToken,
        private ReglementTaxeRepository $reglementRepository,
        private DocumentsComptablesBuilder $builder,
    ) {
    }

    /**
     * Toutes les écritures, tous exercices confondus, triées par date croissante.
     * Chaque écriture : { date, piece, libelle, type, lignes:[{compte, libelle,
     * debit, credit}] }.
     *
     * @return array<int, array{date:\DateTimeImmutable, piece:string, libelle:string, type:string, lignes:array<int, array{compte:string, libelle:string, debit:float, credit:float}>}>
     */
    public function ecritures(): array
    {
        if ($this->ecritures !== null) {
            return $this->ecritures;
        }

        $ecritures = [];

        // 1) Capital social (apport d'ouverture) — écriture fondatrice éventuelle.
        $ecritureCapital = $this->ecritureCapital();
        if ($ecritureCapital !== null) {
            $ecritures[] = $ecritureCapital;
        }

        // 2) Ventes (revenus) : encaissement TTC, produit HT, TVA collectée.
        foreach ($this->purchaseRepository->findChronologique() as $vente) {
            $ecritures[] = $this->ecritureVente($vente);
        }

        // 3) Dépenses (charges) non annulées : charge HT, TVA déductible, sortie TTC.
        foreach ($this->depenseRepository->findChronologique() as $depense) {
            $ecritures[] = $this->ecritureDepense($depense);
        }

        // 4) Reversements de TVA à l'autorité : extinction de la dette / sortie trésorerie.
        foreach ($this->reglementRepository->findChronologique() as $reglement) {
            $ecritures[] = $this->ecritureReglementTaxe($reglement);
        }

        // Tri chronologique stable (les écritures sans pièce gardent leur ordre relatif).
        usort($ecritures, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $this->ecritures = $ecritures;
    }

    /**
     * Les sept documents comptables d'un exercice (année civile), prêts à
     * l'affichage et à l'export. Structure : voir DocumentsComptablesBuilder.
     *
     * @return array{exercice:int, ouverture:\DateTimeImmutable, cloture:\DateTimeImmutable, journal:array, grandLivre:array, balance:array, resultat:array, tfr:array, bilan:array, tft:array}
     */
    public function documents(int $exercice): array
    {
        return $this->builder->documents($this->ecritures(), $exercice);
    }

    /**
     * Années civiles présentes dans les données (pour le sélecteur d'exercice),
     * de la plus récente à la plus ancienne. À défaut : l'année courante.
     *
     * @return int[]
     */
    public function exercicesDisponibles(): array
    {
        return $this->builder->exercicesDisponibles($this->ecritures());
    }

    // ===================== Génération des écritures =====================

    /** Écriture de vente : D 521 Banques (TTC) / C 706 Services vendus (HT) / C 443 TVA (collectée). */
    private function ecritureVente(TokenPurchase $vente): array
    {
        $ttc = round((float) $vente->getMontantUsd(), 2);
        $ht  = round($this->taxesVente->revenuHorsTaxe($ttc), 2);
        $tva = round($ttc - $ht, 2);

        $lignes = [
            $this->ligne(PlanComptable::BANQUES, $ttc, 0.0),
            $this->ligne(PlanComptable::SERVICES_VENDUS, 0.0, $ht),
        ];
        if (abs($tva) >= 0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_FACTUREE, 0.0, $tva);
        }

        return [
            'date'    => $vente->getCreatedAt(),
            'piece'   => $vente->getReference() ?? ('V-' . $vente->getId()),
            'libelle' => sprintf('Vente paquet %s', $this->libellePaquet((string) $vente->getPack())),
            'type'    => 'vente',
            'lignes'  => $lignes,
        ];
    }

    /**
     * Libellé public d'un paquet à partir de sa clé technique : MÊME source que la
     * vitrine / section Tarif (ParametresTokenService → label configuré, repli
     * TokenPricing::PACKS), avec repli sur la clé capitalisée si absente. Identique
     * au filtre Twig `token_pack_label` (DRY).
     */
    private function libellePaquet(string $key): string
    {
        return $this->parametresToken->pack($key)['label'] ?? ucfirst(mb_strtolower($key));
    }

    /**
     * Écriture de dépense : D compte de charge (HT) [+ D 445 TVA récupérable] /
     * C trésorerie (si payée) ou C 401 Fournisseurs (si engagée), pour le TTC.
     */
    private function ecritureDepense(Depense $depense): array
    {
        $ttc = round($depense->getMontantFloat(), 2);
        $ht  = round($depense->getMontantHtFloat(), 2);
        $tva = round($ttc - $ht, 2);

        $compteCharge = $depense->getCharge()?->getCompteOhada() ?? '60';

        $lignes = [$this->ligne($compteCharge, $ht, 0.0)];
        if (abs($tva) >= 0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_RECUPERABLE, $tva, 0.0);
        }

        // Contrepartie au crédit : trésorerie si payée, dette fournisseur si engagée.
        $compteContrepartie = $depense->getStatut() === Depense::STATUT_PAYEE
            ? ($depense->getMoyenPaiement() === Depense::MOYEN_CAISSE ? PlanComptable::CAISSE : PlanComptable::BANQUES)
            : PlanComptable::FOURNISSEURS;
        $lignes[] = $this->ligne($compteContrepartie, 0.0, $ttc);

        $libelle = $depense->getCharge()?->getLibelle() ?? 'Dépense';
        if ($depense->getBeneficiaire() !== null && $depense->getBeneficiaire() !== '') {
            $libelle .= ' — ' . $depense->getBeneficiaire();
        }

        return [
            'date'    => $depense->getDateDepense(),
            'piece'   => $depense->getReference() ?? ('D-' . $depense->getId()),
            'libelle' => $libelle,
            'type'    => 'depense',
            'lignes'  => $lignes,
        ];
    }

    /**
     * Écriture de reversement / déclaration de TVA, DÉTAILLÉE :
     *   D 443 État, TVA facturée   (TVA collectée de la période déclarée)
     *   C 445 État, TVA récupérable (TVA déductible de la période)
     *   C 521/571 Trésorerie        (montant net effectivement payé)
     *   ± 4441 État, TVA due        (solde déclaré non encore payé, si paiement partiel)
     * Équilibrée par construction. Pour les reversements antérieurs à la saisie des
     * photos de TVA (collectée/déductible = 0), repli sur l'écriture simple
     * D 443 / C trésorerie.
     */
    private function ecritureReglementTaxe(ReglementTaxe $reglement): array
    {
        $montant     = round($reglement->getMontantFloat(), 2);
        $collectee   = round($reglement->getTvaCollecteeFloat(), 2);
        $deductible  = round($reglement->getTvaDeductibleFloat(), 2);
        $tresorerie  = $reglement->getMoyenPaiement() === ReglementTaxe::MOYEN_CAISSE
            ? PlanComptable::CAISSE
            : PlanComptable::BANQUES;

        $base = [
            'date'    => $reglement->getDatePaiement(),
            'piece'   => $reglement->getReference() ?? ('TVA-' . $reglement->getId()),
            'libelle' => sprintf('Reversement TVA %s — %s', $reglement->getPeriodeLabel(), $reglement->getAutorite()),
            'type'    => 'reglement_taxe',
        ];

        // Repli (données héritées sans photo de TVA) : extinction simple de la dette.
        if ($collectee < 0.005 && $deductible < 0.005) {
            return $base + ['lignes' => [
                $this->ligne(PlanComptable::TVA_FACTUREE, $montant, 0.0),
                $this->ligne($tresorerie, 0.0, $montant),
            ]];
        }

        $lignes = [
            $this->ligne(PlanComptable::TVA_FACTUREE, $collectee, 0.0),
            $this->ligne(PlanComptable::TVA_RECUPERABLE, 0.0, $deductible),
            $this->ligne($tresorerie, 0.0, $montant),
        ];

        // Solde déclaré non couvert par le paiement → dette (4441) ; trop-payé → créance.
        $residuel = round($collectee - $deductible - $montant, 2);
        if ($residuel >= 0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_DUE, 0.0, $residuel);
        } elseif ($residuel <= -0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_DUE, -$residuel, 0.0);
        }

        return $base + ['lignes' => $lignes];
    }

    /** Écriture du capital social (D 521 Banques / C 101 Capital social), ou null si non renseigné. */
    private function ecritureCapital(): ?array
    {
        $params = $this->parametresRepository->getSingleton();
        $capital = round($params->getCapitalSocialFloat(), 2);
        if ($capital < 0.005) {
            return null;
        }

        // Date de l'apport : date de constitution si renseignée, sinon la 1ʳᵉ opération,
        // sinon le 1ᵉʳ janvier de l'année courante (cas d'une plateforme sans flux).
        $date = $params->getDateConstitution() ?? $this->premiereOperation() ?? new \DateTimeImmutable('first day of January this year 00:00:00');

        return [
            'date'    => $date,
            'piece'   => 'CAPITAL',
            'libelle' => 'Apport en capital social',
            'type'    => 'capital',
            'lignes'  => [
                $this->ligne(PlanComptable::BANQUES, $capital, 0.0),
                $this->ligne(PlanComptable::CAPITAL_SOCIAL, 0.0, $capital),
            ],
        ];
    }

    /** Date de la première opération (vente ou dépense), pour dater le capital à défaut. */
    private function premiereOperation(): ?\DateTimeImmutable
    {
        $dates = [];
        foreach ($this->purchaseRepository->findChronologique() as $v) {
            if ($v->getCreatedAt() !== null) {
                $dates[] = $v->getCreatedAt();
            }
        }
        foreach ($this->depenseRepository->findChronologique() as $d) {
            if ($d->getDateDepense() !== null) {
                $dates[] = $d->getDateDepense();
            }
        }

        if ($dates === []) {
            return null;
        }

        return min($dates);
    }

    /** Construit une ligne d'écriture normalisée (compte + libellé résolu). */
    private function ligne(string $compte, float $debit, float $credit): array
    {
        return [
            'compte'  => $compte,
            'libelle' => PlanComptable::libelle($compte),
            'debit'   => $debit,
            'credit'  => $credit,
        ];
    }
}
