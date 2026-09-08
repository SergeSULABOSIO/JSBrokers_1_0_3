<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use App\Ai\Scope\AiScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UNE PISTE NON DÉRIVÉE EST UNE SOUSCRIPTION.
 *
 * ── POURQUOI CE N'EST PAS « DEVINER » ───────────────────────────────────────────────
 * Les cinq autres types d'avenant — incorporation, prorogation, annulation,
 * renouvellement, résiliation — présupposent tous une police qui existe déjà. C'est
 * exactement ce qu'est une piste DÉRIVÉE : une piste née d'un avenant de base, ouverte
 * avec `?idAvenant=`. Sans ce paramètre, il n'y a aucune police antérieure à faire
 * évoluer, et « Souscription » est le seul type cohérent.
 *
 * ── LA RÈGLE S'ARRÊTE AU FORMULAIRE, ET C'EST VÉRIFIÉ ICI ───────────────────────────
 * L'inventaire annoncé à l'assistant continue de ne déclarer AUCUN défaut sur ce champ
 * (« un discriminant ne se devine pas », cf. InventaireChampsValeursTest) : il le lit sur
 * une entité NEUVE, hors de tout contexte de dérivation, et n'a donc pas de quoi
 * trancher. Le formulaire, lui, sait d'où il est ouvert.
 *
 * Les deux affirmations tiennent ensemble, et ce test les tient ensemble.
 */
class PisteTypeAvenantDefautTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Piste-TypeAvenant';
    private const OWNER = 'phpunit-piste-typeavenant@test.local';

    private $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :o', ['o' => self::OWNER]);

        // L'ordre suit les clés étrangères : l'avenant pend à la cotation, qui pend à la
        // piste, qui pend au client.
        foreach (['avenant', 'cotation', 'piste', 'client', 'risque'] as $table) {
            $conn->executeStatement(
                sprintf('DELETE t FROM %s t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', $table),
                ['n' => self::ENT],
            );
        }

        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :o', ['o' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * @return array{entreprise: Entreprise, invite: Invite, user: Utilisateur}
     */
    private function seed(): array
    {
        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit');
        $user->setPassword('x');
        $user->setVerified(true);
        $this->em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $this->em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setUtilisateur($user)
            ->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($invite);

        $user->setConnectedTo($entreprise);
        $this->em->flush();
        $this->client->loginUser($user);

        return ['entreprise' => $entreprise, 'invite' => $invite, 'user' => $user];
    }

    /** Le formulaire de création, tel que le dialogue le reçoit. */
    private function formulaire(Entreprise $e, Invite $i, string $query = ''): string
    {
        $this->client->request('GET', sprintf(
            '/admin/piste/api/get-form?idEntreprise=%d&idInvite=%d%s',
            $e->getId(),
            $i->getId(),
            $query,
        ));
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** La valeur cochée pour `typeAvenant`, ou null si aucune ne l'est. */
    private function typeCoche(string $html): ?string
    {
        if (!preg_match_all('/<input[^>]*name="typeAvenant"[^>]*>/', $html, $m)) {
            $this->fail('Le champ « typeAvenant » n\'est pas rendu.');
        }

        foreach ($m[0] as $input) {
            if (str_contains($input, 'checked')) {
                preg_match('/value="([^"]*)"/', $input, $v);

                return $v[1] ?? null;
            }
        }

        return null;
    }

    public function testUnePisteOrdinaireSOuvreSurSouscription(): void
    {
        ['entreprise' => $e, 'invite' => $i] = $this->seed();

        $this->assertSame(
            (string) Piste::AVENANT_SOUSCRIPTION,
            $this->typeCoche($this->formulaire($e, $i)),
            'Sans police antérieure, « Souscription » est le seul type cohérent.',
        );
    }

    /**
     * LE CAS QUE LA RÈGLE NE DOIT PAS ABÎMER : une piste dérivée d'un avenant existant
     * s'ouvre sur « Renouvellement ». Écraser ce choix classerait la police dans la
     * mauvaise catégorie, en silence — précisément ce qu'on veut éviter.
     */
    public function testUnePisteDeriveeGardeSonRenouvellement(): void
    {
        ['entreprise' => $e, 'invite' => $i] = $this->seed();
        $avenant = $this->seedAvenant($e);

        $this->assertSame(
            (string) Piste::AVENANT_RENOUVELLEMENT,
            $this->typeCoche($this->formulaire($e, $i, '&idAvenant=' . $avenant->getId())),
        );
    }

    /** Le pré-remplissage de cross-selling posait déjà « Souscription » : il le pose toujours. */
    public function testLeCrossSellingResteSurSouscription(): void
    {
        ['entreprise' => $e, 'invite' => $i] = $this->seed();
        $client = $this->seedClient($e);

        $this->assertSame(
            (string) Piste::AVENANT_SOUSCRIPTION,
            $this->typeCoche($this->formulaire($e, $i, '&idClient=' . $client->getId())),
        );
    }

    /**
     * L'ASSISTANT, LUI, CONTINUE DE DEMANDER.
     *
     * Il lit l'inventaire sur une entité neuve, sans savoir d'où viendrait la piste :
     * lui annoncer un défaut le ferait trancher à l'aveugle. La règle du formulaire ne
     * doit donc pas déborder jusqu'ici.
     */
    public function testLInventaireDeLAssistantNAnnonceToujoursAucunDefaut(): void
    {
        ['entreprise' => $e, 'invite' => $i] = $this->seed();

        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $inventaire = $service->inventaireChamps('Piste', new AiScope($e, $i));

        // L'inventaire se lit par GROUPES (obligatoires / facultatifs), comme le fait
        // déjà InventaireChampsValeursTest : c'est le même contrat de retour.
        $champs = [];
        foreach (['obligatoires', 'facultatifs'] as $groupe) {
            foreach ($inventaire[$groupe] ?? [] as $item) {
                $champs[$item['champ']] = $item;
            }
        }

        $this->assertArrayHasKey('typeAvenant', $champs);
        $this->assertArrayNotHasKey('defaut', $champs['typeAvenant'], 'Un discriminant ne se devine pas.');
    }

    private function seedClient(Entreprise $e): Client
    {
        $client = (new Client())->setNom('Client PHPUnit');
        $client->setEntreprise($e);
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    /** Une police complète : client → piste → cotation → avenant. */
    private function seedAvenant(Entreprise $e): Avenant
    {
        $client = $this->seedClient($e);

        // `imposable` est NOT NULL en base : le semis le pose sur les 43 risques du
        // catalogue, une fixture doit donc le poser aussi.
        $risque = (new Risque())->setCode('PHPU-RISQ')->setNomComplet('Risque PHPUnit')
            ->setBranche(0)->setImposable(true);
        $risque->setEntreprise($e);
        $this->em->persist($risque);

        $piste = (new Piste())->setNom('Piste de base')->setClient($client)->setRisque($risque)
            ->setDescriptionDuRisque('Couverture PHPUnit')
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setExercice((int) date('Y'));
        $piste->setEntreprise($e);
        $this->em->persist($piste);

        // Les colonnes NOT NULL de `cotation` et `avenant` sont posées ici en entier :
        // les découvrir une par une au fil des erreurs coûte un aller-retour chacune.
        $cotation = (new Cotation())->setNom('Cotation de base')->setPiste($piste)->setDuree(12);
        $cotation->setEntreprise($e);
        $this->em->persist($cotation);

        $avenant = (new Avenant())
            ->setNumero('AV-PHPU-001')
            ->setReferencePolice('POL-PHPU-001')
            ->setDescription('Avenant de base PHPUnit')
            ->setStartingAt(new \DateTimeImmutable('-1 year'))
            ->setEndingAt(new \DateTimeImmutable('+1 month'))
            ->setCotation($cotation);
        $avenant->setEntreprise($e);
        $this->em->persist($avenant);

        $this->em->flush();

        return $avenant;
    }
}
