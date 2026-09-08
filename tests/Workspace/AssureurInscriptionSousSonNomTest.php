<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN ASSUREUR S'INSCRIT SOUS SON NOM — l'incident du 2026-09-08, rejoué.
 *
 * « Crée-moi les assureurs suivants : ACTIVA, ACTIVA LIFE, SUNU, RAWSUR, RAWSUR LIFE,
 * MAYFAIR et SFA. » Sept enregistrements sans la moindre subtilité. Ket a réclamé, pour
 * chacun, une adresse e-mail, un numéro d'impôt, une identification nationale et un
 * RCCM — vingt-huit références que le courtier n'avait pas, et n'avait aucune raison
 * d'avoir. Trois messages plus tard, aucun des sept n'existait.
 *
 * Le blocage n'était pas dans l'assistant. `assureur` était la SEULE table du périmètre
 * à exiger ces quatre colonnes : `client` et `partenaire`, qui portent exactement les
 * mêmes champs, les ont toujours eues nullables. `ChampsObligatoiresInspector` ne
 * faisait que rapporter fidèlement ce que la base exigeait, et l'écran de saisie
 * opposait le même refus à qui l'ouvrait à la main.
 *
 * Ce test tient les deux bouts : ce que l'inventaire ANNONCE à l'assistant, et ce que le
 * dry-run ACCEPTE réellement. Un écart entre les deux est précisément la faute que
 * l'invariant « annoncé = exigé » existe pour interdire.
 */
class AssureurInscriptionSousSonNomTest extends WebTestCase
{
    private const ENT = 'PHPUnit-AssureurNom';
    private const OWNER = 'phpunit-assureurnom-owner@test.local';

    /** Ceux du fil du 2026-09-08, à l'identique. */
    private const SEPT = ['ACTIVA', 'ACTIVA LIFE', 'SUNU', 'RAWSUR', 'RAWSUR LIFE', 'MAYFAIR', 'SFA'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;
    private PlanBuilder $planBuilder;

    private Entreprise $entreprise;
    private Invite $invite;
    private Utilisateur $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->planBuilder = static::getContainer()->get(PlanBuilder::class);
        $this->cleanUp();
        $this->seed();
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
        $conn->executeStatement(
            'DELETE t FROM assureur t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :o', ['o' => self::OWNER]);
        $this->em->clear();
    }

    private function seed(): void
    {
        $this->owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $this->owner->setPassword('x');
        $this->em->persist($this->owner);

        $this->entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($this->owner);
        $this->em->persist($this->entreprise);

        $this->invite = (new Invite())->setNom('Testeur')->setUtilisateur($this->owner)
            ->setEntreprise($this->entreprise)->setProprietaire(true);
        $this->em->persist($this->invite);

        $this->owner->setConnectedTo($this->entreprise);
        $this->em->flush();
        $this->client->loginUser($this->owner);
    }

    private function scope(): AiScope
    {
        return new AiScope($this->entreprise, $this->invite);
    }

    /**
     * CE QUE L'INVENTAIRE ANNONCE. Le nom, et rien d'autre : le NIF, le RCCM et l'IDNAT
     * d'une compagnie se relèvent sur une pièce, plus tard, quand elle arrive.
     */
    public function testSeulLeNomEstExigePourInscrireUnAssureur(): void
    {
        $inventaire = $this->service->inventaireChamps('Assureur', $this->scope());
        $obligatoires = array_column($inventaire['obligatoires'], 'champ');

        $this->assertSame(['nom'], $obligatoires,
            'Un assureur s’inscrit sous son nom ; le reste se complète ensuite.');

        // Et ils EXISTENT toujours — on les a rendus facultatifs, pas supprimés.
        $facultatifs = array_column($inventaire['facultatifs'], 'champ');
        foreach (['email', 'numimpot', 'idnat', 'rccm'] as $champ) {
            $this->assertContains($champ, $facultatifs, sprintf('« %s » doit rester saisissable.', $champ));
        }
    }

    /**
     * ET CE QUE LE DRY-RUN ACCEPTE. La demande d'origine, mot pour mot : sept créations
     * portant leur seul nom, en UN plan, sans une seule question.
     */
    public function testLesSeptAssureursSePreparentEnUnPlanSansAucuneQuestion(): void
    {
        $operations = [];
        foreach (self::SEPT as $nom) {
            $operations[] = new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Assureur',
                targetId: null,
                fields: ['nom' => $nom],
            );
        }

        $resultat = $this->planBuilder->construire(new MutationPlan($operations), $this->scope(), 'preparer_operations');

        $this->assertTrue($resultat->data['pret'] ?? false, sprintf(
            'Le plan doit être prêt ; refus obtenu : %s',
            json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        ));
        $this->assertCount(7, $resultat->data['plan']);
        $this->assertSame([], $resultat->data['champsIgnores'] ?? []);
        $this->assertNotNull($resultat->uiAction, 'Sans action d’interface, aucun bouton « Valider et exécuter ».');
    }

    /**
     * LE LIBELLÉ EST UN NOM DE CHAMP. Le formulaire portait « Nunméro Impôt » : la
     * coquille coupait le rattachement par libellé d'AliasDeChamps, si bien que le numéro
     * d'impôt dicté par l'assistant sous « numeroImpot » était écarté en silence — puis
     * réclamé à l'utilisateur qui venait de le donner.
     */
    public function testLeNumeroDImpotDicteSousUnAutreNomEstRattacheAuBonChamp(): void
    {
        $operation = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'Assureur',
            targetId: null,
            fields: ['nom' => 'ACTIVA', 'numeroImpot' => 'A2412345B'],
        );

        $resultat = $this->planBuilder->construire(new MutationPlan([$operation]), $this->scope(), 'preparer_operations');

        $this->assertTrue($resultat->data['pret'] ?? false);
        $this->assertSame([], $resultat->data['champsIgnores'] ?? [],
            'Aucune valeur ne doit être écartée : « numeroImpot » désigne « Numéro d’impôt (NIF) ».');
        $this->assertContains('numimpot', $resultat->data['plan'][0]['champs'],
            'La valeur dictée doit atterrir dans le champ réel, et donc figurer au plan.');
    }
}
