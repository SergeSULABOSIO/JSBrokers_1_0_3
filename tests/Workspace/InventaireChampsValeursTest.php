<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Workspace\ReferentielEnumerateur;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'INVENTAIRE DES CHAMPS est la seule description de champ que reçoive l'assistant.
 * Il n'annonçait que `champ` + `libelle` : pour un champ à code constant, cela ne
 * suffisait pas à le remplir — d'où des champs laissés vides, ou remplis du libellé
 * affiché puis rejetés par le formulaire, sans que l'erreur enseigne les valeurs
 * acceptables (la réparation bouclait).
 *
 * On vérifie ici, contre la BDD de test, que l'inventaire dit désormais CE QU'IL ATTEND
 * (nature, valeurs, défaut, entité cible, multiplicité), que le périmètre annoncé
 * correspond exactement à ce que l'écriture sait faire, et que l'écriture accepte un
 * libellé là où elle exigeait un code.
 */
class InventaireChampsValeursTest extends WebTestCase
{
    private const ENT = 'PHPUnit-InvChamps';
    private const OWNER = 'phpunit-invchamps-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;

    private Entreprise $entreprise;
    private Invite $invite;
    private Utilisateur $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
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
        foreach (['piste', 'client', 'risque', 'assureur'] as $table) {
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

    /** @return array<string, array> l'inventaire indexé par nom de champ */
    private function parChamp(array $inventaire): array
    {
        $index = [];
        foreach (['obligatoires', 'facultatifs'] as $groupe) {
            foreach ($inventaire[$groupe] as $item) {
                $index[$item['champ']] = $item + ['__groupe' => $groupe];
            }
        }

        return $index;
    }

    private function seedRisque(string $nom): Risque
    {
        // Un Risque se nomme par « nomComplet » (pas « nom ») : c'est précisément ce que
        // EntiteLibelle::displayField() détecte, au lieu de le coder en dur.
        $r = (new Risque())->setNomComplet($nom)->setCode(substr(md5($nom), 0, 8))
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true)
            ->setEntreprise($this->entreprise)->setInvite($this->invite);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    // ───────────────── L'inventaire dit ce qu'il attend ─────────────────

    public function testUnChampACodeAnnonceSesValeursEtLeurSens(): void
    {
        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));

        $this->assertArrayHasKey('typeAvenant', $champs);
        $item = $champs['typeAvenant'];

        $this->assertSame('obligatoires', $item['__groupe'], 'Le type d’avenant est un discriminant exigé.');
        $this->assertSame('choix', $item['nature']);
        $this->assertArrayHasKey('valeurs', $item, 'Sans « valeurs », le champ ne peut pas être rempli.');

        $codes = array_column($item['valeurs'], 'code');
        $this->assertContains(Piste::AVENANT_RENOUVELLEMENT, $codes);
        $this->assertCount(6, $codes);

        $parCode = array_column($item['valeurs'], 'libelle', 'code');
        $this->assertSame('Souscription', $parCode[Piste::AVENANT_SOUSCRIPTION]);
    }

    public function testUnDiscriminantExigeNAnnonceAucunDefaut(): void
    {
        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));

        $this->assertArrayNotHasKey('defaut', $champs['typeAvenant'], 'Un discriminant ne se devine pas.');
    }

    public function testUnChampADefautLAnnonceEnClairAvecSaProvenance(): void
    {
        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));

        $this->assertArrayHasKey('renewalCondition', $champs);
        $defaut = $champs['renewalCondition']['defaut'] ?? null;

        $this->assertNotNull($defaut, 'La consigne « applique et annonce le défaut » exige de le transmettre.');
        $this->assertSame(Piste::RENEWAL_CONDITION_RENEWABLE, $defaut['code']);
        $this->assertSame('entite', $defaut['source']);
        $this->assertNotSame('', $defaut['libelle'], 'Le défaut doit être annonçable en clair, pas en code nu.');
    }

    public function testUneRelationAnnonceSonEntiteCible(): void
    {
        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));

        $this->assertSame('relation', $champs['risque']['nature']);
        $this->assertSame('Risque', $champs['risque']['entiteCible']);
        $this->assertSame('Client', $champs['client']['entiteCible']);
    }

    public function testUnReferentielCourtEstEnumere(): void
    {
        $risque = $this->seedRisque('Incendie PHPUnit');

        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));
        $valeurs = $champs['risque']['valeurs'] ?? null;

        $this->assertNotNull($valeurs, 'Un référentiel court doit être listé : sinon le champ reste vide.');
        $this->assertContains($risque->getId(), array_column($valeurs, 'code'));
        $this->assertContains('Incendie PHPUnit', array_column($valeurs, 'libelle'));
    }

    public function testUnReferentielTropGrandRenvoieVersLaRecherche(): void
    {
        for ($i = 0; $i <= ReferentielEnumerateur::PLAFOND_ENUMERATION; ++$i) {
            $this->seedRisque(sprintf('Risque PHPUnit %02d', $i));
        }

        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));
        $item = $champs['risque'];

        $this->assertArrayNotHasKey('valeurs', $item, 'Une liste tronquée se lirait comme l’ensemble.');
        $this->assertStringContainsString('rechercher_entites', (string) ($item['aide'] ?? ''));
        $this->assertStringContainsString('Risque', (string) ($item['aide'] ?? ''));
    }

    public function testUnReferentielVideEstDistingueDUnReferentielTropGrand(): void
    {
        // Aucun assureur enregistré : la liste est VIDE (il faut en créer un), ce qui
        // n'est pas la même information que « trop d'entrées pour les montrer ».
        $enumerateur = static::getContainer()->get(ReferentielEnumerateur::class);

        $this->assertSame([], $enumerateur->codes('Assureur', $this->scope()));
    }

    public function testLaSurfaceAnnonceeCouvreCeQueLEcritureSaitFaire(): void
    {
        // Ces deux champs étaient écrivables mais JAMAIS annoncés (le filtre de
        // l'inventaire était plus étroit que celui de la soumission) : ils restaient
        // donc vides par construction.
        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));

        $this->assertArrayHasKey('avenantDeBase', $champs, 'OneToOne propriétaire : écrivable, donc annonçable.');
        $this->assertArrayHasKey('partenaires', $champs, 'ManyToMany : écrivable, donc annonçable.');
        $this->assertTrue($champs['partenaires']['multiple'], 'Une liste d’identifiants est attendue.');
    }

    public function testLeCoteInverseDUneRelationResteHorsInventaire(): void
    {
        // Lui affecter une valeur ne persisterait rien : l'annoncer serait un mensonge.
        $champs = $this->parChamp($this->service->inventaireChamps('Piste', $this->scope()));

        $this->assertArrayNotHasKey('cotations', $champs);
        $this->assertArrayNotHasKey('taches', $champs);
    }

    public function testLEditionMontreLaValeurActuelleSansAnnoncerDeDefaut(): void
    {
        $client = (new Client())->setNom('Client Inventaire')->setExonere(true)
            ->setEntreprise($this->entreprise)->setInvite($this->invite);
        $this->em->persist($client);
        $this->em->flush();

        $champs = $this->parChamp($this->service->inventaireChamps('Client', $this->scope(), $client));

        $this->assertSame('Oui', $champs['exonere']['valeurActuelle']);
        $this->assertArrayNotHasKey('defaut', $champs['exonere'], 'En édition, un défaut n’a pas de sens.');
    }

    // ───────────────── L'écriture accepte un libellé ─────────────────

    public function testUnLibelleEstAccepteLaOuUnCodeEstAttendu(): void
    {
        // Symétrie manquante : la lecture d'une fiche restitue « Renouvellement »,
        // l'écriture exigeait 5. Un champ à code était lisible et non réécrivable.
        $op = new MutationOperation('create', 'Piste', null, [
            'nom' => 'Piste libellé PHPUnit',
            'descriptionDuRisque' => 'Test',
            'exercice' => 2026,
            'typeAvenant' => 'Renouvellement',
        ]);

        $this->service->executer($op, $this->scope(), $this->owner);
        $this->em->clear();

        $piste = $this->em->getRepository(Piste::class)->findOneBy(['nom' => 'Piste libellé PHPUnit']);
        $this->assertNotNull($piste, 'La création doit aboutir malgré le libellé.');
        $this->assertSame(Piste::AVENANT_RENOUVELLEMENT, $piste->getTypeAvenant());
    }

    public function testUnLibelleInsensibleALaCasseEtAuxAccentsEstAccepte(): void
    {
        $op = new MutationOperation('create', 'Piste', null, [
            'nom' => 'Piste accents PHPUnit',
            'descriptionDuRisque' => 'Test',
            'exercice' => 2026,
            'typeAvenant' => 'resiliation',
        ]);

        $this->service->executer($op, $this->scope(), $this->owner);
        $this->em->clear();

        $piste = $this->em->getRepository(Piste::class)->findOneBy(['nom' => 'Piste accents PHPUnit']);
        $this->assertSame(Piste::AVENANT_RESILIATION, $piste?->getTypeAvenant());
    }

    public function testUnCodeValideResteIntact(): void
    {
        $op = new MutationOperation('create', 'Piste', null, [
            'nom' => 'Piste code PHPUnit',
            'descriptionDuRisque' => 'Test',
            'exercice' => 2026,
            'typeAvenant' => Piste::AVENANT_PROROGATION,
        ]);

        $this->service->executer($op, $this->scope(), $this->owner);
        $this->em->clear();

        $piste = $this->em->getRepository(Piste::class)->findOneBy(['nom' => 'Piste code PHPUnit']);
        $this->assertSame(Piste::AVENANT_PROROGATION, $piste?->getTypeAvenant());
    }

    public function testUnLibelleInconnuNEstPasDevine(): void
    {
        // Aucune correspondance : la valeur n'est pas transformée, le formulaire refuse.
        // Un refus honnête vaut mieux qu'un code plausible et faux.
        $op = new MutationOperation('create', 'Piste', null, [
            'nom' => 'Piste inconnue PHPUnit',
            'descriptionDuRisque' => 'Test',
            'exercice' => 2026,
            'typeAvenant' => 'Bidule inexistant',
        ]);

        $analyse = $this->service->analyserOperation($op, $this->scope());

        $this->assertNotSame([], $analyse['erreurs'] ?? $analyse['manquants'] ?? [], 'La valeur ne doit pas passer en silence.');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Piste::class)->findOneBy(['nom' => 'Piste inconnue PHPUnit']));
    }
}
