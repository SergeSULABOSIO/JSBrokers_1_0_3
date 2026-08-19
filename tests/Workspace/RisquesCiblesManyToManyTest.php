<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN RISQUE DU CATALOGUE PEUT ÊTRE CIBLÉ PAR PLUSIEURS CONDITIONS.
 *
 * `ConditionPartage.produits` était un OneToMany porté par une clé étrangère sur `Risque` :
 * un risque n'appartenait donc qu'à UNE condition. Le cibler depuis une seconde le retirait
 * SILENCIEUSEMENT de la première — la condition d'origine cessait de s'appliquer sans que
 * rien ne le signale, alors qu'elle pilote des montants. C'est aussi pourquoi l'écran ne
 * proposait que de *créer* un risque, ce qui dupliquait le catalogue.
 *
 * La relation est désormais un ManyToMany (table `condition_partage_risque`). Ce test
 * verrouille les deux choses qui comptent :
 *   1. le partage est réellement possible, et non-destructif pour l'autre condition ;
 *   2. la RÈGLE MÉTIER n'a pas bougé — `sappliqueAuRisque()` rend le même verdict qu'avant
 *      sur les trois critères. C'est cette méthode que lisent le calcul de rétrocommission
 *      ET la reconduction du partage : elle ne doit pas dériver d'un pouce.
 */
class RisquesCiblesManyToManyTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-m2m-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit RisquesM2M SARL';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        // Détacher l'utilisateur AVANT de supprimer l'entreprise : connected_to_id la
        // référence, et la contrainte bloquerait la purge.
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE l FROM condition_partage_risque l
             JOIN condition_partage c ON l.condition_partage_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach (['condition_partage', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{risqueId:int, risqueAutreId:int, conditionAId:int, conditionBId:int} */
    private function semer(): array
    {
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('M2M')->setVerified(true);
        $user->setPassword('x');
        $em->persist($user);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($entreprise);
        $user->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $risque = (new Risque())->setCode('INC')->setNomComplet('Incendie')->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $autre = (new Risque())->setCode('RCA')->setNomComplet('RC automobile')->setImposable(true);
        $autre->setEntreprise($entreprise);
        $em->persist($autre);

        $conditionA = $this->condition($entreprise, 'Condition A', ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES);
        $conditionA->addProduit($risque);
        $em->persist($conditionA);

        $conditionB = $this->condition($entreprise, 'Condition B', ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES);
        $conditionB->addProduit($risque);
        $em->persist($conditionB);

        $em->flush();
        $ids = [
            'risqueId' => (int) $risque->getId(),
            'risqueAutreId' => (int) $autre->getId(),
            'conditionAId' => (int) $conditionA->getId(),
            'conditionBId' => (int) $conditionB->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function condition(Entreprise $entreprise, string $nom, int $critere): ConditionPartage
    {
        $condition = (new ConditionPartage())->setNom($nom)->setTaux(10.0)->setSeuil(0.0)
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setCritereRisque($critere);
        $condition->setEntreprise($entreprise);

        return $condition;
    }

    public function testDeuxConditionsCiblentLeMemeRisqueSansSeLeDisputer(): void
    {
        $ids = $this->semer();
        $em = $this->em();

        $a = $em->getRepository(ConditionPartage::class)->find($ids['conditionAId']);
        $b = $em->getRepository(ConditionPartage::class)->find($ids['conditionBId']);

        // LE CŒUR DE LA CORRECTION : sous l'ancienne cardinalité, rattacher le risque à B
        // l'aurait retiré de A. Ici les deux le gardent.
        self::assertCount(1, $a->getProduits(), 'La condition A garde son risque ciblé.');
        self::assertCount(1, $b->getProduits(), 'La condition B le cible aussi.');
        self::assertSame($ids['risqueId'], (int) $a->getProduits()->first()->getId());
        self::assertSame($ids['risqueId'], (int) $b->getProduits()->first()->getId());
    }

    public function testLeRisqueConnaitLesConditionsQuiLeVisent(): void
    {
        $ids = $this->semer();
        $risque = $this->em()->getRepository(Risque::class)->find($ids['risqueId']);

        // Le côté inverse, relu depuis la base : c'est lui qui alimente l'attribut
        // « Conditions de Partage » de la fiche Risque, et donc son filtre de recherche.
        self::assertCount(2, $risque->getConditionsPartage(), 'Les deux conditions sont visibles depuis le risque.');
    }

    public function testLaRegleDApplicationAuRisqueNaPasBouge(): void
    {
        $ids = $this->semer();
        $em = $this->em();

        $risque = $em->getRepository(Risque::class)->find($ids['risqueId']);
        $autre = $em->getRepository(Risque::class)->find($ids['risqueAutreId']);
        $condition = $em->getRepository(ConditionPartage::class)->find($ids['conditionAId']);

        // 1. « On ne partage QUE sur ces risques » : applicable au risque ciblé, à lui seul.
        self::assertTrue($condition->sappliqueAuRisque($risque));
        self::assertFalse($condition->sappliqueAuRisque($autre));
        self::assertFalse($condition->sappliqueAuRisque(null));

        // 2. « On ne partage PAS sur ces risques » : l'exact complément.
        $condition->setCritereRisque(ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES);
        self::assertFalse($condition->sappliqueAuRisque($risque));
        self::assertTrue($condition->sappliqueAuRisque($autre));
        self::assertTrue($condition->sappliqueAuRisque(null));

        // 3. « Aucun risque ciblé » : toujours applicable, la liste n'a pas d'objet.
        $condition->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);
        self::assertTrue($condition->sappliqueAuRisque($risque));
        self::assertTrue($condition->sappliqueAuRisque($autre));
        self::assertTrue($condition->sappliqueAuRisque(null));
    }

    public function testRetirerUnRisqueNeLeSupprimePasDuCatalogue(): void
    {
        $ids = $this->semer();
        $em = $this->em();

        $a = $em->getRepository(ConditionPartage::class)->find($ids['conditionAId']);
        $a->removeProduit($a->getProduits()->first());
        $em->flush();
        $em->clear();

        // « Retirer » détache, il ne détruit pas : le risque reste au catalogue, et l'autre
        // condition continue de le viser.
        self::assertNotNull($em->getRepository(Risque::class)->find($ids['risqueId']), 'Le risque survit au détachement.');
        self::assertCount(0, $em->getRepository(ConditionPartage::class)->find($ids['conditionAId'])->getProduits());
        self::assertCount(1, $em->getRepository(ConditionPartage::class)->find($ids['conditionBId'])->getProduits());
    }
}
