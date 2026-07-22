<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Engine\SimulatedAiEngine;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PaiementsPrimeTool;
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
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Outil « paiements_prime » sur la VRAIE base : ce que les tests unitaires ne peuvent pas
 * prouver — le chemin de relations du périmètre portefeuille
 * (tranche.cotation.piste.client.portefeuille.gestionnaire) produit bien le SQL attendu,
 * le scoping entreprise tient, et le mode ciblé restitue les signalements d'une tranche.
 */
class PaiementsPrimeToolIntegrationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-paiementsprime-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit PaiementsPrime SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit PaiementsPrime Autre SARL';

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

    private function tool(): PaiementsPrimeTool
    {
        return static::getContainer()->get(PaiementsPrimeTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        // Enfants avant parents.
        foreach (['paiement_prime', 'tranche', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'portefeuille', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email = :email',
            ['email' => self::OWNER_EMAIL]
        );
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setLicence('LIC-PP')
            ->setAdresse('1 rue des Primes')
            ->setTelephone('+243000000002')
            ->setRccm('RCCM-PP')
            ->setIdnat('IDNAT-PP')
            ->setNumimpot('IMP-PP')
            ->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Entreprise A : un portefeuille géré par le propriétaire, son client, sa tranche et
     * DEUX signalements de paiement de prime. Un second invité ne gère aucun portefeuille.
     * Entreprise B : une tranche et un signalement, jamais visibles depuis A.
     *
     * @return array{entreprise: Entreprise, gestionnaire: Invite, sansPortefeuille: Invite, tranche: Tranche}
     */
    private function seed(): array
    {
        $em = $this->em();

        $ownerUser = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit PaiementsPrime')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($ownerUser);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $gestionnaire = (new Invite())->setNom('Propriétaire PP');
        $gestionnaire->setUtilisateur($ownerUser)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($gestionnaire);

        $sansPortefeuille = (new Invite())->setNom('Collaborateur sans portefeuille');
        $sansPortefeuille->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($sansPortefeuille);

        $tranche = $this->makeChaine($entreprise, $gestionnaire, 'A', 1000.0, true);
        $this->makeSignalement($entreprise, $tranche, 'PP-A-001', 400.0, '-40 days');
        $this->makeSignalement($entreprise, $tranche, 'PP-A-002', 200.0, '-5 days');

        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $ownerUser);
        $ownerB = (new Invite())->setNom('Propriétaire B');
        $ownerB->setUtilisateur($ownerUser)->setEntreprise($entrepriseB)->setProprietaire(true);
        $em->persist($ownerB);
        $trancheB = $this->makeChaine($entrepriseB, $ownerB, 'B', 800.0, true);
        $this->makeSignalement($entrepriseB, $trancheB, 'PP-B-001', 800.0, '-2 days');

        $em->flush();
        $em->clear(); // EM partagé : on repart d'entités fraîches.

        return [
            'entreprise'       => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'gestionnaire'     => $em->getRepository(Invite::class)->find($gestionnaire->getId()),
            'sansPortefeuille' => $em->getRepository(Invite::class)->find($sansPortefeuille->getId()),
            'tranche'          => $em->getRepository(Tranche::class)->find($tranche->getId()),
        ];
    }

    /** Portefeuille → client → piste → cotation (avec prime) → tranche. */
    private function makeChaine(Entreprise $entreprise, Invite $gestionnaire, string $suffixe, float $prime, bool $avecPortefeuille): Tranche
    {
        $em = $this->em();

        $client = (new Client())->setNom('Client PP ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise);
        if ($avecPortefeuille) {
            $portefeuille = (new Portefeuille())->setNom('Portefeuille PP ' . $suffixe)->setGestionnaire($gestionnaire);
            $portefeuille->setEntreprise($entreprise);
            $em->persist($portefeuille);
            $client->setPortefeuille($portefeuille);
        }
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste PP ' . $suffixe)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test paiements de prime')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation PP ' . $suffixe)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime PP ' . $suffixe)
            ->setMontantFlatExceptionel($prime)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        $tranche = (new Tranche())
            ->setNom('Tranche PP ' . $suffixe)
            ->setPourcentage(1.0)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        return $tranche;
    }

    private function makeSignalement(Entreprise $entreprise, Tranche $tranche, string $reference, float $montant, string $quand): PaiementPrime
    {
        $paiement = (new PaiementPrime())
            ->setReference($reference)
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable($quand))
            ->setDescription('Avis de règlement ' . $reference)
            ->setTranche($tranche);
        $paiement->setEntreprise($entreprise);
        $this->em()->persist($paiement);

        return $paiement;
    }

    public function testModeCibleListeLesSignalementsDeLaTranche(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'tranche' => $tranche] = $this->seed();

        $result = $this->tool()->execute(
            ['trancheId' => $tranche->getId()],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(2, $result->data['total']);
        $this->assertSame(
            ['PP-A-002', 'PP-A-001'],
            array_column($result->data['signalements'], 'reference'),
            'Les plus récemment signalés d\'abord (id DESC), comme les listes du workspace.'
        );
        // Part SIGNALÉE = somme des PaiementPrime, calculée par le moteur d'indicateurs.
        $this->assertSame(600.0, $result->data['prime']['signalee']);
        $this->assertSame(1000.0, $result->data['prime']['totale']);
        $this->assertSame(400.0, $result->data['prime']['solde']);
    }

    public function testTrancheDUneAutreEntrepriseIntrouvable(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite] = $this->seed();
        $trancheB = $this->em()->getRepository(Tranche::class)->findOneBy(['nom' => 'Tranche PP B']);

        $result = $this->tool()->execute(
            ['trancheId' => $trancheB->getId()],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testPerimetrePortefeuilleFiltreReellementEnSql(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $gestionnaire, 'sansPortefeuille' => $autre] = $this->seed();

        // Le gestionnaire du portefeuille voit les 2 signalements : preuve que le chemin
        // tranche.cotation.piste.client.portefeuille.gestionnaire est valide et joint.
        $vuGestionnaire = $this->tool()->execute([], new AiScope($entreprise, $gestionnaire));
        $this->assertSame(AiToolResult::STATUS_OK, $vuGestionnaire->status);
        $this->assertSame(2, $vuGestionnaire->data['total']);
        $this->assertSame('Portefeuille PP A', $vuGestionnaire->data['perimetre']);
        $this->assertSame(600.0, $vuGestionnaire->data['montantPage']);

        // Un invité qui ne gère aucun portefeuille ne voit rien — et le périmètre le dit,
        // pour que l'assistant explique le zéro au lieu de l'annoncer sèchement.
        $vuAutre = $this->tool()->execute([], new AiScope($entreprise, $autre));
        $this->assertSame(0, $vuAutre->data['total']);
        $this->assertSame('aucun portefeuille', $vuAutre->data['perimetre']);

        // Élargi à l'entreprise : les signalements reviennent, ceux de l'entreprise B non.
        $vuEntreprise = $this->tool()->execute(
            ['perimetre' => PortefeuilleScope::PERIMETRE_ENTREPRISE],
            new AiScope($entreprise, $autre),
        );
        $this->assertSame(2, $vuEntreprise->data['total']);
        $this->assertSame(PortefeuilleScope::LIBELLE_ENTREPRISE, $vuEntreprise->data['perimetre']);
    }

    /**
     * Le moteur simulé retient le PREMIER outil dont le match aboutit : il ne suffit pas
     * que paiements_prime sache répondre, encore faut-il qu'aucun outil générique ne lui
     * coupe l'herbe sous le pied dans l'ordre réel du conteneur. C'est très exactement le
     * défaut constaté (Ket répondait à côté, sur la rubrique Paiements/trésorerie).
     */
    public function testLeMoteurSimuleRouteVersLOutilDedie(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite, 'tranche' => $tranche] = $this->seed();
        $engine = static::getContainer()->get(SimulatedAiEngine::class);

        $questions = [
            sprintf('Quels paiements de prime ont été signalés sur la tranche %d ?', $tranche->getId()),
            sprintf('La prime de la tranche %d a-t-elle été payée ?', $tranche->getId()),
            'Montre-moi les paiements de prime signalés',
        ];

        foreach ($questions as $question) {
            $reply = $engine->reply(new AiRequest(
                systemContext: [
                    'assistantNom'   => 'Ket',
                    'entrepriseNom'  => self::ENTREPRISE_NOM,
                    'perimetre'      => ['owner' => true, 'gestionnaire' => true, 'modules' => []],
                    'date'           => (new \DateTimeImmutable('now'))->format('Y-m-d'),
                    'objetsAttaches' => [],
                ],
                messages: [['role' => 'user', 'content' => $question]],
                scope: new AiScope($entreprise, $invite),
            ));

            $this->assertSame('paiements_prime', $reply->toolUsed, $question);
            $this->assertFalse($reply->refused, $question);
        }

        // L'ACTION reste à l'outil d'action : la lecture ne lui vole pas la question.
        $action = $engine->reply(new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => self::ENTREPRISE_NOM,
                'perimetre'      => ['owner' => true, 'gestionnaire' => true, 'modules' => []],
                'date'           => (new \DateTimeImmutable('now'))->format('Y-m-d'),
                'objetsAttaches' => [],
            ],
            messages: [['role' => 'user', 'content' => sprintf('Signale le paiement de la prime de la tranche %d', $tranche->getId())]],
            scope: new AiScope($entreprise, $invite),
        ));
        $this->assertSame('signaler_paiement_prime', $action->toolUsed);
    }

    public function testRattachementClientEtPeriodeFiltrentEnSql(): void
    {
        ['entreprise' => $entreprise, 'gestionnaire' => $invite] = $this->seed();
        $client = $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'Client PP A']);

        $parClient = $this->tool()->execute(
            ['lieA' => ['entite' => 'Client', 'id' => $client->getId()]],
            new AiScope($entreprise, $invite),
        );
        $this->assertSame(AiToolResult::STATUS_OK, $parClient->status);
        $this->assertSame(2, $parClient->data['total'], 'Chemin tranche.cotation.piste.client valide.');

        // Fenêtre de règlement : seul le signalement récent (-5 j) doit remonter.
        $recent = $this->tool()->execute(
            ['du' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d')],
            new AiScope($entreprise, $invite),
        );
        $this->assertSame(1, $recent->data['total']);
        $this->assertSame('PP-A-002', $recent->data['items'][0]['reference']);
    }
}
