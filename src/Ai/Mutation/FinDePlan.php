<?php

namespace App\Ai\Mutation;

/**
 * LES TROIS FAÇONS DONT MEURT UN PLAN D'ÉCRITURE — et pourquoi il ne faut pas les
 * confondre.
 *
 * ── LE DÉFAUT QUE CELA CORRIGE (2026-09-14) ─────────────────────────────────
 * `mutationPlanCancelled` disait jusqu'ici trois choses à la fois :
 *
 *   1. l'utilisateur a cliqué sur « Annuler »   (AssistantIaController::cancelMutation)
 *   2. le modèle a REMPLACÉ le plan par un autre (PlanBuilder, remplacerPlanEnAttente)
 *   3. le plan a péri faute de décision          (péremption)
 *
 * Le gabarit, lui, ne connaissait qu'un libellé : « Plan annulé — aucune donnée
 * n'a été modifiée. » Un courtier a donc vu ce bandeau se poser sous des plans
 * qu'il n'avait JAMAIS annulés, simplement parce que Ket en avait présenté une
 * version complétée après qu'il eut donné un renseignement de plus. Le fil lui
 * attribuait des gestes qu'il n'avait pas faits — et lui laissait croire qu'il
 * avait refusé ce qu'il venait, au contraire, de préciser.
 *
 * ── UN ÉTAT, TROIS MOTIFS — jamais trois booléens ───────────────────────────
 * `PlanEnAttente::estEnAttente()` reste vrai ou faux, et tout le code décisionnel
 * continue de ne lire que cela : le verrou d'un seul plan, le choix de la trousse,
 * le court-circuit de la compréhension. Ce qui diffère entre les trois fins n'est
 * pas l'ÉTAT du plan — il est mort dans les trois cas — mais ce qu'on en DIT, à
 * l'utilisateur comme au modèle. Trois booléens indépendants autoriseraient
 * « annulé ET périmé », qui ne veut rien dire et qu'il faudrait arbitrer partout.
 *
 * ── POURQUOI PAS DANS TypeAction ────────────────────────────────────────────
 * `TypeAction` est la source unique des actions que le serveur ÉMET et que le
 * navigateur EXÉCUTE ; son test de contrat refuse — à raison — un cas qu'aucun
 * émetteur ne produit. Une fin de plan n'émet aucune action : c'est un état
 * persisté que le gabarit relit au rechargement. Elle appartient à la famille de
 * `planExecute` / `planAnnule`, pas à celle des panneaux porteurs de bouton.
 *
 * ── POURQUOI UN ENUM ADOSSÉ À DES CHAÎNES ───────────────────────────────────
 * Contrairement à {@see \App\Ai\Trousse\Phase}, qui ne vit que le temps d'un
 * message, cette valeur est ÉCRITE dans la meta d'un message et relue des mois
 * plus tard. Elle a donc besoin d'une représentation stable et lisible en base —
 * d'où l'adossement, et d'où {@see self::CLE_META}, qu'on ne renomme pas sans
 * migrer ce qui est déjà écrit.
 */
enum FinDePlan: string
{
    /** Clé de meta. CONTRAT DE PERSISTANCE : la renommer laisserait muets tous les plans déjà morts. */
    public const CLE_META = 'mutationPlanFin';

    /** L'utilisateur a cliqué sur « Annuler ». La SEULE qui s'annonce comme une annulation. */
    case UTILISATEUR = 'utilisateur';

    /** Le modèle a présenté une version corrigée ou complétée : l'ancienne cède la place. */
    case REMPLACE = 'remplace';

    /** Personne n'a tranché, et la conversation est passée à autre chose. */
    case PERIME = 'perime';

    /**
     * CE QUE LE COURTIER LIT SOUS LE PLAN MORT.
     *
     * Les trois disent que rien n'a été enregistré — c'est la seule chose dont il
     * ait besoin dans les trois cas. Mais une seule lui attribue un geste, et c'est
     * celle où il a effectivement cliqué. Les deux autres CONSTATENT, sans accuser.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::UTILISATEUR => 'Plan annulé — aucune donnée n’a été modifiée.',
            self::REMPLACE    => 'Plan remplacé par la version ci-dessous — rien n’a été enregistré.',
            self::PERIME      => 'Plan non tranché, abandonné — rien n’a été enregistré.',
        };
    }

    /**
     * Suffixe de `aic-plan-status--*`. Trois apparences distinctes : deux fins qui
     * se ressembleraient à l'œil se confondraient dans le fil, ce qui est
     * exactement le défaut qu'on corrige.
     */
    public function classeCss(): string
    {
        return match ($this) {
            self::UTILISATEUR => 'cancelled',
            self::REMPLACE    => 'replaced',
            self::PERIME      => 'lapsed',
        };
    }

    /**
     * CETTE FIN PEUT-ELLE ÊTRE VUE ARRIVER, EN DIRECT, DANS LE NAVIGATEUR ?
     *
     * Deux le peuvent : le clic sur « Annuler » (l'utilisateur est là, c'est lui qui
     * l'a fait) et le remplacement (une nouvelle barre de décision arrive dans le
     * même tour, et prend la place de l'ancienne — un simple fait du DOM, sans que
     * le serveur ait à le signaler).
     *
     * La PÉREMPTION, non : elle a lieu entre deux messages, côté serveur, et rien ne
     * la diffuse au navigateur. Le courtier la découvre au rendu suivant, par le
     * gabarit. Lui écrire un libellé dans le contrôleur de chat créerait une
     * constante qu'aucun code n'émettrait — exactement le « case sans émetteur »
     * que le contrat des actions d'interface interdit, parce qu'il simule une
     * capacité que personne ne fournit.
     */
    public function aUnEmetteurEnDirect(): bool
    {
        return $this !== self::PERIME;
    }

    /**
     * La fin inscrite dans une meta, ou null — FAIL-CLOSED.
     *
     * Une valeur inconnue ne retombe PAS sur « annulé » : un plan mort dont on a
     * perdu le motif ne doit pas se mettre à accuser l'utilisateur d'un clic. Le
     * gabarit garde alors son ancien libellé générique, et c'est tout.
     *
     * @param array<string, mixed> $meta
     */
    public static function depuisMeta(array $meta): ?self
    {
        $valeur = $meta[self::CLE_META] ?? null;

        return is_string($valeur) ? self::tryFrom($valeur) : null;
    }
}
