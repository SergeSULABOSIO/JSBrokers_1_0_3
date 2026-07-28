<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\SignalerPaiementPrimeTool;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Outil « signaler_paiement_prime » sur la VRAIE base : ce que les tests unitaires
 * ne peuvent pas prouver — que la conversion en outil d'écriture prépare RÉELLEMENT
 * un plan validable (create PaiementPrime rattaché à la tranche), avec le montant par
 * défaut = solde de prime restant, et que l'allowlist + la gouvernance d'accès
 * (PaiementPrime → droit Tranche) laissent passer la préparation.
 *
 * WebTestCase : le PaiementPrimeType construit (via la collection « preuves » →
 * DocumentType) un champ autocomplete qui scope sur l'utilisateur connecté
 * (getConnectedTo) — on se connecte donc comme le ferait le chat authentifié réel.
 */
class SignalerPaiementPrimeToolIntegrationTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-signalerprime-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit SignalerPrime SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown(); // le kernel d'un test précédent peut être resté démarré.
        $this->client = static::createClient();
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

    private function tool(): SignalerPaiementPrimeTool
    {
        return static::getContainer()->get(SignalerPaiementPrimeTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['paiement_prime', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        // Dénoue la FK circulaire utilisateur.connected_to_id ↔ entreprise avant suppression.
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Chaîne portefeuille → client → piste → cotation (prime 1000, tranche 100 %) →
     * tranche, sans aucun signalement : le solde restant est donc 1000.
     *
     * @return array{entreprise: Entreprise, invite: Invite, tranche: Tranche, owner: Utilisateur}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit SignalerPrime')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-SP')
            ->setAdresse('1 rue des Primes')
            ->setTelephone('+243000000003')
            ->setRccm('RCCM-SP')
            ->setIdnat('IDNAT-SP')
            ->setNumimpot('IMP-SP')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        $owner->setConnectedTo($entreprise); // l'autocomplete du FormType scope sur l'entreprise active.

        $invite = (new Invite())->setNom('Propriétaire SP');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille SP')->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $client = (new Client())->setNom('Client SP')->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste SP')
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test signaler prime')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation SP')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime SP')
            ->setMontantFlatExceptionel(1000.0)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        $tranche = (new Tranche())
            ->setNom('Tranche SP')
            ->setPourcentage(100.0) // 100 % en POINTS (convention pourcentage)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        $em->flush();
        $em->clear();

        $owner = $em->getRepository(Utilisateur::class)->find($owner->getId());
        $this->client->loginUser($owner); // chat authentifié : le FormType a un utilisateur courant.

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'invite'     => $em->getRepository(Invite::class)->find($invite->getId()),
            'tranche'    => $em->getRepository(Tranche::class)->find($tranche->getId()),
            'owner'      => $owner,
        ];
    }

    public function testPrepareUnPlanCreateAvecMontantParDefaut(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'tranche' => $tranche] = $this->seed();

        $result = $this->tool()->execute(
            ['trancheId' => $tranche->getId()],
            new AiScope($entreprise, $invite),
        );

        // Un plan validable est réellement préparé (plus d'ouverture de formulaire).
        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['pret'], 'Un plan prêt à valider doit être préparé.');
        $this->assertSame(PlanEnAttente::ACTION_REVUE, $result->uiAction['type']);

        // L'opération porte bien sur la sous-entité PaiementPrime, en création.
        $ligne = $result->data['plan'][0];
        $this->assertSame('PaiementPrime', $ligne['entite']);
        $this->assertSame('create', $ligne['op']);
        $this->assertSame('Paiements de prime', $ligne['libelle'], 'Libellé lisible (hors MAP) attendu.');

        // Montant par défaut = solde de prime restant (prime 1000 × 100 % − 0 signalé).
        $champs = $result->uiAction['plan'][0]['fields'];
        $this->assertSame($tranche->getId(), $champs['tranche']);
        $this->assertEqualsWithDelta(1000.0, (float) $champs['montant'], 0.001);
        $this->assertArrayHasKey('paidAt', $champs);
        $this->assertStringStartsWith('PRIME-', (string) $champs['reference']);
    }

    public function testMontantEtDateFournisSontRespectes(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'tranche' => $tranche] = $this->seed();

        $result = $this->tool()->execute(
            ['trancheId' => $tranche->getId(), 'montant' => 400, 'paidAt' => '2026-07-20'],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['pret']);
        $champs = $result->uiAction['plan'][0]['fields'];
        $this->assertEqualsWithDelta(400.0, (float) $champs['montant'], 0.001, 'Montant partiel respecté.');
        $this->assertStringStartsWith('2026-07-20', (string) $champs['paidAt']);
    }

    public function testTrancheDUneAutreEntrepriseIntrouvable(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $result = $this->tool()->execute(
            ['trancheId' => 999999999],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
        $this->assertNull($result->uiAction);

        // Aucun PaiementPrime n'a été créé (dry-run : rien n'est écrit).
        $this->assertSame(0, (int) $this->em()->getRepository(PaiementPrime::class)
            ->createQueryBuilder('p')->select('COUNT(p.id)')
            ->join('p.entreprise', 'e')->where('e.nom = :nom')->setParameter('nom', self::ENTREPRISE_NOM)
            ->getQuery()->getSingleScalarResult());
    }
}
