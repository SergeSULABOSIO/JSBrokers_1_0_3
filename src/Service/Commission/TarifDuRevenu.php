<?php

namespace App\Service\Commission;

use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;

/**
 * LE TARIF D'UNE COMMISSION — une seule règle, pour les écrans, la facturation, l'export
 * et Ket (chantier « Assiette et source unique de la commission », décision du
 * 2026-10-15 ; invariant « une source unique par montant » de `courtage-assurance`).
 *
 * ── L'ORDRE, QUI EST CELUI DES ÉCRANS ───────────────────────────────────────────────
 *  1. le taux EXCEPTIONNEL du revenu ;
 *  2. le forfait EXCEPTIONNEL du revenu ;
 *  3. le pourcentage du TYPE ;
 *  4. le forfait du TYPE ;
 *  5. le taux du RISQUE, quand le type s'y adosse.
 * C'est l'ordre qui a toujours FACTURÉ (`IndicatorCalculationHelper`). `Constante` et
 * l'annonce de Ket plaçaient le risque avant le type : un type qui portait les deux était
 * facturé à son pourcentage pendant que Ket annonçait le taux du risque.
 *
 * ⚠ LE TAUX DU RISQUE EST LE DERNIER RECOURS, PAS UNE PRIORITÉ. Le formulaire le dit
 * désormais : « à défaut de pourcentage ou de forfait sur ce type ».
 *
 * Le résultat garde la forme qu'attendait l'export du portefeuille
 * (`{taux, forfait, origine}`, taux en POINTS) : `origine` vaut « revenu » pour une
 * dérogation (et pour « rien », qui ne s'hérite pas), « type » ou « risque » sinon.
 */
final class TarifDuRevenu
{
    /**
     * Le tarif d'un revenu existant (ses dérogations d'abord, puis son type et son risque).
     *
     * @return array{taux: ?float, forfait: ?float, origine: string}
     */
    public static function deRevenu(?RevenuPourCourtier $revenu): array
    {
        $type = $revenu?->getTypeRevenu();
        if ($revenu === null || $type === null) {
            return self::rien();
        }

        if ((float) ($revenu->getTauxExceptionel() ?? 0.0) != 0.0) {
            return ['taux' => (float) $revenu->getTauxExceptionel(), 'forfait' => null, 'origine' => 'revenu'];
        }
        if ((float) ($revenu->getMontantFlatExceptionel() ?? 0.0) != 0.0) {
            return ['taux' => null, 'forfait' => (float) $revenu->getMontantFlatExceptionel(), 'origine' => 'revenu'];
        }

        return self::deType($type, $revenu->getCotation()?->getPiste()?->getRisque());
    }

    /**
     * Ce que la configuration prescrit pour un type et un risque, À DÉFAUT de dérogation —
     * pour un revenu qui n'existe pas encore (l'annonce de Ket).
     *
     * @return array{taux: ?float, forfait: ?float, origine: string}
     */
    public static function deType(TypeRevenu $type, ?Risque $risque): array
    {
        if ((float) ($type->getPourcentage() ?? 0.0) != 0.0) {
            return ['taux' => (float) $type->getPourcentage(), 'forfait' => null, 'origine' => 'type'];
        }
        if ((float) ($type->getMontantflat() ?? 0.0) != 0.0) {
            return ['taux' => null, 'forfait' => (float) $type->getMontantflat(), 'origine' => 'type'];
        }
        if ($type->isAppliquerPourcentageDuRisque() === true
            && (float) ($risque?->getPourcentageCommissionSpecifiqueHT() ?? 0.0) != 0.0) {
            return ['taux' => (float) $risque->getPourcentageCommissionSpecifiqueHT(), 'forfait' => null, 'origine' => 'risque'];
        }

        return self::rien();
    }

    /**
     * Le montant HT de ce tarif sur cette assiette : un forfait s'AJOUTE tel quel, un taux
     * (en points) s'applique à l'assiette.
     *
     * @param array{taux: ?float, forfait: ?float, origine: string} $tarif
     */
    public static function montantHt(array $tarif, float $assiette): float
    {
        if ($tarif['forfait'] !== null) {
            return $tarif['forfait'];
        }

        return $tarif['taux'] !== null ? $assiette * ($tarif['taux'] / 100.0) : 0.0;
    }

    /** @return array{taux: null, forfait: null, origine: string} */
    private static function rien(): array
    {
        return ['taux' => null, 'forfait' => null, 'origine' => 'revenu'];
    }
}
