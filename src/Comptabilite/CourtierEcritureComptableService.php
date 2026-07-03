<?php

namespace App\Comptabilite;

use App\Entity\Entreprise;
use App\Entity\DepenseCourtier;
use App\Entity\Depense;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Taxe;
use App\Repository\DepenseCourtierRepository;
use App\Repository\PaiementRepository;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\ServiceMonnaies;
use App\Services\ServiceTaxes;

/**
 * @file Génération « à la volée » des écritures comptables du COURTIER (workspace).
 * @description Pendant workspace d'EcritureComptableService : dérive la comptabilité
 * en partie double du cabinet de courtage depuis ses données transactionnelles,
 * SCOPÉE à l'entreprise. Comptabilité de trésorerie : le fait générateur est le
 * PAIEMENT (Paiement.paidAt) — le chiffre d'affaires commissions est capturé quand
 * la note (facture) issue du bordereau de production, ou à articles, est payée.
 *
 * Règles métier (validées) :
 *  - le courtier ne collecte PAS les primes et n'indemnise PAS les sinistres :
 *    les paiements liés aux sinistres sont exclus (aucun impact trésorerie/résultat) ;
 *  - note de DÉBIT payée → encaissement : D 521/571, C 706 Commissions (part HT),
 *    C 443 Taxes facturées (part taxe assureur, collectée pour le compte de l'État) ;
 *  - note de CRÉDIT payée (montants HT) → décaissement selon le destinataire :
 *      · partenaire        → D 632 Rémunérations d'intermédiaires (rétro-commission),
 *      · autorité fiscale  → taxe redevable ASSUREUR : D 443 (extinction de dette,
 *        trésorerie seule) ; taxe redevable COURTIER : D 641 Impôts et taxes
 *        (CHARGE : impacte trésorerie ET résultat),
 *      · assureur/client   → D 706 (avoir : réduction de produit) ;
 *  - dépenses du courtier (DepenseCourtier, non annulées) → D charge classe 6 (HT)
 *    [+ D 445 TVA récupérable] / C trésorerie (payée) ou C 401 Fournisseurs (engagée) ;
 *  - capital social (Entreprise.capitalSociale) → écriture fondatrice D 521 / C 101.
 *
 * Les sept documents sont construits par le moteur générique partagé
 * DocumentsComptablesBuilder (DRY avec la console). Lecture seule, aucune persistance.
 */
class CourtierEcritureComptableService
{
    /** @var array<int, array> Cache des écritures par id d'entreprise (toutes périodes). */
    private array $ecritures = [];

    public function __construct(
        private PaiementRepository $paiementRepository,
        private DepenseCourtierRepository $depenseRepository,
        private IndicatorCalculationHelper $helper,
        private ServiceTaxes $serviceTaxes,
        private ServiceMonnaies $serviceMonnaies,
        private DocumentsComptablesBuilder $builder,
    ) {
    }

    /**
     * Toutes les écritures de l'entreprise, tous exercices confondus, triées par
     * date croissante. Même structure normalisée que la console : { date, piece,
     * libelle, type, lignes:[{compte, libelle, debit, credit}] }.
     *
     * @return array<int, array>
     */
    public function ecritures(Entreprise $entreprise): array
    {
        $id = (int) $entreprise->getId();
        if (isset($this->ecritures[$id])) {
            return $this->ecritures[$id];
        }

        $paiements = $this->paiementRepository->findChronologiqueForEntreprise($id);
        $depenses  = $this->depenseRepository->findChronologiqueForEntreprise($id);

        $ecritures = [];

        // 1) Capital social (apport d'ouverture) — écriture fondatrice éventuelle.
        $ecritureCapital = $this->ecritureCapital($entreprise, $paiements, $depenses);
        if ($ecritureCapital !== null) {
            $ecritures[] = $ecritureCapital;
        }

        // 2) Paiements de notes (encaissements de commissions et décaissements).
        foreach ($paiements as $paiement) {
            $ecriture = $this->ecriturePaiement($paiement);
            if ($ecriture !== null) {
                $ecritures[] = $ecriture;
            }
        }

        // 3) Dépenses du cabinet non annulées : charge HT, TVA déductible, sortie TTC.
        foreach ($depenses as $depense) {
            $ecritures[] = $this->ecritureDepense($depense);
        }

        // Tri chronologique stable.
        usort($ecritures, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $this->ecritures[$id] = $ecritures;
    }

    /**
     * Les sept documents comptables d'un exercice du courtier (année civile).
     * Structure : voir DocumentsComptablesBuilder.
     */
    public function documents(Entreprise $entreprise, int $exercice): array
    {
        return $this->builder->documents($this->ecritures($entreprise), $exercice, [
            'libelleCA' => 'Chiffre d\'affaires (commissions HT)',
        ]);
    }

    /**
     * Années civiles présentes dans les données de l'entreprise, de la plus récente
     * à la plus ancienne. À défaut : l'année courante.
     *
     * @return int[]
     */
    public function exercicesDisponibles(Entreprise $entreprise): array
    {
        return $this->builder->exercicesDisponibles($this->ecritures($entreprise));
    }

    // ===================== Génération des écritures =====================

    /**
     * Écriture dérivée d'un paiement de note. Null si le montant est négligeable.
     */
    private function ecriturePaiement(Paiement $paiement): ?array
    {
        $note = $paiement->getNote();
        $montant = round((float) $paiement->getMontant(), 2);
        if ($note === null || abs($montant) < 0.005) {
            return null;
        }

        $tresorerie = $this->compteTresorerie($paiement);
        $destinataire = $this->helper->getNoteAddressedToString($note) ?? '';

        $base = [
            'date'  => $paiement->getPaidAt(),
            'piece' => $paiement->getReference() ?: ('P-' . $paiement->getId()),
        ];

        // ---- Note de DÉBIT : encaissement de commissions (± taxe assureur). ----
        if ($note->getType() === Note::TYPE_NOTE_DE_DEBIT) {
            [$htPart, $taxePart] = $this->ventilerEncaissement($note, $montant);

            $lignes = [$this->ligne($tresorerie, $montant, 0.0)];
            if (abs($htPart) >= 0.005) {
                $lignes[] = $this->ligne(PlanComptable::SERVICES_VENDUS, 0.0, $htPart);
            }
            if (abs($taxePart) >= 0.005) {
                $lignes[] = $this->ligne(PlanComptable::TVA_FACTUREE, 0.0, $taxePart);
            }

            return $base + [
                'libelle' => trim(sprintf('Encaissement %s — %s', $note->getNom() ?? ('note #' . $note->getId()), $destinataire), ' —'),
                'type'    => 'encaissement',
                'lignes'  => $lignes,
                // Métadonnée (ignorée par le moteur de documents) : taxe dont le
                // COURTIER est redevable, due sur la part HT encaissée — alimente le
                // « Dû » du suivi fiscal (CourtierSuiviFiscalService). Non facturée
                // dans la note, elle n'entre en comptabilité qu'au reversement (641).
                'taxeCourtierDue' => round($this->serviceTaxes->getMontantTaxe($htPart, $this->isIARD($note), false), 2),
            ];
        }

        // ---- Note de CRÉDIT : décaissement (montants HT, cf. NoteIndicatorStrategy). ----
        [$compteDebit, $type, $prefixe] = match ($note->getAddressedTo()) {
            Note::TO_PARTENAIRE => [PlanComptable::RETRO_COMMISSIONS, 'retro_commission', 'Rétro-commission'],
            Note::TO_AUTORITE_FISCALE => $this->destinationReversementTaxe($note),
            default => [PlanComptable::SERVICES_VENDUS, 'avoir', 'Avoir'],
        };

        return $base + [
            'libelle' => trim(sprintf('%s %s — %s', $prefixe, $note->getNom() ?? ('note #' . $note->getId()), $destinataire), ' —'),
            'type'    => $type,
            'lignes'  => [
                $this->ligne($compteDebit, $montant, 0.0),
                $this->ligne($tresorerie, 0.0, $montant),
            ],
        ];
    }

    /**
     * Compte de débit d'un reversement à l'autorité fiscale, selon le REDEVABLE de
     * la taxe liée à l'autorité (règle métier) :
     *  - redevable COURTIER → 641 Impôts et taxes : CHARGE (trésorerie + résultat) ;
     *  - redevable ASSUREUR (ou non renseigné, repli conservateur) → 443 : extinction
     *    de la dette fiscale collectée (trésorerie seule, résultat inchangé).
     *
     * @return array{0:string, 1:string, 2:string} [compte, type, préfixe de libellé]
     */
    private function destinationReversementTaxe(Note $note): array
    {
        $redevable = $note->getAutoritefiscale()?->getTaxe()?->getRedevable();

        if ($redevable === Taxe::REDEVABLE_COURTIER) {
            return [PlanComptable::IMPOTS_TAXES, 'reversement_taxe_courtier', 'Reversement taxe (courtier)'];
        }

        return [PlanComptable::TVA_FACTUREE, 'reversement_taxe', 'Reversement taxe (collectée)'];
    }

    /**
     * Ventile le montant payé d'une note de DÉBIT entre part HT (commissions) et
     * part taxe assureur, au PRORATA de la composition de la note :
     *  - note de bordereau (sans articles) : montants HT/taxe persistés du bordereau ;
     *  - note à articles : montants calculés (IndicatorCalculationHelper).
     * Le complément d'arrondi est porté par la part taxe → l'écriture reste
     * équilibrée par construction, même sur paiement partiel.
     *
     * @return array{0:float, 1:float} [part HT, part taxe]
     */
    private function ventilerEncaissement(Note $note, float $montantPaye): array
    {
        if ($note->getArticles()->isEmpty() && $note->getBordereau() !== null) {
            $ht    = (float) ($note->getBordereau()->getMontantComHtPayableNow() ?? 0.0);
            $taxe  = (float) ($note->getBordereau()->getMontantTaxePayableNow() ?? 0.0);
            $total = $ht + $taxe;
        } else {
            $total = $this->helper->getNoteMontantPayable($note);
            $ht    = $this->helper->getNoteMontantHT($note);
        }

        // Note sans montant théorique : tout en produit (aucune base de proratisation).
        if ($total < 0.005) {
            return [round($montantPaye, 2), 0.0];
        }

        $htPart   = round($montantPaye * $ht / $total, 2);
        $taxePart = round($montantPaye - $htPart, 2);

        return [$htPart, $taxePart];
    }

    /**
     * Écriture de dépense du cabinet : D compte de charge (HT) [+ D 445 TVA
     * récupérable] / C trésorerie (si payée, caisse selon le moyen) ou C 401
     * Fournisseurs (si engagée), pour le TTC. Mêmes règles que la console.
     */
    private function ecritureDepense(DepenseCourtier $depense): array
    {
        $ttc = round($depense->getMontantFloat(), 2);
        $ht  = round($depense->getMontantHtFloat(), 2);
        $tva = round($ttc - $ht, 2);

        $compteCharge = $depense->getCharge()?->getCompteOhada() ?? '60';

        $lignes = [$this->ligne($compteCharge, $ht, 0.0)];
        if (abs($tva) >= 0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_RECUPERABLE, $tva, 0.0);
        }

        $compteContrepartie = $depense->getStatut() === Depense::STATUT_PAYEE
            ? ($depense->getMoyenPaiement() === Depense::MOYEN_CAISSE ? PlanComptable::CAISSE : PlanComptable::BANQUES)
            : PlanComptable::FOURNISSEURS;
        $lignes[] = $this->ligne($compteContrepartie, 0.0, $ttc);

        $libelle = $depense->getCharge()?->getLibelle() ?? 'Dépense';
        $tiers = $depense->getTiersLibelle(); // fournisseur enregistré prioritaire, sinon bénéficiaire libre
        if ($tiers !== null && $tiers !== '') {
            $libelle .= ' — ' . $tiers;
        }

        return [
            'date'    => $depense->getDateDepense(),
            'piece'   => $depense->getReference() ?: ('D-' . $depense->getId()),
            'libelle' => $libelle,
            'type'    => 'depense',
            'lignes'  => $lignes,
        ];
    }

    /**
     * Écriture du capital social (D 521 Banques / C 101 Capital social), depuis
     * Entreprise.capitalSociale, ou null si non renseigné. Date : première
     * opération de l'entreprise, sinon 1ᵉʳ janvier de l'année courante (l'entité
     * Entreprise ne porte pas de date de constitution).
     *
     * MONNAIE : le capital social des paramètres de l'entreprise est saisi en
     * monnaie LOCALE (ex. CDF) alors que les documents comptables sont exprimés
     * en monnaie d'AFFICHAGE (ex. USD) — on le convertit via ServiceMonnaies
     * (pivot USD, taux des paramètres Monnaies du workspace). Sans configuration
     * de monnaies exploitable, le montant est repris tel quel.
     *
     * @param Paiement[]        $paiements
     * @param DepenseCourtier[] $depenses
     */
    private function ecritureCapital(Entreprise $entreprise, array $paiements, array $depenses): ?array
    {
        $capitalLocal = round((float) $entreprise->getCapitalSociale(), 2);
        if ($capitalLocal < 0.005) {
            return null;
        }

        $capital = round($this->serviceMonnaies->convertirLocaleVersAffichage($capitalLocal, $entreprise), 2);
        if ($capital < 0.005) {
            return null;
        }

        // Libellé explicite quand une conversion a réellement eu lieu (traçabilité).
        $libelle = 'Apport en capital social';
        $locale = $this->serviceMonnaies->getMonnaieLocalePourEntreprise($entreprise);
        $affichage = $this->serviceMonnaies->getMonnaieAffichagePourEntreprise($entreprise);
        if ($locale !== null && $affichage !== null && $locale->getCode() !== $affichage->getCode()) {
            $libelle .= sprintf(
                ' (%s %s convertis en %s)',
                number_format($capitalLocal, 2, ',', ' '),
                $locale->getCode(),
                $affichage->getCode(),
            );
        }

        $date = $this->premiereOperation($paiements, $depenses)
            ?? new \DateTimeImmutable('first day of January this year 00:00:00');

        return [
            'date'    => $date,
            'piece'   => 'CAPITAL',
            'libelle' => $libelle,
            'type'    => 'capital',
            'lignes'  => [
                $this->ligne(PlanComptable::BANQUES, $capital, 0.0),
                $this->ligne(PlanComptable::CAPITAL_SOCIAL, 0.0, $capital),
            ],
        ];
    }

    /**
     * Date de la première opération (paiement ou dépense), pour dater le capital à défaut.
     *
     * @param Paiement[]        $paiements
     * @param DepenseCourtier[] $depenses
     */
    private function premiereOperation(array $paiements, array $depenses): ?\DateTimeImmutable
    {
        $dates = [];
        foreach ($paiements as $p) {
            if ($p->getPaidAt() !== null) {
                $dates[] = $p->getPaidAt();
            }
        }
        foreach ($depenses as $d) {
            if ($d->getDateDepense() !== null) {
                $dates[] = $d->getDateDepense();
            }
        }

        return $dates === [] ? null : min($dates);
    }

    /**
     * Branche IARD / VIE d'une note, pour le taux de taxe courtier applicable :
     * détectée via la cotation du premier article facturant un revenu ; les notes
     * de bordereau (sans articles) sont réputées IARD (branche non-vie, cas général).
     */
    private function isIARD(Note $note): bool
    {
        foreach ($note->getArticles() as $article) {
            $cotation = $article->getRevenuFacture()?->getCotation();
            if ($cotation !== null) {
                return $this->helper->isIARD($cotation);
            }
        }

        return true;
    }

    /** Compte de trésorerie d'un paiement : banque si un compte est renseigné, sinon caisse. */
    private function compteTresorerie(Paiement $paiement): string
    {
        return $paiement->getCompteBancaire() !== null ? PlanComptable::BANQUES : PlanComptable::CAISSE;
    }

    /**
     * Ligne d'écriture normalisée, avec la terminologie du courtier (le moteur
     * générique propage ces libellés au grand livre, à la balance et au résultat).
     */
    private function ligne(string $compte, float $debit, float $credit): array
    {
        $libelle = match ($compte) {
            PlanComptable::SERVICES_VENDUS => 'Commissions de courtage',
            PlanComptable::TVA_FACTUREE    => 'État, taxes facturées (collectées)',
            default                        => PlanComptable::libelle($compte),
        };

        return [
            'compte'  => $compte,
            'libelle' => $libelle,
            'debit'   => $debit,
            'credit'  => $credit,
        ];
    }
}
