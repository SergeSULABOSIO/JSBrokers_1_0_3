<?php

namespace App\Service\Partage;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Repository\ReversementRetroAgentRepository;
use App\Service\Retro\BeneficiaireRetro;
use App\Services\ServiceMonnaies;

/**
 * QUI SE PARTAGE CETTE AFFAIRE — ET PEUT-ON Y TOUCHER ?
 *
 * Une seule autorité, consultée par tout ce qui pose la question : les quatre listes du
 * workspace (piste, proposition, avenant, tranche), les routes de rattachement, l'assistant,
 * et le moteur de mutation. Le précédent est `LiensProteges` : une règle métier ne vaut que
 * si TOUS les chemins d'écriture la consultent — sinon celui qui l'oublie devient son
 * contournement.
 *
 * ── DEUX FAMILLES, UNE SEULE GRAMMAIRE ───────────────────────────────────────────────
 * Elle ne gouvernait que l'agent interne, et s'appelait `EffortCommercialAgent`. Le
 * partenaire externe, lui, n'avait aucun rattachement : sa condition appartenait soit à
 * l'affaire (exceptionnelle), soit à lui-même — et valait alors pour TOUTES ses affaires.
 * On ne pouvait donc pas dire « ces trois affaires-ci relèvent de l'accord SUNU 20 % ».
 *
 * La règle est désormais unique : **une affaire a au plus UN bénéficiaire PAR FAMILLE**.
 * Pas un seul en tout — le partenaire se sert d'abord, l'agent partage le reliquat, et les
 * fondre en un aurait interdit la mécanique même du cabinet.
 *
 * La famille n'est JAMAIS un paramètre d'écran : elle se lit sur la condition choisie
 * (`estPourAgent()`). L'utilisateur choisit une condition, pas une famille.
 *
 * ── LE RATTACHEMENT VIT SUR LA PISTE, ET NULLE PART AILLEURS ─────────────────────────
 * On peut l'ordonner depuis un avenant, une tranche ou une proposition, parce que c'est de
 * là qu'on travaille — mais l'écriture, elle, remonte toujours à l'affaire. C'est `piste()`
 * qui fait ce chemin, et lui seul le connaît.
 *
 * ── LES DEUX CANAUX COMPTENT ─────────────────────────────────────────────────────────
 * Une condition se pose normalement par la collection PARTAGÉE (`conditionsPartageAgent`,
 * dont le nom dit « agent » pour des raisons historiques mais qui accueille les deux
 * familles). Rien n'empêche pourtant de la créer dans les « conditions spéciales de
 * partage », liée à cette seule piste. N'en lire qu'une laisserait croire la place libre
 * alors qu'un bénéficiaire l'occupe déjà — et l'on en rattacherait un second. Même règle
 * que `IndicatorCalculationHelper::getCotationConditionsAgent()`, et un test croisé
 * garantit que les deux lectures ne divergent pas.
 *
 * ── ON NE FILTRE PAS PAR RISQUE, ICI ────────────────────────────────────────────────
 * Le décompte, lui, écarte une condition qui ne s'applique pas au risque du jour : elle ne
 * paie rien. Mais elle reste un RATTACHEMENT, et c'est ce qu'on gouverne. Filtrer laisserait
 * poser un second bénéficiaire « puisque le premier ne touche rien » — pour découvrir plus
 * tard qu'ils sont deux.
 */
final class RattachementDuPartage
{
    public function __construct(
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly ServiceMonnaies $serviceMonnaies,
    ) {
    }

    /** La famille d'une condition — lue sur elle, jamais dictée par l'appelant. */
    public function familleDe(ConditionPartage $condition): string
    {
        return $condition->estPourAgent()
            ? BeneficiaireRetro::TYPE_AGENT
            : BeneficiaireRetro::TYPE_PARTENAIRE;
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
     * Les conditions rattachées à cette affaire, UNE PAR FAMILLE.
     *
     * Tri par identifiant croissant : le choix doit être le même d'un appel à l'autre, sans
     * quoi le voyant nommerait un bénéficiaire et le refus en nommerait un autre.
     *
     * @return array<string, ConditionPartage> indexé par famille
     */
    public function conditions(?Piste $piste): array
    {
        if ($piste === null) {
            return [];
        }

        $candidates = [];
        foreach ([$piste->getConditionsPartageAgent(), $piste->getConditionsPartageExceptionnelles()] as $canal) {
            foreach ($canal as $condition) {
                $candidates[(int) $condition->getId()] = $condition;
            }
        }
        ksort($candidates);

        $retenues = [];
        foreach ($candidates as $condition) {
            // La PREMIÈRE de chaque famille l'emporte — les suivantes ne s'ajoutent pas.
            $retenues[$this->familleDe($condition)] ??= $condition;
        }

        return $retenues;
    }

    /** La condition rattachée pour cette famille, s'il y en a une. */
    public function condition(?Piste $piste, string $famille): ?ConditionPartage
    {
        return $this->conditions($piste)[$famille] ?? null;
    }

    /** Le bénéficiaire de cette famille sur cette affaire, s'il y en a un. */
    public function beneficiaire(?Piste $piste, string $famille): Invite|Partenaire|null
    {
        $condition = $this->condition($piste, $famille);
        if ($condition === null) {
            return null;
        }

        return $famille === BeneficiaireRetro::TYPE_AGENT
            ? $condition->getAgent()
            : $condition->getPartenaire();
    }

    /**
     * LE VOYANT DE LA LIGNE — « Apporteur : SUNU · Effort : Alice », ou rien.
     *
     * Rien, et non « aucun bénéficiaire » : une affaire sans condition est le cas NORMAL,
     * celle que le cabinet a gagnée seul. L'annoncer sur chaque ligne serait du bruit sur
     * la majorité des lignes.
     *
     * UN SEUL voyant nomme les deux familles, parce qu'une affaire peut légitimement les
     * porter toutes les deux : le partenaire l'apporte, l'agent la travaille. Deux colonnes
     * auraient été vides l'une comme l'autre sur la plupart des lignes.
     */
    public function libelle(?Piste $piste): ?string
    {
        return $this->libelleDepuis($this->conditions($piste));
    }

    /**
     * LES TROIS VALEURS QUE LES LISTES ATTENDENT, en UNE traversée.
     *
     * Les quatre rubriques de l'arbre (piste, proposition, avenant, tranche) affichent le
     * même voyant et gouvernent les mêmes actions. Leur faire appeler trois méthodes, c'est
     * trois parcours de collections par ligne de liste — le genre de N+1 qui ne se voit
     * qu'à soixante lignes — et trois occasions pour l'une des rubriques d'en oublier une.
     *
     * `partageRattachable` est vrai tant qu'une famille AU MOINS est libre : une affaire
     * peut recevoir un apporteur et un agent, mais pas deux du même camp.
     *
     * @return array{partageLibelle: ?string, partageRattachable: bool, partageDetachable: bool}
     */
    public function indicateurs(?object $entite): array
    {
        $piste = $this->piste($entite);
        $conditions = $this->conditions($piste);

        return [
            'partageLibelle'     => $this->libelleDepuis($conditions),
            'partageRattachable' => $piste !== null && count($conditions) < 2,
            'partageDetachable'  => $conditions !== [],
        ];
    }

    /**
     * @param array<string, ConditionPartage> $conditions
     */
    private function libelleDepuis(array $conditions): ?string
    {
        $morceaux = [];
        foreach ([
            BeneficiaireRetro::TYPE_PARTENAIRE => 'Apporteur',
            BeneficiaireRetro::TYPE_AGENT => 'Effort',
        ] as $famille => $prefixe) {
            $condition = $conditions[$famille] ?? null;
            if ($condition === null) {
                continue;
            }
            $beneficiaire = $famille === BeneficiaireRetro::TYPE_AGENT
                ? $condition->getAgent()
                : $condition->getPartenaire();
            if ($beneficiaire !== null) {
                $morceaux[] = $prefixe . ' : ' . ($beneficiaire->getNom() ?: '#' . $beneficiaire->getId());
            }
        }

        return $morceaux === [] ? null : implode(' · ', $morceaux);
    }

    /** Ce qui a déjà été reversé à ce bénéficiaire sur cette affaire. */
    public function montantDejaReverse(Piste $piste, Invite|Partenaire $beneficiaire): float
    {
        return $this->reversements->totalVersePourBeneficiaireSurPiste($beneficiaire, $piste);
    }

    /**
     * Peut-on rattacher CETTE condition à cette affaire ? Rend le MOTIF du refus, ou null.
     *
     * Le message est rendu ici, une fois, pour être dit à l'identique par le picker, par la
     * route, par le toast et par l'assistant : un utilisateur ne doit pas apprendre deux
     * formulations d'une même règle.
     */
    public function refusDeRattachement(?Piste $piste, ConditionPartage $condition): ?string
    {
        if ($piste === null) {
            return 'Impossible de retrouver l\'affaire à laquelle rattacher la condition.';
        }

        $famille = $this->familleDe($condition);
        $existante = $this->condition($piste, $famille);
        if ($existante !== null) {
            return sprintf(
                'L\'affaire « %s » revient déjà à %s (condition « %s »). Une affaire n\'a qu\'un seul '
                . '%s bénéficiaire : détachez la condition en place avant d\'en rattacher une autre.',
                $piste->getNom() ?: ('#' . $piste->getId()),
                $this->nomDuBeneficiaireDe($existante),
                $existante->getNom() ?: ('#' . $existante->getId()),
                $famille === BeneficiaireRetro::TYPE_AGENT ? 'agent' : 'intermédiaire',
            );
        }

        return $this->refusDeLIntermediaire($piste, $condition);
    }

    /**
     * L'INTERMÉDIAIRE DE L'AFFAIRE DOIT ÊTRE CELUI QUE LA CONDITION NOMME.
     *
     * C'est la seule règle qui n'a pas d'équivalent côté agent, et elle vient de la
     * structure : un agent est nommé PAR sa condition, tandis que l'intermédiaire est
     * désigné par l'AFFAIRE (`Piste::partenaire`) — la condition ne fait que moduler son
     * taux. Rattacher la condition d'un autre produirait une règle que le calcul écarterait
     * en silence, puisqu'il ne retient que les conditions de l'intermédiaire du jour.
     *
     * Quand la place est LIBRE, le rattachement la pose (cf. `designerIntermediaire`) :
     * c'est l'implication, pas un refus.
     */
    private function refusDeLIntermediaire(Piste $piste, ConditionPartage $condition): ?string
    {
        if ($this->familleDe($condition) !== BeneficiaireRetro::TYPE_PARTENAIRE) {
            return null;
        }

        $vise = $condition->getPartenaire();
        if ($vise === null) {
            return sprintf(
                'La condition « %s » ne désigne aucun intermédiaire : elle ne peut rétrocéder à personne.',
                $condition->getNom() ?: ('#' . $condition->getId()),
            );
        }

        $actuel = $piste->getPartenaire();
        if ($actuel === null || $actuel->getId() === $vise->getId()) {
            return null;
        }

        return sprintf(
            'L\'affaire « %s » est apportée par %s. La condition « %s » rétrocède à %s : détachez '
            . 'd\'abord, ou choisissez une condition de %s.',
            $piste->getNom() ?: ('#' . $piste->getId()),
            $actuel->getNom() ?: 'un autre intermédiaire',
            $condition->getNom() ?: ('#' . $condition->getId()),
            $vise->getNom() ?: 'un autre intermédiaire',
            $actuel->getNom() ?: 'l\'intermédiaire en place',
        );
    }

    /**
     * POSE L'INTERMÉDIAIRE QUAND L'AFFAIRE N'EN A PAS.
     *
     * Le pendant de l'implication des chips : le choix le plus précis entraîne le plus
     * général. Une condition de partenaire rattachée à une affaire sans apporteur serait
     * autrement écrite en base sans jamais rien produire — le calcul ne retient que les
     * conditions de l'intermédiaire désigné.
     *
     * ⚠ C'est une ÉCRITURE IMPLICITE, et la seule de ce service : elle change qui touche
     * l'argent. L'appelant doit la DIRE — le picker l'annonce avant le clic, le message de
     * retour la confirme. Elle n'a lieu que sur une place libre ; sur une place prise,
     * `refusDeLIntermediaire()` a déjà arrêté le geste.
     *
     * @return bool true si la désignation a été posée — l'appelant le dit à l'utilisateur
     */
    public function designerIntermediaire(Piste $piste, ConditionPartage $condition): bool
    {
        if ($this->familleDe($condition) !== BeneficiaireRetro::TYPE_PARTENAIRE) {
            return false;
        }
        if ($piste->getPartenaire() !== null || $condition->getPartenaire() === null) {
            return false;
        }

        // `Piste::setPartenaire()` recentre du même geste les conditions PROPRES de
        // l'affaire sur le nouvel intermédiaire : on ne réécrit pas cette règle ici.
        $piste->setPartenaire($condition->getPartenaire());

        return true;
    }

    /**
     * Peut-on détacher CETTE condition ? Rend le MOTIF du refus, ou null.
     *
     * UN VERSEMENT SCELLE L'AFFAIRE. Détacher après un virement reviendrait à effacer la
     * raison d'un décaissement déjà comptabilisé. Et comme changer de bénéficiaire suppose
     * de détacher, ce refus ferme aussi le remplacement : le message le dit, sinon
     * l'utilisateur essaierait de rattacher ailleurs et se heurterait à un second refus sans
     * savoir lequel des deux le bloque.
     *
     * La règle vaut pour les DEUX familles depuis que le partenaire envoie sa note de débit
     * et se règle par reversement, comme un agent.
     */
    public function refusDeDetachement(?Piste $piste, ?ConditionPartage $condition): ?string
    {
        if ($piste === null) {
            return 'Impossible de retrouver l\'affaire dont détacher la condition.';
        }
        if ($condition === null) {
            return sprintf(
                'L\'affaire « %s » ne revient à personne : il n\'y a rien à détacher.',
                $piste->getNom() ?: ('#' . $piste->getId()),
            );
        }

        $famille = $this->familleDe($condition);
        $enPlace = $this->condition($piste, $famille);
        if ($enPlace === null || $enPlace->getId() !== $condition->getId()) {
            return sprintf(
                'La condition « %s » n\'est pas rattachée à l\'affaire « %s ».',
                $condition->getNom() ?: ('#' . $condition->getId()),
                $piste->getNom() ?: ('#' . $piste->getId()),
            );
        }

        $beneficiaire = $this->beneficiaire($piste, $famille);
        if ($beneficiaire === null) {
            return null;
        }

        $verse = $this->montantDejaReverse($piste, $beneficiaire);
        if ($verse <= 0.0) {
            return null;
        }

        return sprintf(
            '%s a déjà reçu %s %s sur cette affaire : le rattachement ne peut plus être défait, '
            . 'ni remplacé par un autre %s. Un versement parti ne se réécrit pas.',
            $beneficiaire->getNom() ?: 'Ce bénéficiaire',
            number_format($verse, 2, ',', ' '),
            $this->serviceMonnaies->getCodeMonnaieAffichage(),
            $famille === BeneficiaireRetro::TYPE_AGENT ? 'agent' : 'intermédiaire',
        );
    }

    /**
     * LE LOT EST TOUT OU RIEN.
     *
     * On rattache une condition à plusieurs affaires d'un geste ; si UNE SEULE refuse, on
     * refuse l'ensemble en la nommant. Appliquer le reste serait pire qu'un refus :
     * l'utilisateur croirait avoir tout couvert, et l'affaire oubliée ne se signalerait
     * jamais.
     *
     * @param Piste[] $pistes déjà dédoublonnées par l'appelant
     */
    public function refusDuLot(array $pistes, ConditionPartage $condition): ?string
    {
        if ($pistes === []) {
            return 'Aucune affaire à rattacher : la sélection n\'a rien donné.';
        }

        $motifs = [];
        foreach ($pistes as $piste) {
            $refus = $this->refusDeRattachement($piste, $condition);
            if ($refus !== null) {
                $motifs[] = $refus;
            }
        }
        if ($motifs === []) {
            return null;
        }

        // Le premier motif porte déjà la règle en toutes lettres ; les suivants ne feraient
        // que la répéter. On NOMME en revanche toutes les affaires fautives, sans quoi
        // l'utilisateur corrigerait la première pour buter sur la seconde.
        return count($motifs) === 1
            ? 'Rien n\'a été rattaché. ' . $motifs[0]
            : sprintf('Rien n\'a été rattaché — %d affaires s\'y opposent. %s', count($motifs), $motifs[0]);
    }

    /** Le nom du bénéficiaire d'une condition, quelle que soit sa famille. */
    private function nomDuBeneficiaireDe(ConditionPartage $condition): string
    {
        $beneficiaire = $condition->estPourAgent() ? $condition->getAgent() : $condition->getPartenaire();

        return $beneficiaire?->getNom() ?: 'un bénéficiaire';
    }
}
