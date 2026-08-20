<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\RetrocommissionsTool;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA PARITÉ AGENT ↔ PARTENAIRE, VÉRIFIÉE PLUTÔT QUE PROMISE.
 *
 * L'agent interne disposait d'un rapport détaillé et d'un outil ; le partenaire externe,
 * qui se sert pourtant AVANT lui sur la même commission, n'avait rien. La correction
 * pouvait se faire de deux façons : écrire un second outil jumeau, ou faire passer les
 * deux camps par le même chemin. Le premier tient la parité à la main — et la perd au
 * premier ajout de colonne, silencieusement, du côté qu'on aura oublié.
 *
 * Ce test est le prix à payer pour la seconde solution : il exige que les DEUX camps
 * rendent exactement les mêmes clés, les mêmes colonnes et les mêmes rôles. Ajouter une
 * information d'un seul côté le fait échouer — c'est ce qui rend la parité structurelle.
 *
 * Il vérifie aussi ce qui doit RESTER différent : les montants (le partenaire encaisse sur
 * l'assiette pleine, l'agent sur le reliquat) et la note métier (deux circuits, deux
 * comptes SYSCOHADA). Une parité qui gommerait cela serait un mensonge.
 */
class RetrocommissionsPariteTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-parite-retro@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit PariteRetro SARL';
    private const COMMISSION = 1000.0;
    private const PART_PARTENAIRE = 20.0;
    private const TAUX_AGENT = 10.0;

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

    private function outil(): RetrocommissionsTool
    {
        return static::getContainer()->get(RetrocommissionsTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach ([
            'avenant', 'revenu_pour_courtier', 'chargement_pour_prime', 'cotation',
            'condition_partage', 'piste', 'client', 'partenaire', 'risque', 'type_revenu', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * UNE SEULE affaire, portant les DEUX bénéficiaires : c'est la seule façon de comparer
     * deux rapports qui décrivent le même argent.
     *
     * @return array{entrepriseId:int, gestionnaireId:int, agentId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Parite')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Serge SULA')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(self::PART_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Parité')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Parité')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Parité')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        $condition = (new ConditionPartage())->setNom('Part de Serge')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_AGENT)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($agent);
        $condition->setEntreprise($entreprise);
        $piste->addConditionsPartageAgent($condition);
        $em->persist($condition);

        $cotation = (new Cotation())->setNom('Cotation Parité')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(5000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $avenant = (new Avenant())->setReferencePolice('POL-PARITE')->setNumero('0')
            ->setDescription('Police parité')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'entrepriseId'   => (int) $entreprise->getId(),
            'gestionnaireId' => (int) $gestionnaire->getId(),
            'agentId'        => (int) $agent->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function scope(int $inviteId, int $entrepriseId): AiScope
    {
        $em = $this->em();

        return new AiScope(
            $em->getRepository(Entreprise::class)->find($entrepriseId),
            $em->getRepository(Invite::class)->find($inviteId),
        );
    }

    /** @return array<string, mixed> */
    private function detail(string $beneficiaire, array $ids): array
    {
        $resultat = $this->outil()->execute(
            ['beneficiaire' => $beneficiaire, 'detail' => 'par_ligne'],
            $this->scope($ids['gestionnaireId'], $ids['entrepriseId']),
        );
        self::assertSame(AiToolResult::STATUS_OK, $resultat->status, 'Le décompte de ' . $beneficiaire . ' doit aboutir.');

        return $resultat->data;
    }

    public function testLesDeuxCampsRendentExactementLesMemesClesDeLigne(): void
    {
        $ids = $this->semer();

        $agent = $this->detail('Serge SULA', $ids);
        $partenaire = $this->detail('SUNU Courtage', $ids);

        self::assertNotEmpty($agent['items']);
        self::assertNotEmpty($partenaire['items']);

        $clesAgent = array_keys($agent['items'][0]);
        $clesPartenaire = array_keys($partenaire['items'][0]);
        sort($clesAgent);
        sort($clesPartenaire);

        // UNE COLONNE AJOUTÉE D'UN SEUL CÔTÉ FAIT ÉCHOUER ICI. C'est tout l'objet du test :
        // la parité ne se surveille pas à la relecture, elle se prouve.
        self::assertSame($clesPartenaire, $clesAgent);
    }

    public function testLesDeuxCampsDeclarentLesMemesColonnesEtLesMemesRoles(): void
    {
        $ids = $this->semer();

        $agent = $this->detail('Serge SULA', $ids);
        $partenaire = $this->detail('SUNU Courtage', $ids);

        self::assertSame($partenaire['presentation'], $agent['presentation']);
        self::assertSame($partenaire['colonnesDisponibles'], $agent['colonnesDisponibles']);
        self::assertSame(array_keys($partenaire['totaux']), array_keys($agent['totaux']));
    }

    public function testChaqueColonnePresentableEstUneColonneRenvoyee(): void
    {
        $ids = $this->semer();

        foreach (['Serge SULA', 'SUNU Courtage'] as $beneficiaire) {
            $data = $this->detail($beneficiaire, $ids);
            $ligne = $data['items'][0];

            // Une colonne annoncée que la ligne ne porte pas est une colonne fantôme :
            // le modèle n'aurait que deux issues, la taire ou l'inventer.
            foreach (array_keys($data['presentation']['colonnes']) as $colonne) {
                self::assertArrayHasKey($colonne, $ligne, $beneficiaire . ' : colonne annoncée mais absente.');
            }
            foreach (array_keys($data['colonnesDisponibles']) as $colonne) {
                self::assertArrayHasKey($colonne, $ligne, $beneficiaire . ' : colonne promue mais absente.');
            }
        }
    }

    public function testCeQuiDoitRESTERdifferentLeReste(): void
    {
        $ids = $this->semer();

        $agent = $this->detail('Serge SULA', $ids);
        $partenaire = $this->detail('SUNU Courtage', $ids);

        // LE PARTENAIRE SE SERT LE PREMIER, sur l'assiette pleine.
        self::assertEqualsWithDelta(self::COMMISSION, $partenaire['items'][0]['assiette'], 0.01);
        self::assertEqualsWithDelta(
            self::COMMISSION * self::PART_PARTENAIRE / 100,
            $partenaire['items'][0]['due'],
            0.01,
        );

        // L'AGENT PARTAGE LE RELIQUAT : la commission pure moins ce qui est parti dehors.
        $reliquat = self::COMMISSION - (self::COMMISSION * self::PART_PARTENAIRE / 100);
        self::assertEqualsWithDelta($reliquat, $agent['items'][0]['assiette'], 0.01);
        self::assertEqualsWithDelta($reliquat * self::TAUX_AGENT / 100, $agent['items'][0]['due'], 0.01);

        // Deux circuits, deux comptes : la note ne doit pas être la même des deux côtés.
        self::assertStringContainsString('6611', $agent['note']);
        self::assertStringContainsString('632', $partenaire['note']);
        self::assertNotSame($agent['note'], $partenaire['note']);
    }

    public function testLeTableauDeTousLesBeneficiairesMeleLesDeuxCamps(): void
    {
        $ids = $this->semer();

        $data = $this->outil()->execute([], $this->scope($ids['gestionnaireId'], $ids['entrepriseId']))->data;

        $types = array_column($data['items'], 'type');
        sort($types);

        // « À qui dois-je de la rétrocommission ? » n'avait de réponse pour personne :
        // l'agent et l'intermédiaire figurent désormais dans le même tableau.
        self::assertSame(['agent', 'partenaire'], $types);
        foreach ($data['items'] as $item) {
            self::assertArrayHasKey('beneficiaire', $item);
            self::assertArrayHasKey('due', $item);
        }
    }

    public function testLaVentilationParAxeSAppuieSurLesMemesLignes(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        foreach (['Serge SULA', 'SUNU Courtage'] as $beneficiaire) {
            $lignes = $this->detail($beneficiaire, $ids);
            $ventile = $this->outil()->execute(
                ['beneficiaire' => $beneficiaire, 'detail' => 'par_axe', 'axe' => 'assureur'],
                $scope,
            )->data;

            // La ventilation AGRÈGE, elle ne recalcule pas : les deux vues doivent dire la
            // même somme, faute de quoi l'utilisateur lirait deux vérités selon la question
            // qu'il pose.
            self::assertEqualsWithDelta(
                (float) $lignes['totaux']['due'],
                array_sum(array_column($ventile['items'], 'due')),
                0.01,
                $beneficiaire . ' : la ventilation doit totaliser comme le détail.',
            );
        }
    }

    public function testUnNomInconnuDesDeuxCotesEstRefuseEnDisantQuoiFaire(): void
    {
        $ids = $this->semer();

        $resultat = $this->outil()->execute(
            ['beneficiaire' => 'Personne De Ce Nom'],
            $this->scope($ids['gestionnaireId'], $ids['entrepriseId']),
        );

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        self::assertStringContainsString('agent interne ni partenaire', $resultat->data['note']);
    }

    public function testUnNomPorteParLesDeuxCampsEstRenduAmbiguPlutotQueDevine(): void
    {
        $ids = $this->semer();

        // Un intermédiaire externe portant le nom de l'agent : cela arrive (une société au
        // nom de son fondateur), et trancher seul reviendrait à attribuer une rémunération
        // à la mauvaise personne.
        $em = $this->em();
        $homonyme = (new Partenaire())->setNom('Serge SULA')->setPart(self::PART_PARTENAIRE);
        $homonyme->setEntreprise($em->getRepository(Entreprise::class)->find($ids['entrepriseId']));
        $em->persist($homonyme);
        $em->flush();
        $em->clear();

        $data = $this->outil()->execute(
            ['beneficiaire' => 'Serge SULA'],
            $this->scope($ids['gestionnaireId'], $ids['entrepriseId']),
        )->data;

        // La question est POSÉE, avec les deux candidats nommés — c'est la forme que le
        // repli sait rendre à l'utilisateur.
        self::assertArrayHasKey('ambigu', $data);
        self::assertCount(2, $data['ambigu']['valeurs']);
        self::assertArrayNotHasKey('items', $data, 'Aucun chiffre ne doit sortir d\'une ambiguïté.');
    }

    public function testLeTypeDemandeLeveLAmbiguiteSansQuestion(): void
    {
        $ids = $this->semer();

        $em = $this->em();
        $homonyme = (new Partenaire())->setNom('Serge SULA')->setPart(self::PART_PARTENAIRE);
        $homonyme->setEntreprise($em->getRepository(Entreprise::class)->find($ids['entrepriseId']));
        $em->persist($homonyme);
        $em->flush();
        $em->clear();

        $data = $this->outil()->execute(
            ['beneficiaire' => 'Serge SULA', 'type' => 'agent', 'detail' => 'par_ligne'],
            $this->scope($ids['gestionnaireId'], $ids['entrepriseId']),
        )->data;

        // « l'AGENT Serge SULA » : la phrase le disait, l'outil n'a plus à le demander.
        self::assertArrayNotHasKey('ambigu', $data);
        self::assertSame('agent', $data['type']);
    }

    public function testUneCorrespondanceExacteLEmporteSurUnePartielle(): void
    {
        $ids = $this->semer();

        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $plusLong = (new Partenaire())->setNom('SUNU Courtage IARD RDC')->setPart(self::PART_PARTENAIRE);
        $plusLong->setEntreprise($entreprise);
        $em->persist($plusLong);
        $em->flush();
        $em->clear();

        $data = $this->outil()->execute(
            ['beneficiaire' => 'SUNU Courtage', 'detail' => 'par_ligne'],
            $this->scope($ids['gestionnaireId'], $ids['entrepriseId']),
        )->data;

        // « SUNU Courtage » désigne SUNU Courtage, pas « SUNU Courtage IARD RDC » : sans
        // cette priorité, nommer exactement une société deviendrait ambigu dès qu'une
        // autre porte son nom en préfixe.
        self::assertArrayNotHasKey('ambigu', $data);
        self::assertSame('SUNU Courtage', $data['beneficiaire']);
    }

    public function testLaPeriodeEcarteLesAffairesHorsBornes(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        $dansLaPeriode = $this->outil()->execute([
            'beneficiaire' => 'Serge SULA',
            'detail' => 'par_ligne',
            'du' => (new \DateTimeImmutable('-60 days'))->format('Y-m-d'),
            'au' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
        ], $scope)->data;

        $horsPeriode = $this->outil()->execute([
            'beneficiaire' => 'Serge SULA',
            'detail' => 'par_ligne',
            'du' => '2000-01-01',
            'au' => '2000-12-31',
        ], $scope)->data;

        // La police prend effet il y a trente jours : elle entre dans la première fenêtre
        // et sort de la seconde.
        self::assertNotEmpty($dansLaPeriode['items']);
        self::assertSame('du 01/01/2000 au 31/12/2000', $horsPeriode['periode']);
        self::assertArrayNotHasKey('items', $horsPeriode, 'Hors période, aucune ligne à montrer.');
    }
}