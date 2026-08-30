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

    /**
     * UNE CONDITION CIBLÉE RESTE CIBLÉE — ET C'EST L'INVERSE DE CE QUE CE TEST EXIGEAIT.
     *
     * Il attendait une reconduction en condition GÉNÉRALE, et il avait raison : sous
     * l'ancienne cardinalité, `Risque::conditionPartage` était un ManyToOne, et rattacher
     * le risque au clone l'aurait RETIRÉ de la condition d'origine — cassant la
     * rétrocommission de la police de base. On préservait donc l'EFFET plutôt que la forme.
     *
     * ⚠ MAIS CETTE TRADUCTION COÛTAIT DE L'ARGENT. « Générale » ne veut pas dire « comme
     * avant » : la condition se mettait à payer sur TOUS les risques de la piste dérivée, y
     * compris ceux qu'elle n'avait jamais visés. Depuis le passage des risques ciblés en
     * ManyToMany, la contrainte a disparu et le ciblage se recopie tel quel.
     */
    public function testUneConditionCibleeGardeSonCiblage(): void
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
        $this->assertSame(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $clone->getCritereRisque());
        $this->assertSame([$risque], $clone->getProduits()->toArray(), 'Le risque visé suit le clone.');
        // ET L'ORIGINALE NE L'A PAS PERDU : c'est ce que le ManyToMany garantit.
        $this->assertSame([$risque], $cond->getProduits()->toArray());
    }

    /**
     * AUCUNE condition n'est perdue — une rétrocommission promise à un partenaire est un
     * engagement contractuel, elle doit survivre au changement d'exercice.
     *
     * Celle qui ne s'appliquait pas au risque de la police de base est reconduite AVEC son
     * ciblage : elle ne se déclenche pas davantage sur la suite, mais elle reste lisible
     * telle qu'elle a été écrite. Ce test exigeait auparavant une forme neutre — sans
     * cible — parce que le ciblage n'était pas recopiable ; il l'est.
     */
    public function testConditionNonApplicableReconduiteAvecSonCiblage(): void
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
        $this->assertSame([$autreRisque], $clone->getProduits()->toArray(), 'Elle vise ce qu’elle visait.');
        $this->assertFalse(
            $clone->sappliqueAuRisque($risquePiste),
            'Elle ne se déclenche toujours pas : on n’invente aucune rétrocommission.',
        );
    }

    /**
     * LE CLONE REÇOIT LES RISQUES SANS LES PRENDRE.
     *
     * Ce test est né d'une contrainte disparue : `Risque::conditionPartage` était un
     * ManyToOne, et cibler un risque depuis une seconde condition le retirait de la
     * première — d'où l'interdiction de recopier le ciblage. Les risques ciblés sont
     * passés en ManyToMany ; le clone les reçoit désormais, et l'originale les garde.
     *
     * Ce qu'il vérifie n'a donc pas changé d'un mot : la police de base ne perd rien. Sa
     * raison d'être, si — il ne surveille plus une abstention, il surveille un PARTAGE.
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

    /**
     * ⚠ UNE PISTE DÉRIVÉE QUI PORTE DÉJÀ UN PARTAGE N'EST PAS RETOUCHÉE.
     *
     * Sans cette garde, rejouer la reconduction n'ajoutait pas une règle mais une COPIE :
     * le clonage des conditions de partenaire ne connaît aucun `contains`, et deux copies
     * au même taux paieraient deux fois. C'est ce qui rend la reconduction rejouable — et
     * ce qui protège un ajustement fait à la main sur la piste dérivée.
     */
    public function testUnePisteQuiPorteDejaUnPartageNEstPasRetouchee(): void
    {
        $risque = new Risque();
        $source = (new Piste())->setRisque($risque);
        $source->addConditionsPartageExceptionnelle($this->condition(30.0, ConditionPartage::CRITERE_PAS_RISQUES_CIBLES));

        $cible = (new Piste())->setRisque($risque);
        $ajustee = $this->condition(12.0, ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);
        $cible->addConditionsPartageExceptionnelle($ajustee);

        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(1, $cible->getConditionsPartageExceptionnelles(), 'Rien n’est ajouté.');
        $this->assertSame($ajustee, $cible->getConditionsPartageExceptionnelles()->first(), 'La décision tient.');
    }

    /** Et la reconduction complète, rejouée, ne double plus rien non plus. */
    public function testReconduireDeuxFoisNeDoublePasLesConditionsClonees(): void
    {
        $risque = new Risque();
        $source = (new Piste())->setRisque($risque);
        $source->addConditionsPartageExceptionnelle($this->condition(30.0, ConditionPartage::CRITERE_PAS_RISQUES_CIBLES));
        $cible = (new Piste())->setRisque($risque);

        $this->service->reconduire($source, $cible, $this->entreprise, null);
        $this->service->reconduire($source, $cible, $this->entreprise, null);

        $this->assertCount(1, $cible->getConditionsPartageExceptionnelles());
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
