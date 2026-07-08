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
        $p2 = (new Partenaire())->setNom('Beta');

        $source = (new Piste())->setRisque($risque);
        $source->addPartenaire($p1);
        $source->addPartenaire($p2);

        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(2, $cible->getPartenaires());
        $this->assertTrue($cible->getPartenaires()->contains($p1));
        $this->assertTrue($cible->getPartenaires()->contains($p2));
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

    public function testConditionCibleeNonApplicableIgnoree(): void
    {
        // Condition INCLURE ciblant un AUTRE risque → sans effet sur la piste → non reconduite.
        $risquePiste = new Risque();
        $autreRisque = new Risque();
        $cond = $this->condition(0.45, ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $autreRisque);

        $source = (new Piste())->setRisque($risquePiste);
        $source->addConditionsPartageExceptionnelle($cond);
        $cible = (new Piste())->setRisque($risquePiste);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(0, $cible->getConditionsPartageExceptionnelles());
    }

    public function testPartenairesIdempotents(): void
    {
        $risque = new Risque();
        $p1 = (new Partenaire())->setNom('Alpha');
        $source = (new Piste())->setRisque($risque);
        $source->addPartenaire($p1);
        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);
        // Un second appel ne doit pas dupliquer le partenaire déjà présent.
        foreach ($source->getPartenaires() as $p) {
            $cible->addPartenaire($p);
        }

        $this->assertCount(1, $cible->getPartenaires());
    }
}
