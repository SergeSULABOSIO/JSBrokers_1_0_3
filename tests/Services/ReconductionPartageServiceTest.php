<?php

namespace App\Tests\Services;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Services\ReconductionPartageService;
use PHPUnit\Framework\TestCase;

/**
 * Reconduction du partage partenaire (partenaires + conditions exceptionnelles)
 * d'une piste de base vers une piste dérivée (renouvellement / prorogation /
 * ajustement, ou nouvelle piste d'exercice issue d'un import bordereau).
 *
 * Logique pure : aucun accès base de données ni container.
 */
class ReconductionPartageServiceTest extends TestCase
{
    private ReconductionPartageService $service;
    private Entreprise $entreprise;

    protected function setUp(): void
    {
        $this->service = new ReconductionPartageService();
        $this->entreprise = new Entreprise();
    }

    private function condition(?float $taux, int $critere, ?Risque ...$cibles): ConditionPartage
    {
        $c = (new ConditionPartage())
            ->setNom('Cond ' . ($taux ?? 0))
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)
            ->setTaux($taux)
            ->setCritereRisque($critere);
        foreach ($cibles as $risque) {
            $c->addProduit($risque);
        }
        return $c;
    }

    public function testPartenairesReconduits(): void
    {
        $risque = new Risque();
        $p1 = (new Partenaire())->setNom('Alpha');

        $source = (new Piste())->setRisque($risque);
        $source->setPartenaire($p1);

        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        // Une affaire ne désigne plus qu'UN intermédiaire : la reconduction le reporte,
        // elle n'a plus de liste à parcourir.
        $this->assertSame($p1, $cible->getPartenaire());
    }

    public function testConditionGeneraleClonee(): void
    {
        $risque = new Risque();
        $partenaire = (new Partenaire())->setNom('Alpha');
        $cond = $this->condition(0.30, ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire);

        $source = (new Piste())->setRisque($risque);
        $source->addConditionsPartageExceptionnelle($cond);
        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(1, $cible->getConditionsPartageExceptionnelles());
        /** @var ConditionPartage $clone */
        $clone = $cible->getConditionsPartageExceptionnelles()->first();
        $this->assertNotSame($cond, $clone, 'La condition doit être clonée, pas partagée.');
        $this->assertSame(0.30, $clone->getTaux());
        $this->assertSame($partenaire, $clone->getPartenaire());
        $this->assertSame(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES, $clone->getCritereRisque());
        $this->assertCount(0, $clone->getProduits(), 'Le clone ne re-cible aucun risque.');
        $this->assertSame($this->entreprise, $clone->getEntreprise());
        $this->assertSame($cible, $clone->getPiste());
    }

    public function testConditionCibleeApplicableCloneeEnGenerale(): void
    {
        // Condition INCLURE ciblant précisément le risque de la piste → applicable.
        $risque = new Risque();
        $cond = $this->condition(0.45, ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $risque);

        $source = (new Piste())->setRisque($risque);
        $source->addConditionsPartageExceptionnelle($cond);
        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(1, $cible->getConditionsPartageExceptionnelles());
        /** @var ConditionPartage $clone */
        $clone = $cible->getConditionsPartageExceptionnelles()->first();
        $this->assertSame(0.45, $clone->getTaux());
        // Reconduite en condition générale : le taux effectif est préservé.
        $this->assertSame(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES, $clone->getCritereRisque());
    }

    /**
     * AUCUNE condition n'est perdue — une rétrocommission promise à un partenaire
     * est un engagement contractuel, elle doit survivre au changement d'exercice.
     * Une condition qui ne s'appliquait pas au risque est donc reconduite sous une
     * forme NEUTRE (« inclure » sans cible, jamais satisfaite) : visible et
     * ré-armable à la main, sans rien changer aux calculs.
     */
    public function testConditionCibleeNonApplicableReconduiteMaisNeutralisee(): void
    {
        $risquePiste = new Risque();
        $autreRisque = new Risque();
        $cond = $this->condition(0.45, ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $autreRisque);

        $source = (new Piste())->setRisque($risquePiste);
        $source->addConditionsPartageExceptionnelle($cond);
        $cible = (new Piste())->setRisque($risquePiste);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(1, $cible->getConditionsPartageExceptionnelles(), 'La condition est reconduite, pas abandonnée.');
        /** @var ConditionPartage $clone */
        $clone = $cible->getConditionsPartageExceptionnelles()->first();
        $this->assertSame(0.45, $clone->getTaux(), 'Le taux promis est conservé tel quel.');
        $this->assertSame(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $clone->getCritereRisque());
        $this->assertCount(0, $clone->getProduits());
        $this->assertFalse(
            $clone->sappliqueAuRisque($risquePiste),
            'Forme neutre : elle ne doit pas se déclencher, sinon on inventerait une rétrocommission.',
        );
    }

    /**
     * Les risques ciblés ne sont JAMAIS rattachés au clone : Risque::conditionPartage
     * est un ManyToOne, les y rattacher les retirerait de la condition d'origine et
     * casserait la rétrocommission de la police de base.
     */
    public function testLeCloneNeVolePasLesRisquesCiblesDeLaConditionSource(): void
    {
        $risque = new Risque();
        $cond = $this->condition(0.45, ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $risque);

        $source = (new Piste())->setRisque($risque);
        $source->addConditionsPartageExceptionnelle($cond);
        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(1, $cond->getProduits(), 'La condition source garde ses risques ciblés.');
        // Depuis le passage en ManyToMany, le rattachement se lit depuis la CONDITION,
        // côté propriétaire : le risque n'appartient plus à une condition, il est ciblé
        // par elle. (Le côté inverse n'est peuplé qu'après un flush : on ne l'interroge
        // pas ici, ce test travaillant en mémoire.)
        $this->assertTrue($cond->getProduits()->contains($risque), 'Le risque reste ciblé par la condition d’origine.');
    }

    /** Toutes les conditions comptent : deux à l'entrée, deux à la sortie. */
    public function testToutesLesConditionsSontReconduites(): void
    {
        $risquePiste = new Risque();
        $autreRisque = new Risque();
        $source = (new Piste())->setRisque($risquePiste);
        $source->addConditionsPartageExceptionnelle($this->condition(0.30, ConditionPartage::CRITERE_PAS_RISQUES_CIBLES));
        $source->addConditionsPartageExceptionnelle($this->condition(0.45, ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $autreRisque));
        $cible = (new Piste())->setRisque($risquePiste);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(2, $cible->getConditionsPartageExceptionnelles());
        $taux = array_map(
            static fn (ConditionPartage $c) => $c->getTaux(),
            $cible->getConditionsPartageExceptionnelles()->toArray(),
        );
        $this->assertEqualsCanonicalizing([0.30, 0.45], $taux, 'Aucun taux promis ne disparaît.');
    }

    public function testPartenairesIdempotents(): void
    {
        $risque = new Risque();
        $p1 = (new Partenaire())->setNom('Alpha');
        $source = (new Piste())->setRisque($risque);
        $source->setPartenaire($p1);
        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);
        // Rejouer la reconduction ne doit rien changer — l'idempotence tenait autrefois à
        // une garde `contains` ; elle tient désormais à la nature même d'une affectation.
        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertSame($p1, $cible->getPartenaire());
    }
}
