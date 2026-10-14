<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Service\Workspace\MutationException;
use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Form\PisteType;
use App\Service\Avenant\DefautsDeSouscription;
use App\Service\Workspace\OrigineEcriture;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UNE GARANTIE CRÉÉE, RECONDUITE OU ÉTENDUE EXIGE UNE PRIME CHIFFRÉE — garanti par le serveur.
 *
 * Le premier avenant d'une cotation exige une cotation chiffrée quand le type de sa piste crée,
 * reconduit ou étend la garantie : souscription, prorogation, renouvellement. L'incorporation
 * (ajout à prime nulle possible, santé groupe forfaitaire, flotte régularisée), l'annulation et
 * la résiliation sont exemptées. Un avenant SUIVANT, sur une cotation déjà souscrite, reste
 * toujours permis. Une seule table (DefautsDeSouscription::PRIME_EXIGEE), une seule garde
 * (ChampsObligatoiresInspector::incoherencesMetier), pour l'écran, Ket et les mouvements ;
 * la reprise de données en est exemptée par un drapeau qu'elle seule peut poser.
 */
class AvenantPrimeExigeeTest extends WebTestCase
{
    private const EMAIL = 'phpunit-prime-exigee@test.local';
    private const CABINET = 'PHPUnit Prime Exigee SARL';
    private const VOISIN = 'PHPUnit Prime Exigee Voisin SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
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

    /** Tout ce que porte le cabinet de test, quelle que soit la table : le chemin d'écriture en crée. */
    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        foreach ([self::CABINET, self::VOISIN] as $nom) {
            $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [$nom]);
            if ($id === false) {
                continue;
            }
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    /** @return array{owner: int, entreprise: int, invite: int, client: int} */
    private function semerLeCabinet(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Prime exigée')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000041')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);
        $client = (new Client())->setNom('Client prime exigée')->setExonere(false);
        $client->setEntreprise($cabinet);
        $em->persist($client);
        $em->flush();
        $this->client->loginUser($owner);

        return ['owner' => (int) $owner->getId(), 'entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId(), 'client' => (int) $client->getId()];
    }

    /** Une piste du type donné et sa cotation ; chiffrée si `$prime` > 0 ; déjà souscrite si demandé. */
    private function cotation(array $s, int $type, float $prime = 0.0, bool $dejaSouscrite = false): int
    {
        $em = $this->em();
        $cabinet = $em->find(Entreprise::class, $s['entreprise']);
        $invite = $em->find(Invite::class, $s['invite']);
        $piste = (new Piste())->setNom('Piste type ' . $type . ' ' . uniqid())->setTypeAvenant($type)->setDescriptionDuRisque('R')
            ->setExercice(2026)->setClient($em->find(Client::class, $s['client']));
        $piste->setEntreprise($cabinet)->setInvite($invite);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Offre ' . uniqid())->setDuree(12);
        $cotation->setPiste($piste)->setEntreprise($cabinet);
        $em->persist($cotation);
        if ($prime > 0) {
            $ch = (new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel($prime);
            $ch->setCotation($cotation)->setEntreprise($cabinet);
            $em->persist($ch);
        }
        if ($dejaSouscrite) {
            $a = (new Avenant())->setReferencePolice('POL-' . uniqid())->setNumero('0')->setDescription('Souscription')
                ->setStartingAt(new \DateTimeImmutable('-10 days'))->setEndingAt(new \DateTimeImmutable('+355 days'))->setCotation($cotation);
            $a->setEntreprise($cabinet);
            $em->persist($a);
        }
        $em->flush();
        $id = (int) $cotation->getId();
        $em->clear();

        return $id;
    }

    /** Ce que le dialogue d'avenant envoie, sous une cotation. */
    private function poster(int $cotation, array $extra = []): int
    {
        $this->client->request('POST', '/admin/avenant/api/submit', [
            'referencePolice' => 'POL-' . uniqid(),
            'numero'          => '0',
            'description'     => 'Avenant de test',
            'startingAt'      => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
            'endingAt'        => (new \DateTimeImmutable('+1 year -1 day'))->format('Y-m-d\TH:i'),
            'cotation'        => (string) $cotation,
        ] + $extra);

        return $this->client->getResponse()->getStatusCode();
    }

    private function nombreDAvenants(int $cotation): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM avenant WHERE cotation_id = ?', [$cotation]);
    }

    /** @return iterable<string, array{int, bool}> type => [valeur, prime exigée] */
    public static function lesSixTypes(): iterable
    {
        yield 'souscription'   => [Piste::AVENANT_SOUSCRIPTION, true];
        yield 'incorporation'  => [Piste::AVENANT_INCORPORATION, false];
        yield 'prorogation'    => [Piste::AVENANT_PROROGATION, true];
        yield 'annulation'     => [Piste::AVENANT_ANNULATION, false];
        yield 'resiliation'    => [Piste::AVENANT_RESILIATION, false];
        yield 'renouvellement' => [Piste::AVENANT_RENOUVELLEMENT, true];
    }

    /** La table couvre TOUS les types du code : un type ajouté sans décision fait échouer ce test. */
    public function testLaTableCouvreTousLesTypes(): void
    {
        foreach (array_keys(PisteType::TYPE_AVENANT_LABELS) as $type) {
            self::assertArrayHasKey($type, DefautsDeSouscription::PRIME_EXIGEE, "Le type $type n'a pas de décision de prime.");
        }
    }

    /** @dataProvider lesSixTypes */
    public function testLePremierAvenantDUneCotationNonChiffree(int $type, bool $primeExigee): void
    {
        self::assertSame($primeExigee, DefautsDeSouscription::primeExigee($type));
        $s = $this->semerLeCabinet();
        $cotation = $this->cotation($s, $type);

        $code = $this->poster($cotation);
        $corps = (string) $this->client->getResponse()->getContent();

        if ($primeExigee) {
            self::assertSame(422, $code, $corps);
            self::assertStringContainsString('prime chiffrée', json_decode($corps, true)['message'] ?? '',
                'Le refus se lit dans le pied du dialogue : il nomme ce qui manque.');
            self::assertSame(0, $this->nombreDAvenants($cotation), 'Rien n’est écrit.');
        } else {
            self::assertSame(200, $code, $corps);
            self::assertSame(1, $this->nombreDAvenants($cotation));
        }
    }

    /** @dataProvider lesSixTypes */
    public function testUnAvenantSuivantResteToujoursPermis(int $type): void
    {
        $s = $this->semerLeCabinet();
        $cotation = $this->cotation($s, $type, 0.0, dejaSouscrite: true);

        self::assertSame(200, $this->poster($cotation), (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $this->nombreDAvenants($cotation));
    }

    public function testUneCotationChiffreeSeSouscrit(): void
    {
        $s = $this->semerLeCabinet();
        $cotation = $this->cotation($s, Piste::AVENANT_SOUSCRIPTION, 1200.0);

        self::assertSame(200, $this->poster($cotation), (string) $this->client->getResponse()->getContent());
    }

    /** Le drapeau de reprise n'est PAS une donnée de requête : envoyé par le navigateur, il est ignoré. */
    public function testLeDrapeauDeRepriseEnvoyeParUneRequeteEstIgnore(): void
    {
        $s = $this->semerLeCabinet();
        $cotation = $this->cotation($s, Piste::AVENANT_SOUSCRIPTION);

        self::assertSame(422, $this->poster($cotation, ['origine' => 'reprise', 'reprise' => '1']));
        self::assertSame(0, $this->nombreDAvenants($cotation));
    }

    /** Le « + » des Avenants d'une cotation non chiffrée explique au lieu de proposer. */
    public function testLeBoutonAjouterDesAvenantsExplique(): void
    {
        $s = $this->semerLeCabinet();
        $nonChiffree = $this->cotation($s, Piste::AVENANT_SOUSCRIPTION);
        $chiffree = $this->cotation($s, Piste::AVENANT_SOUSCRIPTION, 900.0);
        $exemptee = $this->cotation($s, Piste::AVENANT_INCORPORATION);

        $formulaire = function (int $id): string {
            $this->client->request('GET', sprintf('/admin/cotation/api/get-form/%d', $id));
            self::assertResponseIsSuccessful();

            return html_entity_decode((string) $this->client->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);
        };

        self::assertStringContainsString('data-ajout-impossible', $formulaire($nonChiffree), 'Le « + » cède la place à son explication.');
        self::assertStringNotContainsString('data-ajout-impossible', $formulaire($chiffree));
        self::assertStringNotContainsString('data-ajout-impossible', $formulaire($exemptee), 'Une incorporation n’exige pas de prime.');
        // Une annulation non plus — mais son avenant naît du mouvement de la police : le « + »
        // y renvoie (AdoptionParLeMouvementTest).
        self::assertStringContainsString('Mouvements de la police', $formulaire($this->cotation($s, Piste::AVENANT_ANNULATION)));
    }

    /** Ket : la même garde, au contrôle à blanc ET à l'écriture ; la reprise seule en est exemptée. */
    public function testKetEtLaRepriseSuiventLaMemeRegle(): void
    {
        $s = $this->semerLeCabinet();
        $cotation = $this->cotation($s, Piste::AVENANT_RENOUVELLEMENT);
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['invite']));
        $acteur = $em->find(Utilisateur::class, $s['owner']);
        $op = fn (array $plus = []) => new MutationOperation('create', 'Avenant', null, [
            'cotation'        => $cotation,
            'referencePolice' => 'POL-KET-' . uniqid(),
            'numero'          => '0',
            'description'     => 'Renouvellement',
            'startingAt'      => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
            'endingAt'        => (new \DateTimeImmutable('+1 year -1 day'))->format('Y-m-d\TH:i'),
        ] + $plus);

        $analyse = $service->analyserOperation($op(), $scope);
        self::assertFalse($analyse['ok'], 'Le contrôle à blanc refuse avant que l’utilisateur ne valide le plan.');

        try {
            $service->executer($op(['origine' => 'reprise']), $scope, $acteur);
            self::fail('Ket ne peut pas créer ce premier avenant, même en glissant « origine » dans ses champs.');
        } catch (MutationException) {
        }
        self::assertSame(0, $this->nombreDAvenants($cotation));

        $service->executer($op(), $scope, $acteur, origine: OrigineEcriture::Reprise);
        self::assertSame(1, $this->nombreDAvenants($cotation), 'La reprise reconstitue l’historique : elle est exemptée.');
    }
    /** Le périmètre de Ket, pour le cabinet semé. */
    private function scopeKet(array $s): AiScope
    {
        $em = $this->em();

        return new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['invite']));
    }

    /**
     * UN ID D'UN AUTRE CABINET NE RATTACHE RIEN. Ket désigne les relations par leur id ; le
     * moteur les chargeait sans regarder le cabinet : un avenant pouvait naître sous la
     * cotation d'un voisin, et le motif de refus en citait le nom.
     */
    public function testKetNeRattacheRienAUneCotationDUnAutreCabinet(): void
    {
        $s = $this->semerLeCabinet();
        $em = $this->em();
        $owner = $em->find(Utilisateur::class, $s['owner']);
        $voisin = (new Entreprise())->setNom(self::VOISIN)->setLicence('L')->setAdresse('2 rue')
            ->setTelephone('+243000000042')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($voisin);
        $piste = (new Piste())->setNom('Piste du voisin')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')->setExercice(2026);
        $piste->setEntreprise($voisin);
        $em->persist($piste);
        $secrete = (new Cotation())->setNom('Offre secrète du voisin')->setDuree(12);
        $secrete->setPiste($piste)->setEntreprise($voisin);
        $em->persist($secrete);
        $em->flush();
        $idSecrete = (int) $secrete->getId();
        $em->clear();

        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $op = new MutationOperation('create', 'Avenant', null, [
            'cotation'        => $idSecrete,
            'referencePolice' => 'POL-INTRUS',
            'numero'          => '0',
            'description'     => 'Intrusion',
            'startingAt'      => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
            'endingAt'        => (new \DateTimeImmutable('+1 year -1 day'))->format('Y-m-d\TH:i'),
        ]);

        $analyse = $service->analyserOperation($op, $this->scopeKet($s));
        self::assertFalse($analyse['ok'], 'Le contrôle à blanc refuse la cotation d’un autre cabinet.');
        self::assertStringNotContainsString('secrète', json_encode($analyse, JSON_UNESCAPED_UNICODE), 'Le refus ne nomme pas ce qu’il protège.');

        try {
            $em->wrapInTransaction(fn () => $service->executer($op, $this->scopeKet($s), $em->find(Utilisateur::class, $s['owner'])));
            self::fail('L’écriture refuse elle aussi.');
        } catch (MutationException) {
        }
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM avenant WHERE reference_police = ?', ['POL-INTRUS']));
    }

    /**
     * UN RENOUVELLEMENT D'UNE POLICE SANS PRIME est refusé AVANT le plan : sinon le contrôle
     * à blanc l'ignorait (la cotation n'est qu'une promesse) et le refus tombait après la
     * validation. Le cas existe : une police reprise sans prime.
     */
    public function testLeMouvementDUnePoliceSansPrimeEstRefuseAvantLePlan(): void
    {
        $s = $this->semerLeCabinet();
        $sansPrime = $this->cotation($s, Piste::AVENANT_SOUSCRIPTION, 0.0, dejaSouscrite: true);
        $avecPrime = $this->cotation($s, Piste::AVENANT_SOUSCRIPTION, 900.0, dejaSouscrite: true);
        $builder = static::getContainer()->get(\App\Ai\Mouvement\MouvementAvenantBuilder::class);
        $em = $this->em();
        $base = fn (int $cotation) => $em->getRepository(Avenant::class)->findOneBy(['cotation' => $cotation]);

        foreach ([\App\Ai\Mouvement\MouvementAvenant::Renouvellement, \App\Ai\Mouvement\MouvementAvenant::Prorogation] as $mouvement) {
            $refus = $builder->construire($mouvement, $base($sansPrime), ['dureeJours' => 30], $this->scopeKet($s));
            self::assertStringContainsString('aucune prime à reconduire', $refus['bloquant'] ?? '', $mouvement->value);
            self::assertArrayNotHasKey('operations', $refus);

            $plan = $builder->construire($mouvement, $base($avecPrime), ['dureeJours' => 30], $this->scopeKet($s));
            self::assertArrayNotHasKey('bloquant', $plan, $mouvement->value);
            self::assertNotEmpty($plan['operations']);
        }

        // Une annulation ne porte pas de prime : elle reste possible.
        $annulation = $builder->construire(\App\Ai\Mouvement\MouvementAvenant::Annulation, $base($sansPrime), ['dateEffet' => (new \DateTimeImmutable('today'))->format('Y-m-d')], $this->scopeKet($s));
        self::assertArrayNotHasKey('bloquant', $annulation);
    }

    /**
     * LE CONTRAT REÇU EN UN PLAN : la cotation, ses chargements et son avenant ensemble.
     * L'avenant peut être listé AVANT les chargements — il est écrit après eux, sans quoi
     * la règle de la prime le refuserait à tort. Sans aucun chargement, le contrôle à
     * blanc le dit avant la validation.
     */
    public function testLeContratRecuEnUnSeulPlan(): void
    {
        $s = $this->semerLeCabinet();
        $em = $this->em();
        $pisteId = (int) $em->getConnection()->fetchOne('SELECT piste_id FROM cotation WHERE id = ?', [$this->cotation($s, Piste::AVENANT_SOUSCRIPTION)]);
        $type = (new \App\Entity\Chargement())->setNom('Prime nette');
        $type->setEntreprise($em->find(Entreprise::class, $s['entreprise']))->setInvite($em->find(Invite::class, $s['invite']));
        $em->persist($type);
        $em->flush();
        $typeId = (int) $type->getId();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $avenant = fn (string $ref) => new MutationOperation('create', '', null, [
            'referencePolice' => $ref,
            'numero'          => '0',
            'description'     => 'Contrat reçu',
            'startingAt'      => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
            'endingAt'        => (new \DateTimeImmutable('+1 year -1 day'))->format('Y-m-d\TH:i'),
        ]);

        $sansPrime = new MutationOperation('create', 'Cotation', null, ['piste' => $pisteId, 'nom' => 'Offre sans prime', 'duree' => 12], [
            'avenants' => [$avenant('POL-SANS-PRIME')],
        ]);
        $analyse = $service->analyserOperation($sansPrime, $this->scopeKet($s));
        self::assertFalse($analyse['ok'], 'Sans prime, le plan n’est pas prêt.');
        self::assertStringContainsString('prime chiffrée', json_encode($analyse['manquants'], JSON_UNESCAPED_UNICODE));

        $complet = new MutationOperation('create', 'Cotation', null, ['piste' => $pisteId, 'nom' => 'Offre complète', 'duree' => 12], [
            'avenants'    => [$avenant('POL-COMPLET')],
            'chargements' => [new MutationOperation('create', '', null, ['nom' => 'Prime nette', 'montantFlatExceptionel' => 1500, 'type' => $typeId])],
        ]);
        $analyse = $service->analyserOperation($complet, $this->scopeKet($s));
        self::assertTrue($analyse['ok'], json_encode($analyse['manquants'] ?? [], JSON_UNESCAPED_UNICODE));
        $em->wrapInTransaction(fn () => $service->executer($complet, $this->scopeKet($s), $em->find(Utilisateur::class, $s['owner'])));
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM avenant WHERE reference_police = ?', ['POL-COMPLET']),
            'Avenant listé avant les chargements, écrit après eux : accepté.');
    }
}
