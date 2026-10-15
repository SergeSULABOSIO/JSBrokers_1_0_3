<?php

namespace App\Service\Monnaie;

use App\Entity\Entreprise;
use App\Entity\Monnaie;
use Doctrine\DBAL\Connection;

/**
 * UN CABINET TRAVAILLE DANS UNE SEULE MONNAIE (décisions du 2026-10-14 et du 2026-10-15).
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────────────
 * Saisie = affichage = calcul : la « monnaie de travail » est la seule monnaie de fonction
 * SAISIE_ET_AFFICHAGE du cabinet. Toute autre monnaie est une RÉFÉRENCE (fonction AUCUNE) :
 * la monnaie du pays, par exemple, qui sert au capital social, aux mentions légales et au
 * taux de change.
 *
 * ── POURQUOI AUCUN MONTANT N'EST CONVERTI ───────────────────────────────────────────
 * Aucun montant ne porte sa monnaie : c'est un nombre. L'écran affichait la monnaie
 * d'affichage à côté de chaque champ pendant la saisie ; l'existant y est donc déjà, et
 * ne bouge pas. Corollaire : CHANGER la monnaie de travail d'un cabinet qui a déjà
 * enregistré des montants les ferait lire dans une autre monnaie — 1 000 USD deviendraient
 * 1 000 CDF sans un mot. C'est refusé ; un cabinet neuf, lui, en change librement.
 *
 * Ici, parce que l'écran et Ket y passent tous deux
 * ({@see \App\Service\Workspace\ChampsObligatoiresInspector::incoherencesMetier()}).
 */
final class MonnaieDeTravail
{
    /** Les anciennes fonctions qui séparaient saisie et affichage : refusées désormais. */
    private const FONCTIONS_SEPAREES = [Monnaie::FONCTION_SAISIE_UNIQUEMENT, Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT];

    /** Les fonctions qui font s'AFFICHER une monnaie (dont l'ancienne « affichage uniquement »). */
    private const FONCTIONS_AFFICHEES = [Monnaie::FONCTION_SAISIE_ET_AFFICHAGE, Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT];

    /** Toute fonction qui faisait d'une monnaie une monnaie de saisie ou d'affichage. */
    private const FONCTIONS_ACTIVES = [
        Monnaie::FONCTION_SAISIE_ET_AFFICHAGE,
        Monnaie::FONCTION_SAISIE_UNIQUEMENT,
        Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT,
    ];

    public function __construct(private readonly Connection $connexion)
    {
    }

    /**
     * Ce qui s'oppose à l'écriture de cette monnaie, ou null.
     *
     * @return array{champ: string, message: string}|null
     */
    public function refus(Monnaie $monnaie): ?array
    {
        $cabinet = $monnaie->getEntreprise()?->getId();
        if ($cabinet === null) {
            return null;
        }

        if (in_array($monnaie->getFonction(), self::FONCTIONS_SEPAREES, true)) {
            return ['champ' => 'fonction', 'message' => 'Une monnaie est soit la monnaie de travail du cabinet — '
                . 'celle de la saisie et de l’affichage —, soit une référence : la saisie et l’affichage ne se séparent plus.'];
        }

        $avant = $monnaie->getId() !== null
            ? $this->connexion->fetchAssociative('SELECT code, fonction FROM monnaie WHERE id = ?', [$monnaie->getId()])
            : false;
        $etaitDeTravail = $avant !== false && in_array((int) $avant['fonction'], self::FONCTIONS_AFFICHEES, true);
        $estDeTravail = $monnaie->getFonction() === Monnaie::FONCTION_SAISIE_ET_AFFICHAGE;

        // UNE PROMOTION seulement : une monnaie DÉJÀ de travail qu'on modifie (son nom, son
        // taux) ne se heurte pas à une seconde — née d'une course entre deux créations, que
        // rien en base n'interdit. Sans cette nuance, aucune des deux ne se corrigeait plus.
        if ($estDeTravail && !$etaitDeTravail) {
            $autre = $this->connexion->fetchOne(
                'SELECT code FROM monnaie WHERE entreprise_id = ? AND id <> ? AND fonction IN (?, ?, ?) ORDER BY id LIMIT 1',
                [$cabinet, $monnaie->getId() ?? 0, ...self::FONCTIONS_ACTIVES],
            );
            if ($autre !== false) {
                return ['champ' => 'fonction', 'message' => sprintf(
                    'Le cabinet travaille déjà en %s : il n’a qu’une monnaie de travail, celle de la saisie et de l’affichage. '
                    . 'Gardez « %s » comme référence.',
                    $autre,
                    (string) $monnaie->getCode(),
                )];
            }
        }

        // LA MONNAIE DE TRAVAIL CHANGE-T-ELLE ? Elle perd sa fonction, ou change de code. Seule
        // compte celle que l'écran retient (la première affichée) : retirer une seconde,
        // surnuméraire, ne change l'étiquette d'aucun montant.
        $codeChange = $avant !== false && strcasecmp((string) $avant['code'], (string) $monnaie->getCode()) !== 0;
        if ($etaitDeTravail && (!$estDeTravail || $codeChange) && $this->estLaRetenue($monnaie) && $this->aDesMontants($cabinet)) {
            return ['champ' => $codeChange && $estDeTravail ? 'code' : 'fonction', 'message' => sprintf(
                'Vos montants sont enregistrés en %s et ne sont jamais convertis : changer la monnaie de travail '
                . 'les ferait lire dans une autre monnaie. Elle ne change plus une fois des montants saisis.',
                (string) $avant['code'],
            )];
        }

        return null;
    }

    /** La monnaie de travail ne peut plus être retirée : elle porte des montants. Motif, ou null. */
    public function refusDeSuppression(Monnaie $monnaie): ?string
    {
        $cabinet = $monnaie->getEntreprise()?->getId();
        if ($cabinet === null || !in_array($monnaie->getFonction(), self::FONCTIONS_AFFICHEES, true)
            || !$this->estLaRetenue($monnaie) || !$this->aDesMontants($cabinet)) {
            return null;
        }

        return sprintf(
            'La monnaie %s est la monnaie de travail du cabinet, et vos montants y sont enregistrés : elle ne se supprime pas.',
            (string) $monnaie->getCode(),
        );
    }

    /**
     * Cette monnaie est-elle celle que l'écran retient comme monnaie de travail — la PREMIÈRE
     * (par id) qui s'affiche ? Même règle que ServiceMonnaies::monnaieDeTravail() et que la
     * migration du 2026-10-15 : c'est l'étiquette que portaient les montants à leur saisie.
     */
    private function estLaRetenue(Monnaie $monnaie): bool
    {
        if ($monnaie->getId() === null) {
            return false;
        }
        $retenue = $this->connexion->fetchOne(
            'SELECT id FROM monnaie WHERE entreprise_id = ? AND fonction IN (?, ?) ORDER BY id LIMIT 1',
            [$monnaie->getEntreprise()?->getId(), ...self::FONCTIONS_AFFICHEES],
        );

        return $retenue !== false && (int) $retenue === $monnaie->getId();
    }

    /**
     * Le cabinet a-t-il déjà enregistré un montant ? Les tables qui portent les montants
     * SAISIS du cabinet — jamais ses référentiels vides, qu'un semis pose sans montant.
     */
    public function aDesMontants(Entreprise|int $cabinet): bool
    {
        $id = $cabinet instanceof Entreprise ? (int) $cabinet->getId() : $cabinet;

        return (bool) $this->connexion->fetchOne(
            'SELECT 1 FROM DUAL WHERE'
            . ' EXISTS (SELECT 1 FROM piste WHERE entreprise_id = :e AND (prime_potentielle IS NOT NULL OR commission_potentielle IS NOT NULL))'
            . ' OR EXISTS (SELECT 1 FROM chargement_pour_prime WHERE entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM revenu_pour_courtier WHERE entreprise_id = :e AND montant_flat_exceptionel IS NOT NULL)'
            . ' OR EXISTS (SELECT 1 FROM type_revenu WHERE entreprise_id = :e AND montantflat IS NOT NULL)'
            . ' OR EXISTS (SELECT 1 FROM tranche WHERE entreprise_id = :e AND montant_flat IS NOT NULL)'
            . ' OR EXISTS (SELECT 1 FROM condition_partage WHERE entreprise_id = :e AND seuil <> 0)'
            . ' OR EXISTS (SELECT 1 FROM paiement WHERE entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM paiement_prime WHERE entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM reversement_retro_agent WHERE entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM notification_sinistre WHERE entreprise_id = :e AND (dommage IS NOT NULL OR evaluation_chiffree IS NOT NULL))'
            . ' OR EXISTS (SELECT 1 FROM offre_indemnisation_sinistre WHERE entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM `operation` o JOIN bordereau b ON b.id = o.bordereau_id WHERE b.entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM depense_courtier WHERE entreprise_id = :e)'
            . ' OR EXISTS (SELECT 1 FROM charge_courtier WHERE entreprise_id = :e AND montant_budgete_mensuel IS NOT NULL)',
            ['e' => $id],
        );
    }
}
