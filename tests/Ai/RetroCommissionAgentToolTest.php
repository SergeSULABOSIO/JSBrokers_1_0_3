<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\RetrocommissionsTool;
use App\Ai\Tool\SignalerReversementRetroAgentTool;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * KET ET LES RÉTROCOMMISSIONS D'AGENTS : parité avec l'écran, et mêmes gardes.
 *
 * Trois propriétés que ce test refuse de laisser filer :
 *
 *  1. LES MÊMES CHIFFRES QUE L'ÉCRAN. L'outil passe par RapportProductionAgentBuilder, le
 *     service qu'utilise la route du workspace. Un écart entre ce que Ket annonce et ce que
 *     le courtier lit ne serait découvert que par un agent contestant sa paie.
 *
 *  2. SOI-MÊME OUI, UN COLLÈGUE NON. La lecture est ouverte à tout invité pour SES propres
 *     rétrocommissions — c'est l'exigence métier — mais s'arrête là. Sans cette borne, le
 *     chat exposerait la rémunération de n'importe qui.
 *
 *  3. VERSER N'EST PAS CONSULTER, ET NE S'IMPROVISE PAS. L'écriture exige le privilège de
 *     gestion (personne ne se paie soi-même), et une demande sans montant exigible est
 *     REFUSÉE EN LE DISANT — jamais soldée par un plan vide que l'utilisateur validerait
 *     sans effet.
 */
class RetroCommissionAgentToolTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ketretro-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit KetRetro SARL';

    private const COMMISSION = 1000.0;
    private const TAUX_ALICE = 15.0;

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

    private function lecture(): RetrocommissionsTool
    {
        return static::getContainer()->get(RetrocommissionsTool::class);
    }

    private function ecriture(): SignalerReversementRetroAgentTool
    {
        return static::getContainer()->get(SignalerReversementRetroAgentTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'DELETE pcp FROM piste_condition_partage pcp JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'reversement_retro_agent', 'condition_partage', 'avenant', 'revenu_pour_courtier',
            'type_revenu', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    // ===================== 1. Les chiffres =====================

    public function testLeModeParAgentRendLeDuLePayeEtLeSolde(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        $resultat = $this->lecture()->execute([], $scope);
        $data = $resultat->data;

        self::assertCount(1, $data['items'], 'Seuls les agents ayant une rétrocommission figurent.');
        $ligne = $data['items'][0];

        self::assertSame('Alice', $ligne['beneficiaire']);
        self::assertSame('agent', $ligne['type']);
        self::assertSame(2, $ligne['affaires']);
        // 15 % de la commission pure de deux affaires à 1000.
        self::assertEqualsWithDelta(2 * self::COMMISSION * 0.15, $ligne['due'], 0.01);
        self::assertEqualsWithDelta(0.0, $ligne['payee'], 0.01);
        self::assertEqualsWithDelta($ligne['due'], $ligne['solde'], 0.01);
        // Aucune commission encaissée : rien n'est encore réclamable.
        self::assertEqualsWithDelta(0.0, $ligne['exigible'], 0.01);

        // La règle métier voyage avec les chiffres. Ce tableau-ci mélange les deux camps :
        // sa note doit donc les DISTINGUER — assiettes, circuits et comptes — sans quoi le
        // modèle prêterait à l'agent la mécanique du partenaire.
        self::assertStringContainsString('DEUX CIRCUITS', $data['note']);
        self::assertStringContainsString('AGENT interne partage ensuite le RELIQUAT', $data['note']);
        self::assertStringContainsString('n\'en est pas le gestionnaire', $data['note']);
    }

    public function testLeModeParLigneDetailleAffaireParAffaire(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        $data = $this->lecture()->execute(
            ['beneficiaire' => 'Alice', 'detail' => 'par_ligne'],
            $scope,
        )->data;

        self::assertCount(2, $data['items']);
        $ligne = $data['items'][0];

        self::assertSame('Client Ket', $ligne['client']);
        // Le GESTIONNAIRE est rendu : c'est ce qui empêche le modèle de le confondre
        // avec le bénéficiaire.
        self::assertSame('Gestionnaire', $ligne['gestionnaire']);
        self::assertSame('Prime apporteur Alice', $ligne['condition']);
        self::assertEqualsWithDelta(self::TAUX_ALICE, $ligne['taux'], 0.01);
        self::assertEqualsWithDelta(self::COMMISSION * 0.15, $ligne['due'], 0.01);

        // Les totaux accompagnent le tableau : le modèle n'a pas à les additionner.
        self::assertEqualsWithDelta(2 * self::COMMISSION * 0.15, $data['totaux']['due'], 0.01);

        // Le décompte d'UN bénéficiaire porte la note de SON circuit, pas un résumé des deux.
        self::assertStringContainsString('AGENT INTERNE', $data['note']);
        self::assertStringContainsString('SYSCOHADA 6611', $data['note']);

        // LA CHAÎNE DE CALCUL : de quoi justifier le montant, et non seulement l'affirmer.
        self::assertSame('condition du bénéficiaire', $ligne['origineDuTaux']);
        self::assertEqualsWithDelta(self::COMMISSION, $ligne['assiette'], 0.01);
        self::assertEqualsWithDelta($ligne['assiette'] * self::TAUX_ALICE / 100, $ligne['due'], 0.01);
    }

    // ===================== 2. Le périmètre de lecture =====================

    public function testUnInviteOrdinaireNeVoitQueSesProprresRetrocommissions(): void
    {
        $ids = $this->semer();
        // Alice n'est pas gestionnaire d'invités : le mode « tous les agents » se réduit
        // à elle-même.
        $scope = $this->scope($ids['aliceId'], $ids['entrepriseId']);

        $data = $this->lecture()->execute([], $scope)->data;

        self::assertSame('Vos propres rétrocommissions', $data['perimetre']);
        foreach ($data['items'] ?? [] as $item) {
            self::assertSame('Alice', $item['beneficiaire']);
        }
    }

    public function testUnInviteOrdinaireNeLitPasLesRetrocommissionsDunCollegue(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['aliceId'], $ids['entrepriseId']);

        $resultat = $this->lecture()->execute(['beneficiaire' => 'Bruno'], $scope);

        // Le REFUS est un STATUT, pas une tournure de phrase : c'est lui que le moteur
        // lit pour ne rien restituer au modèle.
        self::assertSame(
            AiToolResult::STATUS_HORS_PERIMETRE,
            $resultat->status,
            'La rémunération d\'un collègue doit être refusée, pas rendue.',
        );
        self::assertArrayNotHasKey('items', $resultat->data, 'Aucune donnée ne doit fuir avec le refus.');
    }

    public function testUnAgentDuneAutreEntrepriseEstIntrouvable(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        $resultat = $this->lecture()->execute(['beneficiaire' => 'Personne Inconnue'], $scope);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        // UN REFUS DIT QUOI FAIRE. Sans sa note, le modèle n'a rien pour se corriger et
        // finit par inventer une explication — c'est l'impasse polie du 2026-08-12.
        self::assertArrayHasKey('note', $resultat->data);
        self::assertStringContainsString('SANS bénéficiaire', $resultat->data['note']);
    }

    // ===================== 3. L'écriture =====================

    public function testVerserExigeLePrivilegeDeGestion(): void
    {
        $ids = $this->semer();
        $scopeAlice = $this->scope($ids['aliceId'], $ids['entrepriseId']);

        // La garde d'estDisponible() est le MIROIR de celle d'execute() : ne jamais
        // décrire au modèle un outil qui refusera.
        self::assertFalse($this->ecriture()->estDisponible($scopeAlice));

        $resultat = $this->ecriture()->execute(['agentId' => $ids['aliceId']], $scopeAlice);
        self::assertSame(
            AiToolResult::STATUS_HORS_PERIMETRE,
            $resultat->status,
            'Personne ne se paie soi-même.',
        );
    }

    public function testSansMontantExigibleLEcritureEstRefuseeEnLeDisant(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        // Aucune commission encaissée : rien n'est réclamable. L'outil doit REFUSER en
        // expliquant, jamais produire un plan vide que l'utilisateur validerait sans effet.
        $resultat = $this->ecriture()->execute(['agentId' => $ids['aliceId']], $scope);
        $json = json_encode($resultat->data, JSON_UNESCAPED_UNICODE);

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
        // Et le refus DIT pourquoi : sans cela le modèle improviserait une explication.
        self::assertStringContainsString('exigible', mb_strtolower($json, 'UTF-8'));
        self::assertArrayNotHasKey('plan', $resultat->data, 'Aucun plan ne doit être produit.');
    }

    public function testLeGestionnairePeutPreparerUneEcriture(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);

        self::assertTrue($this->ecriture()->estDisponible($scope));
    }

    // ===================== Semis =====================

    private function scope(int $inviteId, int $entrepriseId): AiScope
    {
        $em = $this->em();

        return new AiScope(
            $em->getRepository(Entreprise::class)->find($entrepriseId),
            $em->getRepository(Invite::class)->find($inviteId),
        );
    }

    /**
     * Deux polices dont Alice est BÉNÉFICIAIRE, gérées par un autre invité. Bruno existe
     * pour éprouver la garde de lecture ; il n'est bénéficiaire de rien.
     *
     * @return array{entrepriseId:int, gestionnaireId:int, aliceId:int, brunoId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('KetRetro Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        // Propriétaire de l'espace : c'est lui qui gère les invités.
        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $alice = (new Invite())->setNom('Alice')->setProprietaire(false);
        $alice->setEntreprise($entreprise);
        $em->persist($alice);

        $bruno = (new Invite())->setNom('Bruno')->setProprietaire(false);
        $bruno->setEntreprise($entreprise);
        $em->persist($bruno);

        $risque = (new Risque())->setCode('RK')->setNomComplet('Risque Ket')->setDescription('Risque de Ket')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Ket')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $condition = (new ConditionPartage())->setNom('Prime apporteur Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_ALICE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);

        for ($i = 0; $i < 2; ++$i) {
            $piste = (new Piste())->setNom('Piste Ket ' . $i)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque ket')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
            $piste->addConditionsPartageAgent($condition);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation Ket ' . $i)->setDuree(365);
            $cotation->setPiste($piste);
            $cotation->setEntreprise($entreprise);
            $em->persist($cotation);

            $chargement = (new ChargementPourPrime())->setNom('Prime ' . $i)->setMontantFlatExceptionel(5000.0);
            $chargement->setEntreprise($entreprise);
            $cotation->addChargement($chargement);
            $em->persist($chargement);

            $typeRevenu = (new TypeRevenu())->setNom('Commission ' . $i)->setMontantflat(self::COMMISSION)
                ->setShared(false)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
            $typeRevenu->setEntreprise($entreprise);
            $em->persist($typeRevenu);

            $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $i)->setTypeRevenu($typeRevenu)->setCotation($cotation);
            $revenu->setEntreprise($entreprise);
            $em->persist($revenu);

            $avenant = (new Avenant())->setReferencePolice('POL-KET-' . $i)->setNumero('0')
                ->setDescription('Police ket')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
        }

        $em->flush();
        $ids = [
            'entrepriseId'   => (int) $entreprise->getId(),
            'gestionnaireId' => (int) $gestionnaire->getId(),
            'aliceId'        => (int) $alice->getId(),
            'brunoId'        => (int) $bruno->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /**
     * LE CHEMIN SIMULÉ DOIT SAVOIR DE QUI ON PARLE.
     *
     * Sans clé d'API — en test comme sur un poste de développement — c'est le moteur simulé
     * qui route, et il n'a que des motifs. Rendre la liste de TOUS les agents à qui demande
     * la rétro de Serge SULA serait une réponse plausible et hors sujet : la pire espèce,
     * celle qu'on ne vérifie pas.
     */
    public function testLeCheminSimuleRetrouveLeNomDicte(): void
    {
        $ids = $this->semer();
        $scope = $this->scope($ids['gestionnaireId'], $ids['entrepriseId']);
        $outil = $this->lecture();

        $args = $outil->match('Peux-tu me faire le décompte de la rétrocommission due à l\'agent Serge SULA ?', $scope);
        self::assertSame('Serge SULA', $args['beneficiaire'] ?? null);
        self::assertSame('agent', $args['type'] ?? null);

        $args = $outil->match('détaille la rétrocommission du partenaire SUNU Courtage affaire par affaire', $scope);
        self::assertSame('SUNU Courtage', $args['beneficiaire'] ?? null);
        self::assertSame('partenaire', $args['type'] ?? null);
        self::assertSame('par_ligne', $args['detail'] ?? null);

        $args = $outil->match('répartis-moi la rétrocommission de l\'agent Serge SULA par assureur', $scope);
        self::assertSame('par_axe', $args['detail'] ?? null);
        self::assertSame('assureur', $args['axe'] ?? null);

        // « À QUI dois-je ? » ne nomme personne : y voir un bénéficiaire fermerait la
        // question au lieu d'y répondre.
        $args = $outil->match('à qui dois-je de la rétrocommission ?', $scope);
        self::assertArrayNotHasKey('beneficiaire', $args ?? []);
    }
}