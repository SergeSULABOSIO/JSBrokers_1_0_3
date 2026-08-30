<?php

namespace App\Services;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;

/**
 * Reconduit le schéma de partage de revenu avec le(s) partenaire(s) d'une piste
 * de base vers une piste dérivée (renouvellement, prorogation, ajustement, ou
 * nouvelle piste d'exercice issue d'un import bordereau).
 *
 * Objectif métier : garantir que le partenaire et les mêmes proportions de partage
 * (rétrocommission) soient reconduits à l'identique sur l'avenant suivant.
 *
 * Trois porteurs du partage sur une piste :
 *  1. les partenaires associés (Piste::partenaires) ;
 *  2. les conditions de partage exceptionnelles (Piste::conditionsPartageExceptionnelles),
 *     propriété de leur piste — donc CLONÉES ;
 *  3. les conditions au profit d'AGENTS INTERNES (Piste::conditionsPartageAgent),
 *     partagées entre plusieurs affaires — donc RATTACHÉES telles quelles.
 *
 * Les partenaires portés par le client sont déjà repris automatiquement lorsque
 * la piste dérivée pointe vers le même client — ce service ne traite que le niveau piste.
 */
class ReconductionPartageService
{
    /**
     * @param Piste       $source     Piste de l'avenant de base.
     * @param Piste       $cible      Piste dérivée (neuve) à qui reconduire le partage.
     * @param Entreprise  $entreprise Entreprise propriétaire (audit des conditions clonées).
     * @param Invite|null $invite     Invité auteur (audit des conditions clonées).
     */
    public function reconduire(Piste $source, Piste $cible, Entreprise $entreprise, ?Invite $invite): void
    {
        // 1. L'intermédiaire — un seul par affaire, donc une simple affectation.
        $cible->setPartenaire($source->getPartenaire());

        // 2. Conditions de partage exceptionnelles — TOUTES, sans exception
        // (règle et justification : champsReconductibles).
        foreach ($this->champsReconductibles($source) as $champs) {
            $clone = (new ConditionPartage())
                ->setNom($champs['nom'])
                ->setFormule($champs['formule'])
                ->setSeuil($champs['seuil'])
                ->setTaux($champs['taux'])
                ->setUniteMesure($champs['uniteMesure'])
                ->setPartenaire($champs['partenaire'])
                ->setCritereRisque($champs['critereRisque']);

            // LES RISQUES VISÉS SUIVENT, un par un. Ils ne se recopiaient pas : la
            // condition dérivée arrivait « générale » ou inerte, et l'utilisateur
            // rouvrait chacune pour re-cocher ses risques, exercice après exercice.
            foreach ($champs['produits'] as $risque) {
                $clone->addProduit($risque);
            }

            $clone->setEntreprise($entreprise);
            $clone->setInvite($invite);
            // createdAt / updatedAt sont posés par le PrePersist de AuditableTrait.

            $cible->addConditionsPartageExceptionnelle($clone);
        }

        // 3. Conditions de partage au profit d'AGENTS INTERNES : la MEME condition est
        // RATTACHEE a la piste derivee, jamais clonee.
        //
        // POURQUOI PAS UN CLONE, ICI. Une condition d'agent est PARTAGEE par construction :
        // la regle « prime apporteur 15 % » est ecrite une fois et sert a toutes ses
        // affaires. La cloner en creerait une copie par renouvellement — dix copies au bout
        // de dix ans — et corriger le taux n'en corrigerait qu'une. C'est exactement la
        // source unique que ce rattachement existe pour offrir.
        //
        // Le clonage des conditions de PARTENAIRE (bloc 2) reste inchange : celles-la
        // appartiennent a leur piste. Leur ciblage, lui, est desormais RECOPIE tel quel
        // (cf. champsReconductibles) : rien n'est a redefinir d'un exercice a l'autre.
        //
        // Idempotent : l'adder garde un contains, reconduire deux fois ne double rien.
        foreach ($this->conditionsAgentDe($source) as $condition) {
            $cible->addConditionsPartageAgent($condition);
        }
    }

    /**
     * Identifiants des conditions d'agent a rattacher a la piste derivee — pour le SECOND
     * consommateur de la regle, le plan d'ecriture de l'assistant (MouvementAvenantBuilder),
     * qui n'instancie rien et n'a besoin que des identifiants a poser dans la collection
     * ManyToMany du plan.
     *
     * Une regle, deux consommateurs : l'ecran et Ket ne peuvent pas diverger sur ce qui
     * est reconduit.
     *
     * @return int[]
     */
    public function idsConditionsAgent(Piste $source): array
    {
        $ids = [];
        foreach ($this->conditionsAgentDe($source) as $condition) {
            if ($condition->getId() !== null) {
                $ids[] = $condition->getId();
            }
        }

        return $ids;
    }

    /**
     * Toutes les conditions d'AGENT d'une piste, par l'un OU l'autre rattachement.
     *
     * La collection partagée est le chemin normal ; le ManyToOne historique reste
     * possible si la condition a été créée depuis « conditions spéciales de partage ».
     * Les deux comptent au calcul (cf. IndicatorCalculationHelper::getCotationConditionsAgent),
     * elles doivent donc aussi suivre au renouvellement — sinon la rémunération de l'agent
     * disparaîtrait silencieusement au passage d'un exercice à l'autre.
     *
     * @return ConditionPartage[] dédoublonnées
     */
    private function conditionsAgentDe(Piste $source): array
    {
        $conditions = [];
        foreach ($source->getConditionsPartageAgent() as $condition) {
            $conditions[spl_object_id($condition)] = $condition;
        }
        foreach ($source->getConditionsPartageExceptionnelles() as $condition) {
            if ($condition->estPourAgent()) {
                $conditions[spl_object_id($condition)] = $condition;
            }
        }

        return array_values($conditions);
    }

    /**
     * RÈGLE de reconduction des conditions de partage, isolée des entités pour
     * être partagée par les DEUX chemins qui reconduisent une piste :
     *  1. le submit du formulaire de piste dérivée — reconduire() ci-dessus, qui
     *     instancie les ConditionPartage ;
     *  2. le plan d'écriture de l'assistant IA (App\Ai\Mouvement\MouvementAvenantBuilder),
     *     qui n'instancie RIEN : il a besoin des seules VALEURS pour les poser dans
     *     les éléments de collection du plan, que l'utilisateur voit avant de valider
     *     et qui entrent dans le budget en tokens.
     * Une seule règle, deux consommateurs : la reconduction ne peut pas diverger
     * entre l'écran et Ket.
     *
     * LA RÈGLE : on reconduit TOUTES les conditions de partage, sans exception —
     * aucune ne doit être perdue au passage d'un exercice à l'autre, la
     * rétrocommission d'un partenaire est un engagement contractuel. Et on les
     * reconduit À L'IDENTIQUE : même taux, même formule, MÊMES RISQUES VISÉS.
     *
     * ── LE CIBLAGE SE RECOPIE, ET C'EST NOUVEAU ─────────────────────────────────
     * Il était traduit en « effet équivalent » : une condition applicable au risque
     * de la piste devenait GÉNÉRALE, une condition non applicable devenait inerte.
     * La raison était bonne — `Risque::conditionPartage` était un ManyToOne, et
     * rattacher les mêmes risques au clone les aurait RETIRÉS de la condition
     * d'origine, cassant la rétrocommission de la police de base.
     *
     * Cette raison n'existe plus. Les risques ciblés sont passés en ManyToMany
     * (`ConditionPartage::produits`, table `condition_partage_risque`) : un risque
     * appartient désormais à autant de conditions qu'on veut. La prudence est
     * devenue une perte — l'utilisateur rouvrait chaque condition pour re-cocher
     * ses risques, exercice après exercice.
     *
     * ⚠ ET CELA CHANGE CE QUE PAIENT LES RENOUVELLEMENTS À VENIR. Une condition
     * ciblée « Incendie », applicable au risque de la piste, était reconduite en
     * GÉNÉRALE : sur la piste dérivée, elle payait sur TOUS les risques. Elle ne le
     * fera plus — elle paiera sur ce qu'elle vise, et sur rien d'autre. Ce qui est
     * déjà écrit n'est pas touché : la reconduction ne joue qu'à la création d'une
     * piste dérivée.
     *
     * `applicable` reste utile et reste rendu : une condition qui ne visait pas le
     * risque de la police de base ne le vise pas davantage sur sa suite, et
     * l'appelant en avertit plutôt que de la laisser croire active.
     *
     * `partenaire` et `produits` sont restitués comme ENTITÉS (et non comme
     * identifiants) : le chemin formulaire les injecte tels quels, le chemin IA n'en
     * retient que les id. À chacun son adaptation, la règle reste unique.
     *
     * @return array<int, array{nom: string, formule: int, seuil: float, taux: ?float,
     *                          uniteMesure: ?int, partenaire: ?Partenaire, critereRisque: int,
     *                          produits: Risque[], applicable: bool}>
     */
    public function champsReconductibles(Piste $source): array
    {
        $risqueSource = $source->getRisque();
        $champs = [];

        foreach ($source->getConditionsPartageExceptionnelles() as $condition) {
            // UNE CONDITION D'AGENT NE SE CLONE JAMAIS, quel que soit le rattachement par
            // lequel elle est arrivée là. Elle est PARTAGÉE par construction : la cloner en
            // créerait une copie par renouvellement — dix au bout de dix ans — et corriger
            // le taux n'en corrigerait qu'une. Elle est reconduite par RATTACHEMENT dans
            // reconduire(), avec le même identifiant.
            if ($condition->estPourAgent()) {
                continue;
            }

            $applicable = $condition->sappliqueAuRisque($risqueSource);
            $critereSource = $condition->getCritereRisque();

            $champs[] = [
                'nom'           => $condition->getNom() ?? 'Condition reconduite',
                'formule'       => $condition->getFormule() ?? ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                'seuil'         => $condition->getSeuil() ?? 0.0,
                'taux'          => $condition->getTaux(),
                'uniteMesure'   => $condition->getUniteMesure(),
                'partenaire'    => $condition->getPartenaire(),
                // LE CRITÈRE ET SES RISQUES, TELS QUELS : la condition dérivée vise
                // exactement ce que visait l'originale, et l'originale les garde.
                'critereRisque' => $critereSource,
                'produits'      => $condition->getProduits()->toArray(),
                'applicable'    => $applicable,
            ];
        }

        return $champs;
    }
}
