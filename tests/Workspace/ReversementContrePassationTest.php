<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\PlanComptable;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Entity\Utilisateur;
use App\Service\Retro\ReversementIntangible;
use App\Service\Workspace\SuppressionEnCascade;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\Canvas\Indicator\ReversementRetroAgentIndicatorStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CONTRE-PASSER UN VIREMENT — la seule correction d'un reversement (Serge, 2026-10-30).
 *
 * Ce que ce test tient, surface par surface :
 *  - l'ÉCRAN : une ligne de montant opposé par ligne du virement, reliée à celle qu'elle
 *    annule, sous une référence « CP-… » ; le net du virement revient à zéro ; une seconde
 *    fois, ou sur une contre-passation, est refusé en 409 qui explique ;
 *  - la LISTE : la ligne dit son état, et l'action n'est offerte qu'au virement annulable ;
 *  - le JOURNAL : l'annulation s'écrit à l'envers (D trésorerie / C charge), jamais par un
 *    débit négatif ;
 *  - la SUPPRESSION EN CHAÎNE : un plan qui emporterait un reversement est refusé ;
 *  - KET : le même acte par le moteur d'écriture, et le même refus de modifier un montant.
 */
class ReversementContrePassationTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-cp-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Contre-passation SARL';
    private const LOT = 'VIR-CP-A';

    private KernelBrowser $client;

    protected function setUp(): void
    {
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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        // Une contre-passation retient l'original (RESTRICT) : le lien se dénoue d'abord.
        $conn->executeStatement(
            'UPDATE reversement_retro_agent t JOIN entreprise e ON t.entreprise_id = e.id
             SET t.contre_passation_de_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach (['document', 'reversement_retro_agent', 'partenaire', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur u JOIN entreprise e ON u.connected_to_id = e.id
             SET u.connected_to_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un virement A de trois lignes (10, 20, 30) à un agent, et un versement isolé B (7).
     *
     * @return array{aIds: int[], bId: int, entreprise: int, gestionnaire: int, agent: int, owner: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Contre-passation')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice Contre-passation')->setProprietaire(false)->setEntreprise($entreprise);
        $em->persist($agent);
        $em->flush();

        $ecrire = static function (float $montant, ?string $lot) use ($em, $entreprise, $agent): ReversementRetroAgent {
            $r = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('2026-08-01'))
                ->setReference($lot ?? 'SOLO-CP')
                ->setLotReference($lot);
            $r->setEntreprise($entreprise)->setInvite($agent);
            $em->persist($r);

            return $r;
        };

        $a = [$ecrire(10.0, self::LOT), $ecrire(20.0, self::LOT), $ecrire(30.0, self::LOT)];
        $b = $ecrire(7.0, null);
        $em->flush();

        $s = [
            'aIds' => array_map(static fn (ReversementRetroAgent $r) => $r->getId(), $a),
            'bId' => $b->getId(),
            'entreprise' => $entreprise->getId(),
            'gestionnaire' => $gestionnaire->getId(),
            'agent' => $agent->getId(),
            'owner' => $owner->getId(),
        ];
        $em->clear();

        return $s;
    }

    private function contrePasser(int $id): array
    {
        $this->client->loginUser($this->em()->find(Utilisateur::class, $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL])->getId()));
        $this->client->request('POST', '/admin/retro-agent/reversement/' . $id . '/contre-passer', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return ReversementRetroAgent[] les lignes qui annulent le virement A */
    private function annulations(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(ReversementRetroAgent::class)->findBy(['lotReference' => 'CP-' . self::LOT], ['id' => 'ASC']);
    }

    public function testLeVirementEntierEstAnnuleParDesLignesOpposees(): void
    {
        $s = $this->semer();

        $reponse = $this->contrePasser($s['aIds'][1]); // depuis n'importe quelle ligne du virement

        self::assertResponseIsSuccessful();
        self::assertSame(3, $reponse['lignes'] ?? null);
        self::assertSame(-60.0, (float) ($reponse['total'] ?? 0));

        $annulations = $this->annulations();
        self::assertCount(3, $annulations, 'Une ligne opposée par ligne du virement.');
        $annulees = [];
        foreach ($annulations as $annulation) {
            self::assertTrue($annulation->estUneContrePassation());
            self::assertSame('CP-' . self::LOT, $annulation->getReference());
            self::assertSame(-$annulation->getContrePassationDe()->getMontant(), $annulation->getMontant());
            self::assertSame($s['agent'], $annulation->getAgent()?->getId(), 'Même bénéficiaire.');
            self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $annulation->getPaidAt()?->format('Y-m-d'));
            $annulees[] = $annulation->getContrePassationDe()->getId();
        }
        sort($annulees);
        self::assertSame($s['aIds'], $annulees, 'Chaque ligne du virement, et elles seules.');

        $net = (float) $this->em()->getConnection()->fetchOne(
            'SELECT SUM(montant) FROM reversement_retro_agent WHERE agent_id = :a AND (lot_reference IN (:l, :cp))',
            ['a' => $s['agent'], 'l' => self::LOT, 'cp' => 'CP-' . self::LOT],
        );
        self::assertEqualsWithDelta(0.0, $net, 0.001, 'Le net du virement revient à zéro : le montant redevient dû.');
        self::assertNotNull($this->em()->getRepository(ReversementRetroAgent::class)->find($s['aIds'][0]), 'L’original reste lisible.');
    }

    public function testUneSecondeContrePassationEstRefusee(): void
    {
        $s = $this->semer();
        $this->contrePasser($s['aIds'][0]);

        $reponse = $this->contrePasser($s['aIds'][2]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ReversementIntangible::DEJA_CONTRE_PASSE, $reponse['message'] ?? null);
        self::assertCount(3, $this->annulations(), 'Rien de plus n’a été écrit.');
    }

    public function testUneContrePassationNeSeContrePassePas(): void
    {
        $s = $this->semer();
        $this->contrePasser($s['aIds'][0]);
        $annulation = $this->annulations()[0];

        $reponse = $this->contrePasser($annulation->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ReversementIntangible::CONTRE_PASSATION_NON_ANNULABLE, $reponse['message'] ?? null);
    }

    /** La ligne dit son état, et l'action n'est offerte qu'au virement annulable. */
    public function testLaLigneDitSonEtatEtLActionSeRetire(): void
    {
        $s = $this->semer();
        $strategie = static::getContainer()->get(ReversementRetroAgentIndicatorStrategy::class);
        $repo = $this->em()->getRepository(ReversementRetroAgent::class);

        $avant = $strategie->calculate($repo->find($s['aIds'][0]));
        self::assertTrue($avant['virementAnnulable']);
        self::assertNull($avant['etatComptable']);

        $this->contrePasser($s['aIds'][0]);
        $this->annulations();
        $strategie = static::getContainer()->get(ReversementRetroAgentIndicatorStrategy::class);
        $repo = $this->em()->getRepository(ReversementRetroAgent::class);

        $original = $strategie->calculate($repo->find($s['aIds'][0]));
        self::assertFalse($original['virementAnnulable']);
        self::assertSame('Contre-passé', $original['etatComptable']);

        $annulation = $strategie->calculate($repo->findOneBy(['lotReference' => 'CP-' . self::LOT]));
        self::assertFalse($annulation['virementAnnulable']);
        self::assertSame('Contre-passation', $annulation['etatComptable']);

        self::assertTrue($strategie->calculate($repo->find($s['bId']))['virementAnnulable'], 'Le voisin reste annulable.');
    }

    /** Le journal écrit l'annulation à l'envers, pour son montant positif. */
    public function testLeJournalEcritLAnnulationALEnvers(): void
    {
        $s = $this->semer();
        $this->contrePasser($s['aIds'][0]);
        $this->em()->clear();

        $journal = static::getContainer()->get(CourtierEcritureComptableService::class)
            ->ecritures($this->em()->find(Entreprise::class, $s['entreprise']));

        $retros = array_values(array_filter($journal, static fn (array $e) => ($e['type'] ?? '') === 'retro_agent'));
        $annulation = array_values(array_filter($retros, static fn (array $e) => str_starts_with($e['libelle'], 'Contre-passation')));
        self::assertCount(1, $annulation, 'Une seule écriture pour le virement annulé, comme pour le virement.');

        [$debit, $credit] = $annulation[0]['lignes'];
        self::assertSame(PlanComptable::CAISSE, $debit['compte'], 'La trésorerie est débitée : l’argent revient.');
        self::assertEqualsWithDelta(60.0, $debit['debit'], 0.001);
        self::assertSame(PlanComptable::COMMISSIONS_PERSONNEL, $credit['compte'], 'La charge est créditée.');
        self::assertEqualsWithDelta(60.0, $credit['credit'], 0.001);
        foreach ($retros as $ecriture) {
            foreach ($ecriture['lignes'] as $ligne) {
                self::assertGreaterThanOrEqual(0.0, $ligne['debit'], 'Jamais de débit négatif.');
                self::assertGreaterThanOrEqual(0.0, $ligne['credit'], 'Jamais de crédit négatif.');
            }
        }
    }

    /** Supprimer le bénéficiaire emporterait ou modifierait ses reversements : refusé. */
    public function testLaSuppressionEnChaineNEmportePasUnReversement(): void
    {
        $s = $this->semer();

        $plan = static::getContainer()->get(SuppressionEnCascade::class)
            ->planifier($this->em()->find(Invite::class, $s['agent']));

        self::assertNotSame([], $plan->refus, 'Le plan est refusé.');
        self::assertStringContainsString(ReversementIntangible::MOTIF_SUPPRESSION, implode(' ', $plan->refus));
    }

    /**
     * UN VIREMENT VA À UN SEUL BÉNÉFICIAIRE (revue du 2026-10-31). Deux virements saisis sous la
     * même clé de lot pour un agent et un partenaire ne se fondent pas : contre-passer celui du
     * partenaire laisse celui de l'agent intact.
     */
    public function testUnLotPartageParDeuxBeneficiairesNeSeFondPas(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $entreprise = $em->find(Entreprise::class, $s['entreprise']);
        $partenaire = (new \App\Entity\Partenaire())->setNom('Partenaire Contre-passation')->setPart(10);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);
        $ligne = (new ReversementRetroAgent())->setPartenaire($partenaire)->setMontant(5.0)
            ->setPaidAt(new \DateTimeImmutable('2026-08-01'))->setReference(self::LOT)->setLotReference(self::LOT);
        $ligne->setEntreprise($entreprise)->setInvite($em->find(Invite::class, $s['gestionnaire']));
        $em->persist($ligne);
        $em->flush();
        $idPartenaire = $ligne->getId();

        $reponse = $this->contrePasser($idPartenaire);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $reponse['lignes'] ?? null, 'Seule la ligne du partenaire est annulée.');
        $this->em()->clear();
        foreach ($s['aIds'] as $id) {
            self::assertNull(
                $this->em()->getRepository(ReversementRetroAgent::class)->findOneBy(['contrePassationDe' => $id]),
                'Le virement de l’agent, sous la même clé, n’a pas été touché.',
            );
        }
    }

    /** La clé d'un nouveau lot est posée par le serveur : unique, jamais la référence saisie. */
    public function testLaCleDeLotEstUniqueEtNeReprendPasLaReference(): void
    {
        $lots = static::getContainer()->get(\App\Service\Retro\LotDeVersement::class);
        $a = $lots->nouvelleReferenceDeLot();
        $b = $lots->nouvelleReferenceDeLot();

        self::assertNotSame($a, $b);
        self::assertStringStartsWith('LOT-', $a);
    }

    /** KET ne crée ni un versement négatif, ni une ligne dans un virement annulé. */
    public function testKetNeCreePasDeVersementNegatifNiDansUnVirementAnnule(): void
    {
        $s = $this->semer();
        $this->contrePasser($s['aIds'][0]);
        $em = $this->em();
        $em->clear();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['gestionnaire']));

        $negatif = $service->analyserOperation(new MutationOperation('create', 'ReversementRetroAgent', null, [
            'agent' => $s['agent'], 'montant' => -50, 'paidAt' => '2026-10-01', 'reference' => 'FAUX-CP',
        ]), $scope);
        self::assertSame('bloque', $negatif['statut']);
        self::assertContains(ReversementIntangible::MONTANT_POSITIF, $negatif['impacts']);

        foreach ([self::LOT, 'CP-' . self::LOT] as $lotClos) {
            $greffe = $service->analyserOperation(new MutationOperation('create', 'ReversementRetroAgent', null, [
                'agent' => $s['agent'], 'montant' => 5, 'paidAt' => '2026-10-01', 'reference' => 'X', 'lotReference' => $lotClos,
            ]), $scope);
            self::assertSame('bloque', $greffe['statut'], $lotClos);
            self::assertContains(ReversementIntangible::LOT_CLOS, $greffe['impacts']);
        }
    }

    /** KET : le même acte par le moteur d'écriture, et le même refus de modifier un montant. */
    public function testKetContrePasseParLeMemeServiceEtNeModifiePasUnMontant(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['gestionnaire']));

        $montant = $service->analyserOperation(new MutationOperation('edit', 'ReversementRetroAgent', $s['bId'], ['montant' => 8]), $scope);
        self::assertSame('bloque', $montant['statut']);
        self::assertContains(ReversementIntangible::MOTIF, $montant['impacts']);

        $melange = $service->analyserOperation(new MutationOperation('edit', 'ReversementRetroAgent', $s['bId'], ['contrePasser' => true, 'description' => 'x']), $scope);
        self::assertSame('bloque', $melange['statut'], 'La contre-passation se fait seule.');

        $acte = new MutationOperation('edit', 'ReversementRetroAgent', $s['bId'], ['contrePasser' => true]);
        $analyse = $service->analyserOperation($acte, $scope);
        self::assertTrue($analyse['ok'], json_encode($analyse));
        self::assertContains(ReversementIntangible::ANNONCE_CONTRE_PASSATION, $analyse['impacts']);

        $service->executer($acte, $scope, $em->find(Utilisateur::class, $s['owner']), null, false);
        $em->clear();
        $annulation = $em->getRepository(ReversementRetroAgent::class)->findOneBy(['reference' => 'CP-SOLO-CP']);
        self::assertNotNull($annulation);
        self::assertSame(-7.0, $annulation->getMontant());

        $encore = $service->analyserOperation($acte, new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['gestionnaire'])));
        self::assertSame('bloque', $encore['statut']);
        self::assertContains(ReversementIntangible::DEJA_CONTRE_PASSE, $encore['impacts']);

        $creation = $service->analyserOperation(new MutationOperation('create', 'ReversementRetroAgent', null, ['contrePassationDe' => $s['aIds'][0], 'montant' => -10]), $scope);
        self::assertSame('bloque', $creation['statut'], 'Une contre-passation ne naît que par son chemin.');
    }
}
