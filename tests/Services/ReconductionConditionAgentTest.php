<?php

namespace App\Tests\Services;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Services\ReconductionPartageService;
use PHPUnit\Framework\TestCase;

/**
 * AU RENOUVELLEMENT, LA CONDITION D'UN AGENT EST RATTACHÉE — PAS CLONÉE.
 *
 * Deux traitements opposés cohabitent volontairement dans le même service :
 *
 *  - une condition de PARTENAIRE appartient à sa piste. Elle est CLONÉE sur la piste
 *    dérivée, et son ciblage par risque y est réinterprété (une condition applicable
 *    devient générale, une inapplicable devient neutre mais ré-armable). Ce comportement
 *    est verrouillé par ReconductionPartageServiceTest et ne doit pas bouger.
 *
 *  - une condition d'AGENT est partagée par construction : « prime apporteur 15 % » est
 *    écrite une fois et sert à toutes ses affaires. La cloner en créerait une copie par
 *    renouvellement — dix copies au bout de dix ans — et corriger le taux n'en corrigerait
 *    qu'une. On rattache donc la MÊME entité, avec le même identifiant.
 *
 * Le test travaille en mémoire : la règle est une règle d'objets, pas de base.
 */
class ReconductionConditionAgentTest extends TestCase
{
    private ReconductionPartageService $service;
    private Entreprise $entreprise;
    private Risque $risque;

    protected function setUp(): void
    {
        $this->service = new ReconductionPartageService();
        $this->entreprise = new Entreprise();
        $this->risque = (new Risque())->setCode('RC')->setNomComplet('Risque')->setDescription('Risque')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
    }

    private function piste(string $nom): Piste
    {
        $piste = (new Piste())->setNom($nom)->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque')->setExercice((int) date('Y'))
            ->setRisque($this->risque);
        $piste->setEntreprise($this->entreprise);

        return $piste;
    }

    private function conditionAgent(Invite $agent, float $taux): ConditionPartage
    {
        $condition = (new ConditionPartage())->setNom('Prime apporteur')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux($taux)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($agent);
        $condition->setEntreprise($this->entreprise);

        return $condition;
    }

    public function testLaMemeConditionSuitLaPisteDeriveeSansEtreDupliquee(): void
    {
        $agent = (new Invite())->setNom('Alice');
        $condition = $this->conditionAgent($agent, 15.0);

        $source = $this->piste('Police 2025');
        $source->addConditionsPartageAgent($condition);
        $cible = $this->piste('Police 2026');

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        self::assertCount(1, $cible->getConditionsPartageAgent());
        self::assertSame(
            $condition,
            $cible->getConditionsPartageAgent()->first(),
            'C\'est la MÊME instance : modifier son taux met à jour toutes les affaires rattachées.',
        );
    }

    public function testLesConditionsDePartenaireRestentClonees(): void
    {
        // Non-régression du comportement historique : celles-là appartiennent à leur piste.
        $partenaire = (new Partenaire())->setNom('Partenaire')->setPart(10.0);
        $condition = (new ConditionPartage())->setNom('Générale du partenaire')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire);
        $condition->setEntreprise($this->entreprise);

        $source = $this->piste('Police 2025');
        $source->addConditionsPartageExceptionnelle($condition);
        $cible = $this->piste('Police 2026');

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $clone = $cible->getConditionsPartageExceptionnelles()->first();
        self::assertNotSame($condition, $clone, 'Une condition de partenaire est bien recopiée.');
        self::assertSame(20.0, $clone->getTaux(), 'Au même taux.');
        self::assertNull($clone->getAgent(), 'Et sans bénéficiaire interne.');
    }

    public function testReconduireDeuxFoisNeDoublePasLeRattachement(): void
    {
        $agent = (new Invite())->setNom('Alice');
        $source = $this->piste('Police 2025');
        $source->addConditionsPartageAgent($this->conditionAgent($agent, 15.0));
        $cible = $this->piste('Police 2026');

        $this->service->reconduire($source, $cible, $this->entreprise, null);
        $this->service->reconduire($source, $cible, $this->entreprise, null);

        self::assertCount(1, $cible->getConditionsPartageAgent(), 'L\'adder garde un contains : idempotent.');
    }

    public function testPlusieursAgentsSuiventTousLaPolice(): void
    {
        $source = $this->piste('Police 2025');
        $source->addConditionsPartageAgent($this->conditionAgent((new Invite())->setNom('Alice'), 15.0));
        $source->addConditionsPartageAgent($this->conditionAgent((new Invite())->setNom('Bruno'), 10.0));
        $cible = $this->piste('Police 2026');

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        self::assertCount(2, $cible->getConditionsPartageAgent());
    }

    public function testLaRegleEstLaMemePourLEcranEtPourKet(): void
    {
        // Le plan d'écriture de l'assistant ne pose pas d'entités : il a besoin des
        // identifiants. Les deux chemins doivent désigner exactement les mêmes conditions —
        // c'est ce que garantit le passage par idsConditionsAgent().
        $agent = (new Invite())->setNom('Alice');
        $condition = $this->conditionAgent($agent, 15.0);
        // Sans identifiant (entité non persistée), la liste destinée au plan est vide :
        // rien à rattacher par référence, et surtout aucun `null` glissé dans le plan.
        $source = $this->piste('Police 2025');
        $source->addConditionsPartageAgent($condition);

        self::assertSame([], $this->service->idsConditionsAgent($source));
    }
}
