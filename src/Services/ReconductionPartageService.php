<?php

namespace App\Services;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
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

            $clone->setEntreprise($entreprise);
            $clone->setInvite($invite);
            // createdAt / updatedAt sont posés par le PrePersist de AuditableTrait.

            $cible->addConditionsPartageExceptionnelle($clone);
        }
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
     * rétrocommission d'un partenaire est un engagement contractuel. Ce qui est
     * préservé de chacune, c'est son EFFET sur le risque de la piste :
     *  - condition APPLICABLE au risque => reconduite en condition GÉNÉRALE
     *    (« pas de risques ciblés ») : elle continue de s'appliquer, au même taux ;
     *  - condition NON applicable => reconduite sous une forme NEUTRE (« inclure »
     *    sans aucun risque ciblé, donc jamais satisfaite) : elle reste visible et
     *    ré-armable à la main sur la piste dérivée, sans rien changer aux calculs.
     *
     * Pourquoi ne pas recopier les risques ciblés tels quels : Risque::conditionPartage
     * est un ManyToOne — un risque n'appartient qu'à UNE condition. Rattacher les
     * mêmes risques au clone les RETIRERAIT de la condition d'origine, cassant la
     * rétrocommission de la police de base. Le ciblage est donc traduit en effet
     * équivalent, et `ciblageAplati` le signale à l'appelant, qui en avertit
     * l'utilisateur plutôt que de le laisser le découvrir.
     *
     * `partenaire` est restitué comme ENTITÉ (et non comme identifiant) : le
     * chemin formulaire l'injecte tel quel par son setter, le chemin IA n'en
     * retient que l'id. À chacun son adaptation, la règle reste unique.
     *
     * @return array<int, array{nom: string, formule: int, seuil: float, taux: ?float,
     *                          uniteMesure: ?int, partenaire: ?Partenaire, critereRisque: int,
     *                          applicable: bool, ciblageAplati: bool}>
     */
    public function champsReconductibles(Piste $source): array
    {
        $risqueSource = $source->getRisque();
        $champs = [];

        foreach ($source->getConditionsPartageExceptionnelles() as $condition) {
            $applicable = $condition->sappliqueAuRisque($risqueSource);
            $critereSource = $condition->getCritereRisque();

            $champs[] = [
                'nom'           => $condition->getNom() ?? 'Condition reconduite',
                'formule'       => $condition->getFormule() ?? ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
                'seuil'         => $condition->getSeuil() ?? 0.0,
                'taux'          => $condition->getTaux(),
                'uniteMesure'   => $condition->getUniteMesure(),
                'partenaire'    => $condition->getPartenaire(),
                // Applicable => générale (s'applique) ; sinon => « inclure » sans
                // cible, forme neutre qui ne se déclenche jamais.
                'critereRisque' => $applicable
                    ? ConditionPartage::CRITERE_PAS_RISQUES_CIBLES
                    : ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES,
                'applicable'    => $applicable,
                // Le ciblage n'a pas pu être recopié à l'identique (cf. docblock).
                'ciblageAplati' => $critereSource !== ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
            ];
        }

        return $champs;
    }
}
