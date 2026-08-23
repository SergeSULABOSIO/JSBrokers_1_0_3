<?php

namespace App\Service\Partage;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Repository\ReversementRetroAgentRepository;
use App\Services\ServiceMonnaies;

/**
 * CETTE AFFAIRE EST-ELLE L'EFFORT COMMERCIAL D'UN AGENT — ET PEUT-ON Y TOUCHER ?
 *
 * Une seule autorité, consultée par tout ce qui pose la question : les quatre listes du
 * workspace (piste, proposition, avenant, tranche), les routes de rattachement, l'assistant,
 * et le moteur de mutation. Le précédent est `LiensProteges` : une règle métier ne vaut que
 * si TOUS les chemins d'écriture la consultent — sinon celui qui l'oublie devient son
 * contournement.
 *
 * ── LE RATTACHEMENT VIT SUR LA PISTE, ET NULLE PART AILLEURS ─────────────────────────
 * On peut désormais l'ordonner depuis un avenant, une tranche ou une proposition, parce que
 * c'est de là qu'on travaille — mais l'écriture, elle, remonte toujours à l'affaire. C'est
 * `piste()` qui fait ce chemin, et lui seul le connaît.
 *
 * ── LES DEUX CANAUX COMPTENT ─────────────────────────────────────────────────────────
 * Une condition d'agent se pose normalement par la collection PARTAGÉE
 * (`conditionsPartageAgent`), celle qui la rend réutilisable. Rien n'empêche pourtant de la
 * créer dans les « conditions spéciales de partage », liée à cette seule piste. N'en lire
 * qu'une laisserait croire l'affaire libre alors qu'un agent en bénéficie déjà — et l'on
 * rattacherait un second bénéficiaire à une affaire qui n'en veut qu'un. Même règle que
 * `IndicatorCalculationHelper::getCotationConditionsAgent()`, et un test croisé garantit que
 * les deux lectures ne divergent pas.
 *
 * ── ON NE FILTRE PAS PAR RISQUE, ICI ────────────────────────────────────────────────
 * Le décompte, lui, écarte une condition qui ne s'applique pas au risque du jour : elle ne
 * paie rien. Mais elle reste un RATTACHEMENT, et c'est ce qu'on gouverne. Filtrer laisserait
 * poser un second agent « puisque le premier ne touche rien » — pour découvrir plus tard
 * qu'ils sont deux.
 */
final class EffortCommercialAgent
{
    public function __construct(
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly ServiceMonnaies $serviceMonnaies,
    ) {
    }

    /**
     * L'affaire à laquelle appartient cet objet, où qu'on se trouve dans son arbre.
     *
     * Les quatre écrans passent par ici. Une table explicite plutôt qu'un `match` sur des
     * noms de classe venus de l'URL : on ne remonte l'arbre que pour des types qu'on a
     * nommés.
     */
    public function piste(?object $entite): ?Piste
    {
        return match (true) {
            $entite instanceof Piste => $entite,
            $entite instanceof Cotation => $entite->getPiste(),
            $entite instanceof Avenant, $entite instanceof Tranche => $entite->getCotation()?->getPiste(),
            default => null,
        };
    }

    /**
     * La condition d'agent rattachée à cette affaire, tous canaux confondus.
     *
     * Tri par identifiant croissant : le choix doit être le même d'un appel à l'autre, sans
     * quoi le voyant nommerait un agent et le refus en nommerait un autre.
     */
    public function condition(?Piste $piste): ?ConditionPartage
    {
        if ($piste === null) {
            return null;
        }

        $candidates = [];
        foreach ($piste->getConditionsPartageAgent() as $condition) {
            if ($condition->estPourAgent()) {
                $candidates[(int) $condition->getId()] = $condition;
            }
        }
        foreach ($piste->getConditionsPartageExceptionnelles() as $condition) {
            if ($condition->estPourAgent()) {
                $candidates[(int) $condition->getId()] = $condition;
            }
        }
        if ($candidates === []) {
            return null;
        }

        ksort($candidates);

        return reset($candidates);
    }

    /** L'agent qui bénéficie de cette affaire, s'il y en a un. */
    public function agent(?Piste $piste): ?Invite
    {
        return $this->condition($piste)?->getAgent();
    }

    /**
     * LE VOYANT DE LA LIGNE — « Effort commercial : Alice », ou rien.
     *
     * Rien, et non « aucun agent » : une affaire sans condition est le cas NORMAL, celle que
     * le cabinet a gagnée seul. L'annoncer sur chaque ligne serait du bruit sur la majorité
     * des lignes.
     *
     * Cette même valeur sert de drapeau aux actions de la barre d'outils : un seul champ
     * pour une seule information, sinon les deux finiraient par se contredire.
     */
    public function libelle(?Piste $piste): ?string
    {
        $agent = $this->agent($piste);
        if ($agent === null) {
            return null;
        }

        return 'Effort commercial : ' . ($agent->getNom() ?: '#' . $agent->getId());
    }

    /** Ce qui a déjà été reversé à cet agent sur cette affaire. */
    public function montantDejaReverse(Piste $piste, Invite $agent): float
    {
        return $this->reversements->totalVersePourAgentSurPiste($agent, $piste);
    }

    /**
     * Peut-on rattacher une condition d'agent à cette affaire ? Rend le MOTIF du refus, ou
     * null si le geste est permis.
     *
     * Le message est rendu ici, une fois, pour être dit à l'identique par le picker, par la
     * route, par le toast et par l'assistant : un utilisateur ne doit pas apprendre deux
     * formulations d'une même règle.
     */
    public function refusDeRattachement(?Piste $piste): ?string
    {
        if ($piste === null) {
            return 'Impossible de retrouver l\'affaire à laquelle rattacher la condition.';
        }

        $existante = $this->condition($piste);
        if ($existante === null) {
            return null;
        }

        return sprintf(
            'L\'affaire « %s » revient déjà à %s (condition « %s »). Une affaire n\'a qu\'un seul '
            . 'agent bénéficiaire : détachez la condition en place avant d\'en rattacher une autre.',
            $piste->getNom() ?: ('#' . $piste->getId()),
            $existante->getAgent()?->getNom() ?: 'un agent',
            $existante->getNom() ?: ('#' . $existante->getId()),
        );
    }

    /**
     * Peut-on détacher la condition en place ? Rend le MOTIF du refus, ou null.
     *
     * UN VERSEMENT SCELLE L'AFFAIRE. Détacher après un virement reviendrait à effacer la
     * raison d'un décaissement déjà comptabilisé. Et comme changer d'agent suppose de
     * détacher, ce refus ferme aussi le remplacement : le message le dit, sinon
     * l'utilisateur essaierait de rattacher ailleurs et se heurterait à un second refus sans
     * savoir lequel des deux le bloque.
     */
    public function refusDeDetachement(?Piste $piste): ?string
    {
        if ($piste === null) {
            return 'Impossible de retrouver l\'affaire dont détacher la condition.';
        }

        $condition = $this->condition($piste);
        if ($condition === null) {
            return sprintf(
                'L\'affaire « %s » ne revient à aucun agent : il n\'y a rien à détacher.',
                $piste->getNom() ?: ('#' . $piste->getId()),
            );
        }

        $agent = $condition->getAgent();
        if ($agent === null) {
            return null;
        }

        $verse = $this->montantDejaReverse($piste, $agent);
        if ($verse <= 0.0) {
            return null;
        }

        return sprintf(
            '%s a déjà reçu %s %s sur cette affaire : le rattachement ne peut plus être défait, '
            . 'ni remplacé par un autre agent. Un versement parti ne se réécrit pas.',
            $agent->getNom() ?: 'Cet agent',
            number_format($verse, 2, ',', ' '),
            $this->serviceMonnaies->getCodeMonnaieAffichage(),
        );
    }

    /**
     * LE LOT EST TOUT OU RIEN.
     *
     * On rattache une condition à plusieurs affaires d'un geste ; si UNE SEULE est déjà
     * prise, on refuse l'ensemble en la nommant. Appliquer le reste serait pire qu'un refus :
     * l'utilisateur croirait avoir tout couvert, et l'affaire oubliée ne se signalerait
     * jamais.
     *
     * @param Piste[] $pistes déjà dédoublonnées par l'appelant
     */
    public function refusDuLot(array $pistes): ?string
    {
        if ($pistes === []) {
            return 'Aucune affaire à rattacher : la sélection n\'a rien donné.';
        }

        $prises = [];
        foreach ($pistes as $piste) {
            $existante = $this->condition($piste);
            if ($existante !== null) {
                $prises[] = sprintf(
                    '« %s » (%s)',
                    $piste->getNom() ?: ('#' . $piste->getId()),
                    $existante->getAgent()?->getNom() ?: 'un agent',
                );
            }
        }
        if ($prises === []) {
            return null;
        }

        return sprintf(
            'Rien n\'a été rattaché : %s revient%s déjà à un agent. Une affaire n\'a qu\'un seul '
            . 'agent bénéficiaire — détachez d\'abord, ou retirez-la de la sélection.',
            implode(', ', $prises),
            count($prises) > 1 ? 'nt' : '',
        );
    }
}
