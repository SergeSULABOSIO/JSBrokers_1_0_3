<?php

namespace App\Service\Partage;

use App\Entity\ConditionPartage;
use App\Entity\Invite;
use App\Entity\Partenaire;

/**
 * LA CONDITION DE PARTAGE QU'UN INTERMÉDIAIRE REÇOIT D'OFFICE.
 *
 * ── LE MANQUE QU'ELLE COMBLE ────────────────────────────────────────────────────────
 * Un intermédiaire fraîchement créé — partenaire externe ou agent interne — n'avait
 * aucune condition de partage. Le calcul fonctionnait quand même pour un partenaire : sa
 * « Part % » sert de taux par défaut, tout en bas de la cascade. Mais il n'était pas
 * RATTACHABLE : le geste qui dit « ces affaires-ci relèvent de son accord » n'avait rien
 * à désigner, et il fallait aller écrire une condition dans une autre rubrique avant de
 * pouvoir s'en servir.
 *
 * ── LA « PART % » RESTE LA SOURCE DU TAUX ───────────────────────────────────────────
 * Deux écritures du même nombre finissent toujours par diverger — et c'est le taux de la
 * CONDITION qui paierait, pendant que l'écran annoncerait la part. La condition d'office
 * suit donc la part, TANT QU'ELLE N'A PAS ÉTÉ RETOUCHÉE. Dès que son taux s'en écarte,
 * elle appartient à l'utilisateur : on ne réécrit jamais par-dessus une décision.
 *
 * Cette filiation ne demande aucune colonne. La condition d'office est celle qui nomme ce
 * partenaire, ne cible aucun risque, et dont le taux ÉGALE ENCORE la part. Trois faits
 * lisibles, aucun état caché à maintenir.
 *
 * ── ET AUCUN MONTANT NE BOUGE ───────────────────────────────────────────────────────
 * La cascade retient UNE condition — exceptionnelle ▸ rattachée ▸ du partenaire — puis se
 * replie sur la part. Une condition d'office au même taux occupe simplement l'étage qui
 * précède ce repli : le résultat est identique. Un agent, lui, n'a pas de part : sa
 * condition naît sans taux, à saisir, et reste donc sans effet tant qu'on ne l'a pas
 * renseignée — ce qui est exactement l'état d'avant.
 */
final class ConditionDOffice
{
    /** Le libellé proposé — reconnaissable, et qui dit d'où elle vient. */
    public static function nomPour(string $nomDuBeneficiaire): string
    {
        return 'Partage — ' . $nomDuBeneficiaire;
    }

    /**
     * La condition d'office d'un intermédiaire, ou null s'il en a déjà une quelconque.
     *
     * On ne propose rien à qui a déjà écrit ses règles : la question « faut-il l'équiper »
     * ne se pose que pour un intermédiaire nu.
     */
    public static function manquantePour(Invite|Partenaire $beneficiaire): ?ConditionPartage
    {
        if (self::conditionsDe($beneficiaire) !== []) {
            return null;
        }

        $condition = (new ConditionPartage())
            ->setNom(self::nomPour((string) $beneficiaire->getNom()))
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)
            // AUCUN RISQUE CIBLÉ : elle vaut pour tout ce que l'intermédiaire apporte.
            // C'est le neutre, et c'est ce qu'on attend d'un accord-cadre ; le ciblage se
            // pose ensuite, affaire par affaire, s'il y a lieu.
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);

        if ($beneficiaire instanceof Partenaire) {
            $condition->setPartenaire($beneficiaire)->setTaux($beneficiaire->getPart());
        } else {
            // UN AGENT N'A PAS DE PART. Sa condition naît sans taux, à saisir : inventer
            // un pourcentage reviendrait à lui promettre une rémunération que personne
            // n'a décidée.
            $condition->setAgent($beneficiaire);
        }

        $condition->setEntreprise($beneficiaire->getEntreprise());
        // L auteur au sens de l audit. Un partenaire porte le sien (AuditableTrait) ;
        // un invité N EST PAS audité de la sorte — il EST un auteur possible, et c est
        // donc lui-même qu on inscrit. Le champ reste nullable : rien n en dépend ici.
        $condition->setInvite($beneficiaire instanceof Partenaire ? $beneficiaire->getInvite() : $beneficiaire);

        return $condition;
    }

    /**
     * LA CONDITION D'OFFICE D'UN PARTENAIRE, SI ELLE SUIT ENCORE SA PART.
     *
     * Trois faits la désignent : elle le nomme, elle ne cible aucun risque, et son taux
     * égale encore la part connue. Le troisième est celui qui compte — dès que
     * l'utilisateur a corrigé le taux, la condition cesse d'être suivie.
     *
     * @param float|null $partPrecedente la part d'AVANT la modification en cours
     */
    public static function suivantLaPart(Partenaire $partenaire, ?float $partPrecedente): ?ConditionPartage
    {
        foreach ($partenaire->getConditionPartages() as $condition) {
            if ($condition->getCritereRisque() !== ConditionPartage::CRITERE_PAS_RISQUES_CIBLES) {
                continue;
            }
            // On compare à la part PRÉCÉDENTE : la nouvelle vient d'être posée sur
            // l'entité, et comparer à elle-même ne dirait jamais rien.
            if (self::memeTaux($condition->getTaux(), $partPrecedente)) {
                return $condition;
            }
        }

        return null;
    }

    /** Deux taux en POINTS sont-ils le même ? Comparés au centième, comme les montants. */
    public static function memeTaux(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs($a - $b) < 0.005;
    }

    /**
     * Les conditions dont cet intermédiaire est bénéficiaire, quelle que soit sa famille.
     *
     * @return ConditionPartage[]
     */
    private static function conditionsDe(Invite|Partenaire $beneficiaire): array
    {
        // Les deux familles ne nomment pas leur collection de la même façon — l une
        // est celle du partenaire, l autre celle de l agent — mais la question posée est
        // la même : cet intermédiaire a-t-il déjà écrit une règle ?
        return $beneficiaire instanceof Partenaire
            ? $beneficiaire->getConditionPartages()->toArray()
            : $beneficiaire->getConditionsPartageAgent()->toArray();
    }
}
