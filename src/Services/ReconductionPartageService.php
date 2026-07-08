<?php

namespace App\Services;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;

/**
 * Reconduit le schéma de partage de revenu avec le(s) partenaire(s) d'une piste
 * de base vers une piste dérivée (renouvellement, prorogation, ajustement, ou
 * nouvelle piste d'exercice issue d'un import bordereau).
 *
 * Objectif métier : garantir que le partenaire et les mêmes proportions de partage
 * (rétrocommission) soient reconduits à l'identique sur l'avenant suivant.
 *
 * Deux porteurs du partage sur une piste :
 *  1. les partenaires associés (Piste::partenaires) ;
 *  2. les conditions de partage exceptionnelles (Piste::conditionsPartageExceptionnelles).
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
        // 1. Partenaires — idempotent (garde contains via addPartenaire).
        foreach ($source->getPartenaires() as $partenaire) {
            $cible->addPartenaire($partenaire);
        }

        // 2. Conditions de partage exceptionnelles.
        // Une piste porte un unique risque : on ne reconduit que les conditions qui
        // s'appliquaient effectivement au risque de la piste source, en les clonant
        // comme conditions générales (sans ciblage de risque) — décision « sans ciblage ».
        $risqueSource = $source->getRisque();
        foreach ($source->getConditionsPartageExceptionnelles() as $condition) {
            if (!$condition->sappliqueAuRisque($risqueSource)) {
                continue; // Sans effet sur la rétrocommission de l'avenant de base.
            }

            $clone = (new ConditionPartage())
                ->setNom($condition->getNom() ?? 'Condition reconduite')
                ->setFormule($condition->getFormule() ?? ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
                ->setSeuil($condition->getSeuil() ?? 0.0)
                ->setTaux($condition->getTaux())
                ->setUniteMesure($condition->getUniteMesure())
                ->setPartenaire($condition->getPartenaire())
                // Reconduite en condition générale : le taux effectif est préservé
                // pour le risque unique de la piste dérivée, sans re-cibler de risque.
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);

            $clone->setEntreprise($entreprise);
            $clone->setInvite($invite);
            // createdAt / updatedAt sont posés par le PrePersist de AuditableTrait.

            $cible->addConditionsPartageExceptionnelle($clone);
        }
    }
}
