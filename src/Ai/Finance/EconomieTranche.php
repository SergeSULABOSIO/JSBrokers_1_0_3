<?php

namespace App\Ai\Finance;

use App\Ai\Presentation\Colonnes;
use App\Entity\Tranche;
use App\Service\Partage\Exigibilite;
use App\Service\Partage\Reserve;

/**
 * L'ÉCONOMIE D'UNE TRANCHE, NOMMÉE UNE SEULE FOIS — prime, commission de courtage,
 * taxes SUR LA COMMISSION, rétrocommission — pour tout outil de l'assistant qui tient
 * une tranche en main et doit en restituer autre chose que son seul montant.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────────────
 * L'INCIDENT DU 2026-08-11. Un courtier demande la liste des paiements de prime
 * signalés : Ket la produit. Il enchaîne — « ajoute une colonne pour afficher les
 * commissions exigibles relatives à toutes ces tranches ». Ket répond, à juste titre,
 * que « les commissions exigibles ne figurent pas directement dans les résultats des
 * paiements de prime déclaratifs », et s'arrête là. C'était exact et c'était inutile :
 * chaque ligne portait déjà son `trancheId`, et la commission, ses taxes et son
 * exigibilité sont TOUTES calculables à partir de cette tranche — par un moteur qui
 * tournait déjà dans le même processus.
 *
 * C'est le corollaire de la règle du 2026-08-10 (« une colonne PRÉSENTABLE est une
 * colonne RENVOYÉE ») : quand l'outil DÉTIENT la clé d'une information, la taire revient
 * au même que ne pas l'avoir. Le modèle n'a toujours que deux issues — la taire, ou
 * l'inventer.
 *
 * ── CE QUE CETTE CLASSE NE FAIT PAS ─────────────────────────────────────────────────
 * ELLE NE CALCULE RIEN. Pas une multiplication, pas un taux déduit d'un montant. Elle
 * LIT les indicateurs déjà posés sur la tranche par TrancheIndicatorStrategy — la source
 * unique du projet, celle qui alimente la rubrique Tranches, ses chips de statut et
 * suivi_impayes — et se contente de les nommer dans le vocabulaire du glossaire de Ket.
 * Un chiffre annoncé par l'assistant est ainsi, au centime près, celui de l'écran.
 *
 * Corollaire à ne pas enfreindre : hydrater d'abord (TranchePaiementService::
 * chargerIndicateurs), projeter ensuite. Sur une tranche non hydratée, `depuis()` rend
 * un tableau VIDE plutôt que des zéros — un zéro se présente, une absence non.
 *
 * ── UN PIÈGE, ET SON GARDE-FOU ──────────────────────────────────────────────────────
 * Ces montants sont ceux de la TRANCHE, jamais du signalement de paiement qui y mène.
 * Deux règlements partiels sur la même tranche portent donc la MÊME commission : la
 * colonne se lit ligne à ligne, elle ne s'additionne pas. D'où `cumul()`, qui somme sur
 * les tranches DISTINCTES, et l'exclusion de ces clés de « presentation.totaliser ».
 */
final class EconomieTranche
{
    /**
     * Rôle de présentation de chaque clé produite par `depuis()` — pour qu'un outil qui
     * promeut l'une d'elles en colonne (à la demande de l'utilisateur) hérite du bon
     * alignement et du bon format sans le redevinier. Cf. Colonnes.
     */
    public const ROLES = [
        'statutTranche'      => Colonnes::STATUT,
        'primeTranche'       => Colonnes::MONTANT,
        'primeSignalee'      => Colonnes::MONTANT,
        'primeSolde'         => Colonnes::MONTANT,
        'commissionHt'       => Colonnes::MONTANT,
        'tauxTaxeAssureur'   => Colonnes::POURCENTAGE,
        'taxeAssureur'       => Colonnes::MONTANT,
        'commissionTtc'      => Colonnes::MONTANT,
        'tauxTaxeCourtier'   => Colonnes::POURCENTAGE,
        'taxeCourtier'       => Colonnes::MONTANT,
        'commissionEncaissee' => Colonnes::MONTANT,
        'commissionSolde'    => Colonnes::MONTANT,
        'commissionExigible' => Colonnes::MONTANT,
        'retroCommission'    => Colonnes::MONTANT,
        'retroAPayer'        => Colonnes::MONTANT,
        'retroReversee'      => Colonnes::MONTANT,
        'retroSolde'         => Colonnes::MONTANT,
        'retroAgentDue'      => Colonnes::MONTANT,
        'retroAgentReversee' => Colonnes::MONTANT,
        'retroAgentSolde'    => Colonnes::MONTANT,
        'retroAgentExigible' => Colonnes::MONTANT,
        'taxeCourtierPayee'  => Colonnes::MONTANT,
        'taxeCourtierSolde'  => Colonnes::MONTANT,
        'taxeCourtierExigible' => Colonnes::MONTANT,
        'taxeAssureurPayee'  => Colonnes::MONTANT,
        'taxeAssureurSolde'  => Colonnes::MONTANT,
        'taxeAssureurExigible' => Colonnes::MONTANT,
        'commissionPure'     => Colonnes::MONTANT,
        'reserve'            => Colonnes::MONTANT,
    ];

    /**
     * La règle du métier que ces chiffres obéissent, à joindre au résultat de tout outil
     * qui les restitue. Trois pièges y sont désamorcés d'avance, chacun constaté :
     * l'assiette des taxes, la définition du TTC, et le prorata qu'on est tenté de faire.
     */
    public const NOTE = 'ÉCONOMIE DE LA TRANCHE (pas du règlement) : ces montants décrivent la '
        . 'tranche entière ; deux règlements partiels sur la même tranche portent donc la MÊME '
        . 'commission — n\'additionne JAMAIS ces colonnes ligne à ligne, sers-toi des cumuls '
        . 'fournis, calculés sur les tranches distinctes. '
        . 'TAXES SUR LA COMMISSION (jamais sur la prime) : assiette = commissionHt ; '
        . 'taxeAssureur = TVA due par l\'ASSUREUR, collectée puis reversée par le courtier ; '
        . 'taxeCourtier = taxe due par le COURTIER sur sa commission (ARCA). '
        . 'commissionTtc = commissionHt + taxeAssureur SEULE (la taxe courtier n\'y est PAS). '
        . 'Les taux sont LUS sur le paramétrage de l\'entreprise : ne les déduis jamais d\'une division. '
        . 'commissionExigible = solde de commission réclamable à l\'assureur, et il vaut 0 tant que la '
        . 'prime n\'est pas intégralement payée : ne PRORATISE jamais une commission sur un règlement '
        . 'partiel de prime, cette règle n\'existe pas.';

    /**
     * Projection APLATIE, à fusionner dans la ligne d'une liste : une valeur structurée
     * n'a pas de rendu de cellule honnête, et le tableau de repli comme le contrat de
     * présentation écartent les sous-tableaux.
     *
     * Les clés dont la valeur est nulle sont ABSENTES (rétrocommission d'une affaire sans
     * partenaire, taxe non paramétrée) ; un zéro RÉEL, lui, est conservé — « commission
     * exigible : 0 » est une réponse, pas un vide.
     *
     * @return array<string, float|string> vide si les indicateurs n'ont pas été hydratés
     */
    public static function depuis(?Tranche $tranche): array
    {
        if (!self::estHydratee($tranche)) {
            return [];
        }

        return array_filter([
            'statutTranche'       => $tranche->statutPaiement,
            'primeTranche'        => self::montant($tranche->primeTranche),
            'primeSignalee'       => self::montant($tranche->primeDeclareePayee),
            'primeSolde'          => self::solde($tranche->primeSoldeDue),
            'commissionHt'        => self::montant($tranche->montantCalculeHT),
            'tauxTaxeAssureur'    => self::taux($tranche->taxeAssureurTaux),
            'taxeAssureur'        => self::montant($tranche->taxeAssureurMontant),
            'commissionTtc'       => self::montant($tranche->montantCalculeTTC),
            'tauxTaxeCourtier'    => self::taux($tranche->taxeCourtierTaux),
            'taxeCourtier'        => self::montant($tranche->taxeCourtierMontant),
            'commissionEncaissee' => self::montant($tranche->montant_paye),
            'commissionSolde'     => self::solde($tranche->solde_restant_du),
            'commissionExigible'  => self::montant($tranche->commissionExigible),
            // Flux INVERSE (le courtier est ici le débiteur) : omis quand il n'y a pas de
            // partenaire, pour ne pas suggérer une dette inexistante.
            'retroCommission'     => self::positifOuRien($tranche->retroCommission),
            'retroAPayer'         => self::positifOuRien($tranche->retroCommissionExigible),
            'retroReversee'       => self::positifOuRien($tranche->retroCommissionReversee),
            'retroSolde'          => self::positifOuRien($tranche->retroCommissionSolde),

            // ── LA RÉTROCOMMISSION DES AGENTS INTERNES ──────────────────────────────
            // ⚠ ELLE MANQUAIT ENTIÈREMENT, alors que ses indicateurs existent depuis
            // longtemps : l'assistant ne savait rien dire d'une famille de dettes que
            // l'écran affiche. Une capacité présente à l'écran et absente du chat est un
            // défaut — c'est la doctrine de parité de la rubrique d'échange.
            'retroAgentDue'       => self::positifOuRien($tranche->retroAgentDue),
            'retroAgentReversee'  => self::positifOuRien($tranche->retroAgentReversee),
            'retroAgentSolde'     => self::positifOuRien($tranche->retroAgentSolde),
            'retroAgentExigible'  => self::positifOuRien($tranche->retroAgentExigible),

            // ── LE RÈGLEMENT DES TAXES, ET CE QU'IL EN RESTE ────────────────────────
            'taxeCourtierPayee'   => self::montant($tranche->taxeCourtierPayee),
            'taxeCourtierSolde'   => self::solde($tranche->taxeCourtierSolde),
            'taxeCourtierExigible' => self::exigibiliteTaxe($tranche, $tranche->taxeCourtierMontant, $tranche->taxeCourtierPayee),
            'taxeAssureurPayee'   => self::montant($tranche->taxeAssureurPayee),
            'taxeAssureurSolde'   => self::solde($tranche->taxeAssureurSolde),
            'taxeAssureurExigible' => self::exigibiliteTaxe($tranche, $tranche->taxeAssureurMontant, $tranche->taxeAssureurPayee),

            // ── CE QUI RESTE AU CABINET ────────────────────────────────────────────
            'commissionPure'      => self::commissionPure($tranche),
            'reserve'             => self::reserve($tranche),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A');
    }

    /**
     * Même matière, mais STRUCTURÉE par notion — pour l'entête d'une consultation ciblée,
     * où une seule tranche est décrite et où rien ne sera rendu en tableau. Le
     * regroupement dit alors ce que l'aplatissement ne peut pas dire : que le taux et le
     * montant d'une taxe vont ensemble, et que la rétrocommission est un autre flux.
     *
     * @return array<string, mixed> vide si les indicateurs n'ont pas été hydratés
     */
    public static function bloc(?Tranche $tranche): array
    {
        if (!self::estHydratee($tranche)) {
            return [];
        }

        return array_filter([
            'commission' => array_filter([
                'ht'               => self::montant($tranche->montantCalculeHT),
                'taxeAssureur'     => self::montant($tranche->taxeAssureurMontant),
                'tauxTaxeAssureur' => self::taux($tranche->taxeAssureurTaux),
                'ttc'              => self::montant($tranche->montantCalculeTTC),
                'taxeCourtier'     => self::montant($tranche->taxeCourtierMontant),
                'tauxTaxeCourtier' => self::taux($tranche->taxeCourtierTaux),
                'encaissee'        => self::montant($tranche->montant_paye),
                'solde'            => self::solde($tranche->solde_restant_du),
                'exigible'         => self::montant($tranche->commissionExigible),
            ], static fn ($v) => $v !== null),
            'retrocommission' => array_filter([
                'due'      => self::positifOuRien($tranche->retroCommission),
                'reversee' => self::positifOuRien($tranche->retroCommissionReversee),
                'solde'    => self::positifOuRien($tranche->retroCommissionSolde),
                'aPayer'   => self::positifOuRien($tranche->retroCommissionExigible),
            ], static fn ($v) => $v !== null),
        ], static fn ($v) => $v !== []);
    }

    /**
     * Cumul sur des tranches DISTINCTES — le seul total honnête quand plusieurs lignes
     * d'une liste renvoient à la même tranche (deux règlements partiels d'une même
     * prime). Sommer la colonne compterait cette tranche deux fois ; c'est précisément le
     * piège que ce bloc existe pour retirer des mains du modèle.
     *
     * @param Tranche[] $tranches doublons admis : ils sont écartés par identifiant
     *
     * @return array<string, float|int> vide si rien n'est hydraté
     */
    public static function cumul(array $tranches): array
    {
        $distinctes = [];
        foreach ($tranches as $index => $tranche) {
            if (self::estHydratee($tranche)) {
                // Une tranche non persistée (aucun id) reste comptée une fois, sous sa
                // position : la dédoublonner par null les fondrait toutes en une seule.
                $distinctes[$tranche->getId() ?? 'n' . $index] = $tranche;
            }
        }
        if ($distinctes === []) {
            return [];
        }

        $cumul = [
            'nbTranches'          => count($distinctes),
            'primeTranche'        => 0.0,
            'commissionHt'        => 0.0,
            'taxeAssureur'        => 0.0,
            'commissionTtc'       => 0.0,
            'taxeCourtier'        => 0.0,
            'commissionEncaissee' => 0.0,
            'commissionSolde'     => 0.0,
            'commissionExigible'  => 0.0,
            'retroAPayer'         => 0.0,
        ];
        foreach ($distinctes as $tranche) {
            $cumul['primeTranche']        += (float) ($tranche->primeTranche ?? 0);
            $cumul['commissionHt']        += (float) ($tranche->montantCalculeHT ?? 0);
            $cumul['taxeAssureur']        += (float) ($tranche->taxeAssureurMontant ?? 0);
            $cumul['commissionTtc']       += (float) ($tranche->montantCalculeTTC ?? 0);
            $cumul['taxeCourtier']        += (float) ($tranche->taxeCourtierMontant ?? 0);
            $cumul['commissionEncaissee'] += (float) ($tranche->montant_paye ?? 0);
            $cumul['commissionSolde']     += max(0.0, (float) ($tranche->solde_restant_du ?? 0));
            $cumul['commissionExigible']  += (float) ($tranche->commissionExigible ?? 0);
            $cumul['retroAPayer']         += max(0.0, (float) ($tranche->retroCommissionExigible ?? 0));
        }

        return array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $cumul);
    }

    /**
     * Une tranche porte-t-elle ses indicateurs ? `statutPaiement` est le témoin : la
     * stratégie de calcul le pose TOUJOURS (fût-ce à « N/A » pour un projet), et rien
     * d'autre ne l'écrit. Tant qu'il est nul, le canvas n'est pas passé — et rendre des
     * zéros à sa place ferait annoncer « commission : 0 » sur une affaire qui en porte une.
     */
    private static function estHydratee(?Tranche $tranche): bool
    {
        return $tranche !== null && $tranche->statutPaiement !== null;
    }

    private static function montant(?float $valeur): ?float
    {
        return $valeur === null ? null : round($valeur, 2);
    }

    /** Un solde négatif est un trop-perçu : rien ne reste dû, on rend 0. */
    private static function solde(?float $valeur): ?float
    {
        return $valeur === null ? null : round(max(0.0, $valeur), 2);
    }

    /** Un taux en POINTS (16 = 16 %). Zéro = non paramétré : la clé disparaît. */
    private static function taux(?float $valeur): ?float
    {
        return ($valeur ?? 0.0) > 0 ? round($valeur, 2) : null;
    }

    /** Montant omis quand il est nul : une dette inexistante ne s'affiche pas à 0. */
    private static function positifOuRien(?float $valeur): ?float
    {
        return ($valeur ?? 0.0) > 0 ? round($valeur, 2) : null;
    }

    /**
     * COMMISSION PURE : ce qui reste au cabinet avant tout partage — commission HT moins
     * la taxe dont il est lui-même redevable. La définition est celle de `Reserve`, dont
     * l'en-tête fait foi ; on l'applique, on ne la réinvente pas.
     */
    private static function commissionPure(Tranche $tranche): ?float
    {
        if ($tranche->montantCalculeHT === null) {
            return null;
        }

        return round($tranche->montantCalculeHT - ($tranche->taxeCourtierMontant ?? 0.0), 2);
    }

    /**
     * RÉSERVE DU CABINET : la commission pure, moins ce qui part chez les partenaires
     * externes ET chez les agents internes.
     *
     * ⚠ Le négatif n'est PAS écrêté : une réserve sous zéro signale un cumul de taux mal
     * paramétré, et le masquer ferait disparaître le seul signe visible de l'anomalie.
     */
    private static function reserve(Tranche $tranche): ?float
    {
        $pure = self::commissionPure($tranche);
        if ($pure === null) {
            return null;
        }

        return Reserve::calculer(
            $pure,
            $tranche->retroCommission ?? 0.0,
            $tranche->retroAgentDue ?? 0.0,
        );
    }

    /**
     * LA PART DE TAXE DEVENUE RÉCLAMABLE, au rythme de la commission encaissée.
     *
     * ⚠ POURQUOI CE PRORATA EST LÉGITIME, LÀ OÙ CELUI DE LA COMMISSION EST INTERDIT.
     * La NOTE ci-dessus interdit de proratiser la COMMISSION sur un règlement partiel de
     * PRIME : la commission n'est réclamable à l'assureur qu'une fois la prime
     * intégralement payée — c'est une condition contractuelle, pas une proportion. La
     * taxe, elle, est DUE SUR UN REVENU PERÇU : elle naît avec l'encaissement et croît
     * avec lui. La proportionnalité n'y est pas un raccourci, elle est la règle.
     *
     * La formule n'est pas écrite ici : `Exigibilite::exigible()` la porte déjà pour les
     * rétrocommissions, avec ses trois garde-fous éprouvés — ratio plafonné à 1, jamais
     * de négatif, et ratio de 1 quand aucune commission n'est attendue (sans quoi la taxe
     * d'une affaire à honoraires purs resterait éternellement inexigible).
     */
    private static function exigibiliteTaxe(Tranche $tranche, ?float $montant, ?float $payee): ?float
    {
        if (($montant ?? 0.0) <= 0.0) {
            return null;
        }

        return Exigibilite::exigible(
            $montant,
            $tranche->montantCalculeTTC ?? 0.0,
            $tranche->montant_paye ?? 0.0,
            $payee ?? 0.0,
        );
    }
}
