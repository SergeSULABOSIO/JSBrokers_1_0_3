<?php

namespace App\Tests\Workspace;

use App\Ai\Mouvement\MouvementAvenant;
use App\Ai\Mouvement\MouvementAvenantBuilder;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Avenant\DefautsDeSouscription;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\Search\PisteTransformationScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN SEUL CHEMIN PAR TYPE : LE MOUVEMENT DE LA POLICE ADOPTE LA PISTE DÉRIVÉE DÉJÀ MONTÉE.
 *
 * Renouvellement, prorogation, annulation, résiliation : la piste et sa proposition ont été
 * négociées à part ; « Renouveler » (ou « Annuler la police »…) sur la carte du pipe ouvre la
 * boîte du MOUVEMENT de la police de base, qui les adopte au lieu d'en créer une seconde. Le
 * plan n'écrit que l'avenant — dates du type, prime et durée de la proposition — et garde les
 * effets du mouvement : statut de la base, échéances à régulariser, tâche de suivi.
 *
 * Aucun double avenant : l'adoption est revérifiée sous verrou, dans la transaction.
 */
class AdoptionParLeMouvementTest extends WebTestCase
{
    private const EMAIL = 'phpunit-adoption-mouvement@test.local';
    private const CABINET = 'PHPUnit Adoption Mouvement SARL';
    private const VOISIN = 'PHPUnit Adoption Mouvement Voisin SARL';

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

    /**
     * La police de base (souscrite, chiffrée, une échéance future), et la piste dérivée du
     * type donné, liée à elle dans les deux sens, avec sa proposition.
     *
     * @return array{owner: int, entreprise: int, invite: int, base: int, derivee: int, proposition: int, echeance: int}
     */
    private function semer(int $type, ?float $prime, int $duree = 12, bool $lier = true): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Adoption')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000051')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);
        $client = (new Client())->setNom('Client adoption')->setExonere(false);
        $client->setEntreprise($cabinet);
        $em->persist($client);

        $scoper = static function (object $e) use ($cabinet, $invite, $em): object {
            $e->setEntreprise($cabinet);
            if (method_exists($e, 'setInvite')) {
                $e->setInvite($invite);
            }
            $em->persist($e);

            return $e;
        };

        $pisteBase = $scoper((new Piste())->setNom('Police de base')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('R')->setExercice(2026)->setClient($client));
        $cotationBase = $scoper((new Cotation())->setNom('Offre de base')->setDuree(12));
        $cotationBase->setPiste($pisteBase);
        $cotationBase->addChargement($scoper((new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel(1200.0)));
        $echeance = $scoper((new Tranche())->setNom('Échéance future')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('+2 months')));
        $cotationBase->addTranche($echeance);
        $base = $scoper((new Avenant())->setReferencePolice('POL-ADOPT')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-6 months'))->setEndingAt(new \DateTimeImmutable('+6 months -1 day'))
            ->setCotation($cotationBase));

        $derivee = $scoper((new Piste())->setNom('Piste dérivée')->setTypeAvenant($type)
            ->setDescriptionDuRisque('R')->setExercice(2027)->setClient($client));
        if ($lier) {
            $derivee->setAvenantDeBase($base);
            $base->setPisteDeRenouvellement($derivee);
        }
        $proposition = $scoper((new Cotation())->setNom('Proposition négociée')->setDuree($duree));
        $proposition->setPiste($derivee);
        $derivee->addCotation($proposition);
        if ($prime !== null) {
            $proposition->addChargement($scoper((new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel($prime)));
        }
        $em->flush();
        $this->client->loginUser($owner);

        $ids = [
            'owner' => (int) $owner->getId(), 'entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId(),
            'base' => (int) $base->getId(), 'derivee' => (int) $derivee->getId(), 'proposition' => (int) $proposition->getId(),
            'echeance' => (int) $echeance->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function pipe(int $piste): array
    {
        $this->client->request('GET', sprintf('/admin/piste/api/action-pipe/%d/souscrire', $piste));

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    private function executer(string $mouvement, array $ids, array $args = []): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/avenant/api/mouvement/%s/%d?cotation=%d', $mouvement, $ids['base'], $ids['proposition']),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($args),
        );

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    private function compter(string $sql, array $params): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, $params);
    }

    /** Le verbe et le chemin de chaque type : une seule table, lue par la carte, le pipe et Ket. */
    public function testLeVerbeEtLeCheminDeChaqueType(): void
    {
        $attendus = [
            Piste::AVENANT_SOUSCRIPTION   => ['Souscrire', null],
            Piste::AVENANT_INCORPORATION  => ['Souscrire', null],
            Piste::AVENANT_PROROGATION    => ['Proroger', MouvementAvenant::Prorogation],
            Piste::AVENANT_ANNULATION     => ['Annuler la police', MouvementAvenant::Annulation],
            Piste::AVENANT_RESILIATION    => ['Résilier la police', MouvementAvenant::Resiliation],
            Piste::AVENANT_RENOUVELLEMENT => ['Renouveler', MouvementAvenant::Renouvellement],
        ];
        foreach ($attendus as $type => [$verbe, $mouvement]) {
            self::assertSame($verbe, DefautsDeSouscription::verbe($type));
            self::assertSame($mouvement, DefautsDeSouscription::mouvementDe($type));
        }

        // La carte : un type exempté de prime agit dès « En cotation ».
        self::assertSame(PisteTransformationScope::ETAPE_EN_COTATION, DefautsDeSouscription::actionDuPipe(PisteTransformationScope::ETAPE_EN_COTATION, Piste::AVENANT_RENOUVELLEMENT));
        self::assertSame('souscrire-1', DefautsDeSouscription::actionDuPipe(PisteTransformationScope::ETAPE_EN_COTATION, Piste::AVENANT_INCORPORATION));
        self::assertSame('souscrire-3', DefautsDeSouscription::actionDuPipe(PisteTransformationScope::ETAPE_EN_COTATION, Piste::AVENANT_ANNULATION));
        self::assertSame('souscrire-5', DefautsDeSouscription::actionDuPipe(PisteTransformationScope::ETAPE_OFFRES_CHIFFREES, Piste::AVENANT_RENOUVELLEMENT));
        self::assertNull(DefautsDeSouscription::actionDuPipe('transformee', Piste::AVENANT_RENOUVELLEMENT), 'Une piste transformée n’a plus d’action.');
    }

    /** Le pipe d'une piste de renouvellement chiffrée ouvre le MOUVEMENT de sa police, en adoption. */
    public function testLePipeOuvreLeMouvementEnAdoption(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);

        [$code, $r] = $this->pipe($ids['derivee']);
        self::assertSame(200, $code, json_encode($r));
        self::assertSame(
            sprintf('/admin/avenant/api/mouvement-picker/renouvellement/%d?cotation=%d', $ids['base'], $ids['proposition']),
            $r['pickerUrl'],
        );

        // La boîte s'ouvre en adoption : la proposition voyage dans ses URL.
        $this->client->request('GET', $r['pickerUrl']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cotation=' . $ids['proposition'], (string) $this->client->getResponse()->getContent());
    }

    /**
     * LA LIGNE DE LA PROPOSITION (onglet Cotations de la piste) : « Renouveler » ouvre la boîte
     * du mouvement en adoption — pas le formulaire d'avenant. Et le « + » des Avenants de la
     * proposition renvoie au mouvement au lieu d'écrire un avenant sans ses effets.
     */
    public function testLaLigneEtLePlusMenentAuMouvement(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);

        $this->client->request('GET', sprintf('/admin/piste/api/%d/cotations/dialog', $ids['derivee']));
        self::assertResponseIsSuccessful();
        $ligne = new \Symfony\Component\DomCrawler\Crawler((string) json_decode((string) $this->client->getResponse()->getContent(), true)['html']);
        $bouton = $ligne->filter('[data-action="click->collection#evenementDeLigne"]');
        self::assertCount(1, $bouton);
        self::assertSame('Renouveler', $bouton->attr('title'));
        self::assertSame('ui:avenant.mouvement-request', $bouton->attr('data-geste-evenement'));
        self::assertSame(sprintf('/admin/avenant/api/mouvement-picker/renouvellement/%d?cotation=%d', $ids['base'], $ids['proposition']), $bouton->attr('data-geste-url'));
        self::assertCount(0, $ligne->filter('[data-action="click->collection#actionDeLigne"]'), 'Pas de formulaire d’avenant pour un renouvellement.');

        $this->client->request('GET', sprintf('/admin/cotation/api/get-form/%d', $ids['proposition']));
        $html = html_entity_decode((string) $this->client->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);
        self::assertStringContainsString('data-ajout-impossible', $html);
        self::assertStringContainsString('se valide par le mouvement « Renouveler » de la police « POL-ADOPT »', $html);
    }

    /** Une seconde offre de la même piste ne s'adopte plus : la police est déjà traitée. */
    public function testLOffreConcurrenteNeSAdoptePasApresLaPremiere(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);
        $em = $this->em();
        $concurrente = (new Cotation())->setNom('Offre concurrente')->setDuree(12);
        $concurrente->setPiste($em->find(Piste::class, $ids['derivee']))->setEntreprise($em->find(Entreprise::class, $ids['entreprise']));
        $concurrente->addChargement((new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel(1400.0)->setEntreprise($em->find(Entreprise::class, $ids['entreprise'])));
        $em->persist($concurrente);
        $em->flush();
        $idConcurrente = (int) $concurrente->getId();
        $em->clear();

        self::assertSame(200, $this->executer('renouvellement', $ids)[0]);
        [$code, $r] = $this->executer('renouvellement', ['proposition' => $idConcurrente] + $ids);
        self::assertSame(409, $code);
        self::assertSame(1, $this->compter('SELECT COUNT(*) FROM avenant a JOIN cotation c ON c.id = a.cotation_id WHERE c.piste_id = ?', [$ids['derivee']]),
            'Un seul avenant pour la piste de renouvellement.');
    }

    /** Une proposition d'un autre cabinet : 404, comme un identifiant qui n'existe pas. */
    public function testUnePropositionDUnAutreCabinetNeSOuvrePas(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);
        $em = $this->em();
        $voisin = (new Entreprise())->setNom(self::VOISIN)->setLicence('L')->setAdresse('2 rue')
            ->setTelephone('+243000000052')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($em->find(Utilisateur::class, $ids['owner']));
        $em->persist($voisin);
        $cotationVoisine = (new Cotation())->setNom('Offre du voisin')->setDuree(12);
        $cotationVoisine->setEntreprise($voisin);
        $em->persist($cotationVoisine);
        $em->flush();
        $etrangere = (int) $cotationVoisine->getId();
        $em->clear();

        $this->client->request('GET', sprintf('/admin/avenant/api/mouvement-picker/renouvellement/%d?cotation=%d', $ids['base'], $etrangere));
        self::assertResponseStatusCodeSame(404);
    }

    /** Sans police de base, un refus qui mène quelque part : le geste qui existe. */
    public function testSansPoliceDeBaseLeRefusOriente(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0, lier: false);

        [$code, $r] = $this->pipe($ids['derivee']);
        self::assertSame(409, $code);
        self::assertStringContainsString('aucune police', $r['message']);
        self::assertStringContainsString('Mouvements de la police', $r['message']);
    }

    /**
     * RENOUVELLEMENT ADOPTÉ : l'avenant naît sur la proposition — lendemain de l'échéance,
     * durée de la proposition (6 mois, et non celle de la base) — sans piste ni cotation de
     * plus ; la base passe « renouvelée » ; une tâche de suivi est posée.
     */
    public function testLeRenouvellementAdopteLaProposition(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0, 6);
        $pistes = $this->compter('SELECT COUNT(*) FROM piste WHERE entreprise_id = ?', [$ids['entreprise']]);
        $cotations = $this->compter('SELECT COUNT(*) FROM cotation WHERE entreprise_id = ?', [$ids['entreprise']]);

        [$code, $r] = $this->executer('renouvellement', $ids);
        self::assertSame(200, $code, json_encode($r, JSON_UNESCAPED_UNICODE));

        $conn = $this->em()->getConnection();
        $avenant = $conn->fetchAssociative('SELECT * FROM avenant WHERE cotation_id = ?', [$ids['proposition']]);
        self::assertNotFalse($avenant, 'L’avenant est né sur la proposition adoptée.');
        $finBase = new \DateTimeImmutable($conn->fetchOne('SELECT ending_at FROM avenant WHERE id = ?', [$ids['base']]));
        $debut = $finBase->modify('+1 day')->setTime(0, 0);
        self::assertSame($debut->format('Y-m-d'), substr($avenant['starting_at'], 0, 10), 'Au lendemain de l’échéance.');
        self::assertSame($debut->modify('+6 months -1 day')->format('Y-m-d'), substr($avenant['ending_at'], 0, 10), 'La durée négociée fait foi.');
        self::assertSame($pistes, $this->compter('SELECT COUNT(*) FROM piste WHERE entreprise_id = ?', [$ids['entreprise']]), 'Aucune piste de plus.');
        self::assertSame($cotations, $this->compter('SELECT COUNT(*) FROM cotation WHERE entreprise_id = ?', [$ids['entreprise']]), 'Aucune cotation de plus.');
        self::assertSame(Avenant::RENEWAL_STATUS_RENEWED, (int) $conn->fetchOne('SELECT renewal_status FROM avenant WHERE id = ?', [$ids['base']]));
        self::assertSame(1, $this->compter('SELECT COUNT(*) FROM tache WHERE cotation_id = ?', [$ids['proposition']]), 'La tâche de suivi du paiement.');
    }

    /** Sans durée sur la proposition, le renouvellement garde celle de la police de base. */
    public function testSansDureeLaDureeDeLaBase(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0, 0);

        [$code] = $this->executer('renouvellement', $ids);
        self::assertSame(200, $code);
        $conn = $this->em()->getConnection();
        $base = $conn->fetchAssociative('SELECT starting_at, ending_at FROM avenant WHERE id = ?', [$ids['base']]);
        $avenant = $conn->fetchAssociative('SELECT starting_at, ending_at FROM avenant WHERE cotation_id = ?', [$ids['proposition']]);
        $jours = static fn (array $a) => (new \DateTimeImmutable(substr($a['starting_at'], 0, 10)))->diff(new \DateTimeImmutable(substr($a['ending_at'], 0, 10)))->days;
        self::assertSame($jours($base), $jours($avenant), 'Même nombre de jours que la police de base.');
    }

    /** PROROGATION : la durée de la proposition pré-remplit la boîte, et reste modifiable. */
    public function testLaProrogationPreRemplitLaDureeDeLaProposition(): void
    {
        $ids = $this->semer(Piste::AVENANT_PROROGATION, 300.0, 3);
        $finBase = new \DateTimeImmutable($this->em()->getConnection()->fetchOne('SELECT ending_at FROM avenant WHERE id = ?', [$ids['base']]));
        $debut = $finBase->modify('+1 day')->setTime(0, 0);
        $attendus = $debut->diff($debut->modify('+3 months -1 day'))->days + 1;

        $this->client->request('GET', sprintf('/admin/avenant/api/mouvement-picker/prorogation/%d?cotation=%d', $ids['base'], $ids['proposition']));
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/data-mouvement-picker-target="duree"[^>]*value="' . $attendus . '"|value="' . $attendus . '"[^>]*data-mouvement-picker-target="duree"/', (string) $this->client->getResponse()->getContent());

        [$code, $r] = $this->executer('prorogation', $ids, ['dureeJours' => 45]);
        self::assertSame(200, $code, json_encode($r, JSON_UNESCAPED_UNICODE));
        $avenant = $this->em()->getConnection()->fetchAssociative('SELECT starting_at, ending_at FROM avenant WHERE cotation_id = ?', [$ids['proposition']]);
        self::assertSame($debut->modify('+44 days')->format('Y-m-d'), substr($avenant['ending_at'], 0, 10), 'La durée dictée l’emporte.');
        self::assertSame(Avenant::RENEWAL_STATUS_EXTENDED, (int) $this->em()->getConnection()->fetchOne('SELECT renewal_status FROM avenant WHERE id = ?', [$ids['base']]));
    }

    /**
     * ANNULATION ET RÉSILIATION ADOPTÉES : à la date demandée, sans prime ; la base est
     * annulée, et son échéance postérieure à l'acte passe « à régulariser » — par CETTE piste.
     *
     * @dataProvider lesActesDeFin
     */
    public function testLActeDeFinAdopteLaProposition(int $type, string $mouvement): void
    {
        $ids = $this->semer($type, null);
        $date = (new \DateTimeImmutable('+1 month'))->format('Y-m-d');

        [$code, $r] = $this->executer($mouvement, $ids, ['dateEffet' => $date]);
        self::assertSame(200, $code, json_encode($r, JSON_UNESCAPED_UNICODE));

        $conn = $this->em()->getConnection();
        $avenant = $conn->fetchAssociative('SELECT starting_at, ending_at FROM avenant WHERE cotation_id = ?', [$ids['proposition']]);
        self::assertSame($date, substr($avenant['starting_at'], 0, 10));
        self::assertSame($date, substr($avenant['ending_at'], 0, 10));
        self::assertSame(Avenant::RENEWAL_STATUS_CANCELLED, (int) $conn->fetchOne('SELECT renewal_status FROM avenant WHERE id = ?', [$ids['base']]));
        self::assertSame($ids['derivee'], (int) $conn->fetchOne('SELECT regularisation_par_id FROM tranche WHERE id = ?', [$ids['echeance']]),
            'L’échéance d’après l’acte sort du recouvrement, rattachée à la piste adoptée.');
        self::assertSame(0, $this->compter('SELECT COUNT(*) FROM tache WHERE cotation_id = ?', [$ids['proposition']]), 'Rien à recouvrer : pas de tâche.');
    }

    /** @return iterable<string, array{int, string}> */
    public static function lesActesDeFin(): iterable
    {
        yield 'annulation' => [Piste::AVENANT_ANNULATION, 'annulation'];
        yield 'résiliation' => [Piste::AVENANT_RESILIATION, 'resiliation'];
    }

    /** DEUX ADOPTIONS SUCCESSIVES : la seconde reçoit un 409, et un seul avenant existe. */
    public function testDeuxAdoptionsUnSeulAvenant(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);

        self::assertSame(200, $this->executer('renouvellement', $ids)[0]);
        [$code, $r] = $this->executer('renouvellement', $ids);
        self::assertSame(409, $code);
        self::assertStringContainsString('porte déjà son avenant', $r['message']);

        // La seconde demande vient de Ket : pas de plan, donc pas de bouton.
        $em = $this->em();
        $em->clear();
        $ket = static::getContainer()->get(\App\Ai\Tool\PreparerMouvementAvenantTool::class)->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $ids['base']],
            new AiScope($em->find(Entreprise::class, $ids['entreprise']), $em->find(Invite::class, $ids['invite'])),
        );
        self::assertFalse($ket->data['pret'] ?? true);
        self::assertNull($ket->uiAction);
        self::assertSame(1, $this->compter('SELECT COUNT(*) FROM avenant WHERE cotation_id = ?', [$ids['proposition']]));
    }

    /**
     * LA SECONDE VIENT DE KET : son plan a été préparé AVANT que l'écran n'adopte. Il passe
     * toutes les gardes de préparation — c'est la précondition « premier avenant », vérifiée
     * sous verrou à l'écriture, qui le refuse.
     */
    public function testUnPlanDeKetPerimeNeDoubleRien(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $ids['entreprise']), $em->find(Invite::class, $ids['invite']));
        $planDeKet = static::getContainer()->get(MouvementAvenantBuilder::class)->construire(
            MouvementAvenant::Renouvellement,
            $em->find(Avenant::class, $ids['base']),
            [],
            $scope,
            $em->find(Cotation::class, $ids['proposition']),
        );
        self::assertArrayHasKey('operations', $planDeKet);
        // Le plan tel que Ket le garde en attente, puis le relit : la précondition survit.
        $plan = MutationPlan::fromArray(MutationPlan::fromArray($planDeKet['operations'])->toArray());

        self::assertSame(200, $this->executer('renouvellement', $ids)[0], 'L’écran adopte d’abord.');

        $em = $this->em();
        $em->clear();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $ids['entreprise']), $em->find(Invite::class, $ids['invite']));
        $acteur = $em->find(Utilisateur::class, $ids['owner']);
        try {
            $em->wrapInTransaction(function () use ($plan, $service, $scope, $acteur): void {
                $refs = MutationReferences::live();
                foreach ($plan->operationsOrdonnees() as $op) {
                    $service->executer($op, $scope, $acteur, $refs);
                }
            });
            self::fail('Le plan périmé de Ket ne doit rien écrire.');
        } catch (MutationException $e) {
            self::assertSame(MutationException::BLOQUE, $e->statut);
            self::assertStringContainsString('vient d’être souscrite', $e->getMessage());
        }
        self::assertSame(1, $this->compter('SELECT COUNT(*) FROM avenant WHERE cotation_id = ?', [$ids['proposition']]));
    }

    /** Une proposition sans prime ne s'adopte pas pour un renouvellement : le motif le dit. */
    public function testUnRenouvellementSansPrimeNAdoptePas(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, null);

        [$code, $r] = $this->executer('renouvellement', $ids);
        self::assertSame(422, $code);
        self::assertStringContainsString('prime chiffrée', $r['message']);
        self::assertSame(0, $this->compter('SELECT COUNT(*) FROM avenant WHERE cotation_id = ?', [$ids['proposition']]));
    }

    /** Le mauvais mouvement pour cette piste : refusé, rien d'écrit. */
    public function testLeMauvaisMouvementNAdoptePas(): void
    {
        $ids = $this->semer(Piste::AVENANT_RENOUVELLEMENT, 1500.0);

        [$code, $r] = $this->executer('annulation', $ids, ['dateEffet' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d')]);
        self::assertSame(422, $code);
        self::assertStringContainsString('choisissez le mouvement', $r['message']);
        self::assertSame(0, $this->compter('SELECT COUNT(*) FROM avenant WHERE cotation_id = ?', [$ids['proposition']]));
    }

    /** INCORPORATION : effet aujourd'hui, échéance de la police qu'elle complète ; sans base, la règle de souscription, annoncée. */
    public function testLIncorporationSAcheveAvecLaPolice(): void
    {
        $ids = $this->semer(Piste::AVENANT_INCORPORATION, null);
        $em = $this->em();
        $defauts = static::getContainer()->get(DefautsDeSouscription::class);

        $avec = $defauts->pour($em->find(Cotation::class, $ids['proposition']));
        $finBase = $em->find(Avenant::class, $ids['base'])->getEndingAt();
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $avec['startingAt']->format('Y-m-d'));
        self::assertSame($finBase->format('Y-m-d'), $avec['endingAt']->format('Y-m-d'));
        self::assertStringContainsString('POL-ADOPT', implode(' ', $avec['annonces']));

        $sansBase = (new Cotation())->setNom('Sans base')->setDuree(12)->setPiste((new Piste())->setTypeAvenant(Piste::AVENANT_INCORPORATION));
        $sans = $defauts->pour($sansBase);
        self::assertSame((new \DateTimeImmutable('today +12 months -1 day'))->format('Y-m-d'), $sans['endingAt']->format('Y-m-d'));
        self::assertStringContainsString('Aucune police de base', implode(' ', $sans['annonces']));

        // Et la carte « En cotation » d'une incorporation non chiffrée ouvre le formulaire.
        [$code, $r] = $this->pipe($ids['derivee']);
        self::assertSame(200, $code, json_encode($r));
        self::assertTrue($r['isCreationMode']);
        self::assertSame(['id' => $ids['proposition'], 'fieldName' => 'cotation'], $r['parentContext']);
    }
}
