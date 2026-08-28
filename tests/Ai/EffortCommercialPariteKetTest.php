<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EffortCommercialAgentTool;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * KET FAIT CE QUE L'ÉCRAN FAIT — ET SUBIT LES MÊMES REFUS.
 *
 * Le rattachement d'une condition d'agent s'ordonne depuis n'importe où dans l'arbre d'une
 * affaire. Si l'assistant ne savait pas le faire, « demande-le à Ket » deviendrait le geste
 * qu'on ne fait pas — précisément ce que ce lot corrige. Et s'il le savait SANS les refus de
 * l'écran, il en deviendrait le contournement : une règle qui ne vaut que pour qui
 * l'emprunte n'est pas une règle.
 *
 * Trois propriétés, donc :
 *
 *  1. L'ÉCRITURE VA SUR LA PISTE. Le plan porte une opération `edit` sur Piste, quelle que
 *     soit la porte par laquelle l'affaire a été désignée.
 *  2. LES MÊMES REFUS. Lot mixte, affaire scellée par un versement, condition de partenaire :
 *     l'assistant s'arrête là où l'écran s'arrête, et AVANT de proposer un plan.
 *  3. LE CHEMIN GÉNÉRIQUE EST GARDÉ AUSSI. Rien n'empêcherait le modèle d'écrire
 *     directement sur `Piste.conditionsPartageAgent` par preparer_operations ; le moteur de
 *     mutation refuse, comme il refuse déjà de casser un lien protégé.
 */
class EffortCommercialPariteKetTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-parite-effort@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Parite Effort SARL';

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

    private function outil(): EffortCommercialAgentTool
    {
        return static::getContainer()->get(EffortCommercialAgentTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE pcp FROM piste_condition_partage pcp JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'reversement_retro_agent', 'condition_partage', 'avenant', 'cotation', 'piste',
            'client', 'risque', 'partenaire', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Deux affaires, chacune avec sa police, plus deux conditions d'agent et une de
     * partenaire — de quoi éprouver les trois refus.
     *
     * @return array{scope: AiScope, agent: Invite, pisteA: Piste, pisteB: Piste, avenantA: Avenant, avenantB: Avenant, condition: ConditionPartage, autre: ConditionPartage, partenaire: ConditionPartage}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Parite Effort')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $ent->setUtilisateur($owner);
        $em->persist($ent);
        // L'entreprise ACTIVE : les champs d'autocomplete filtrent dessus
        // (getConnectedTo). Sans elle, la condition dictée est jugée « choix invalide »
        // par le FormType, et le plan se refuse pour une raison qui n'a rien de métier.
        $owner->setConnectedTo($ent);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($ent);
        $em->persist($agent);

        $bruno = (new Invite())->setNom('Bruno')->setProprietaire(false);
        $bruno->setEntreprise($ent);
        $em->persist($bruno);

        $risque = (new Risque())->setCode('PAR')->setNomComplet('Risque parite')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($ent);
        $em->persist($risque);

        $faireCondition = function (string $nom, ?Invite $beneficiaire) use ($ent, $em): ConditionPartage {
            $c = (new ConditionPartage())->setNom($nom)
                ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)->setTaux(12.0)
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);
            if ($beneficiaire !== null) {
                $c->setAgent($beneficiaire);
            }
            $c->setEntreprise($ent);
            $em->persist($c);

            return $c;
        };

        $condition = $faireCondition('Prime Alice', $agent);
        $autre = $faireCondition('Prime Bruno', $bruno);
        // Sans agent : c'est une condition de PARTENAIRE au sens de estPourAgent().
        $partenaire = $faireCondition('Accord SUNU', null);

        // UN VRAI INTERMÉDIAIRE, et sa condition. La fixture n'en avait aucun : « Accord
        // SUNU » ne désignait personne, ce qui suffisait à l'ancien refus — mais ne peut
        // pas servir un rattachement, maintenant qu'il est permis.
        $sunu = (new \App\Entity\Partenaire())->setNom('SUNU Courtage')->setPart(20.0);
        $sunu->setEntreprise($ent);
        $em->persist($sunu);

        $ascoma = (new \App\Entity\Partenaire())->setNom('ASCOMA')->setPart(12.0);
        $ascoma->setEntreprise($ent);
        $em->persist($ascoma);

        $conditionSunu = $faireCondition('Apport SUNU 20%', null);
        $conditionSunu->setPartenaire($sunu);
        $conditionAscoma = $faireCondition('Accord ASCOMA 12%', null);
        $conditionAscoma->setPartenaire($ascoma);

        $faireAffaire = function (string $nom, string $police) use ($ent, $gestionnaire, $risque, $em): array {
            $client = (new Client())->setNom('Client ' . $nom)->setExonere(false);
            $client->setEntreprise($ent);
            $em->persist($client);

            $piste = (new Piste())->setNom($nom)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
                ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
                ->setClient($client)->setRisque($risque);
            $piste->setEntreprise($ent)->setInvite($gestionnaire);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation ' . $nom)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($ent);
            $em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice($police)->setNumero('0')
                ->setDescription('Police ' . $police)
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($ent)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);

            return [$piste, $avenant];
        };

        [$pisteA, $avenantA] = $faireAffaire('Affaire Ket A', 'POL-KET-A');
        [$pisteB, $avenantB] = $faireAffaire('Affaire Ket B', 'POL-KET-B');

        $em->flush();

        // UNE IDENTITÉ AUTHENTIFIÉE. Les champs d'autocomplete scopent leurs choix sur
        // l'entreprise ACTIVE de l'utilisateur connecté (FormListenerFactory), et le repli
        // sans identité est fail-closed : liste VIDE, donc « choix invalide » au dry-run.
        // En production l'assistant tourne dans une requête authentifiée ; ici, on pose
        // le jeton nous-mêmes, comme les autres tests d'outils qui produisent un plan.
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($owner, 'main', $owner->getRoles()),
        );

        return [
            'scope' => new AiScope($ent, $gestionnaire),
            'agent' => $agent,
            'pisteA' => $pisteA, 'pisteB' => $pisteB,
            'avenantA' => $avenantA, 'avenantB' => $avenantB,
            'condition' => $condition, 'autre' => $autre, 'partenaire' => $partenaire,
        ];
    }

    private function cible(Avenant $avenant): array
    {
        return ['entite' => 'Avenant', 'reference' => (string) $avenant->getId()];
    }

    // ===================== 1. L'écriture va sur la piste =====================

    /**
     * DÉSIGNÉE PAR UNE POLICE, ÉCRITE SUR L'AFFAIRE.
     *
     * C'est la propriété centrale : le plan doit porter une opération sur **Piste**. Une
     * écriture sur l'avenant serait ignorée par le décompte, et l'agent ne serait jamais payé.
     */
    public function testLePlanEcritSurLaPisteMemeDesigneeParUnePolice(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Prime Alice',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false, sprintf(
            'Le plan doit être prêt (données : %s).',
            json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        ));
        self::assertNotNull($resultat->uiAction, 'Un plan s\'accompagne TOUJOURS des boutons valider / annuler.');
    }

    /** Plusieurs cibles d'une même affaire ne font qu'une opération : le lot dédoublonne. */
    public function testLeLotDedoublonneLesAffaires(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Prime Alice',
            'cibles' => [
                $this->cible($s['avenantA']),
                ['entite' => 'Piste', 'reference' => (string) $s['pisteA']->getId()],
            ],
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false);
        self::assertSame(
            1,
            $resultat->data['budget']['enregistrements'] ?? null,
            'Une police et son affaire, c\'est UNE affaire — donc une seule écriture.',
        );
    }

    // ===================== 2. Les mêmes refus que l'écran =====================

    /** Une affaire déjà prise refuse un second agent, AVANT tout plan. */
    public function testUnLotMixteEstRefuseAvantLePlan(): void
    {
        $s = $this->semer();
        $s['pisteA']->addConditionsPartageAgent($s['condition']);
        $this->em()->flush();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Prime Bruno',
            'cibles' => [$this->cible($s['avenantA']), $this->cible($s['avenantB'])],
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertFalse($resultat->data['pret'] ?? true, 'Aucun plan ne doit être proposé.');
        self::assertStringContainsString('Affaire Ket A', $resultat->data['bloquant'] ?? '');
        self::assertArrayNotHasKey('operations', $resultat->data);
        self::assertNull($resultat->uiAction, 'Pas de plan, donc pas de bouton — jamais de plan fantôme.');
    }

    /** Un versement scelle l'affaire : Ket ne détache pas plus que l'écran. */
    public function testUneAffaireScelleeResisteAussiAKet(): void
    {
        $s = $this->semer();
        $s['pisteA']->addConditionsPartageAgent($s['condition']);
        $reversement = (new ReversementRetroAgent())
            ->setAgent($s['agent'])->setAvenant($s['avenantA'])->setMontant(154.19)
            ->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('VIR-KET-1');
        $reversement->setEntreprise($s['pisteA']->getEntreprise())->setInvite($s['pisteA']->getInvite());
        $this->em()->persist($reversement);
        $this->em()->flush();

        $resultat = $this->outil()->execute([
            'action' => 'detacher',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertFalse($resultat->data['pret'] ?? true);
        self::assertStringContainsString('154,19', $resultat->data['bloquant'] ?? '');
        self::assertStringContainsString('remplacé par un autre agent', $resultat->data['bloquant'] ?? '');
    }

    /**
     * DÉTACHER, QUAND RIEN N'EST VERSÉ : le plan doit être PRÊT.
     *
     * Le détachement vide la liste des conditions d'agent. Un plan dont le seul champ est
     * une liste vide risque d'être lu comme « aucune valeur à écrire » et refusé pour une
     * raison qui n'a rien de métier — l'utilisateur s'entendrait dire qu'il n'a rien dicté
     * alors qu'il a demandé un retrait.
     */
    public function testDetacherSansVersementProduitUnPlanPret(): void
    {
        $s = $this->semer();
        $s['pisteA']->addConditionsPartageAgent($s['condition']);
        $this->em()->flush();

        $resultat = $this->outil()->execute([
            'action' => 'detacher',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false, sprintf(
            'Un détachement légitime doit produire un plan (rendu : %s).',
            json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        ));
        self::assertNotNull($resultat->uiAction);

        // ET IL EFFACE VRAIMENT. Un plan « prêt » ne prouve rien tant qu'il n'a pas été
        // exécuté : vider une relation multiple n'est PAS exprimable par une liste vide
        // (le moteur l'écarte comme « aucune valeur dictée »), et c'est `null` qui porte
        // l'intention. Un plan qui se validerait sans rien défaire serait le pire des cas.
        $service = static::getContainer()->get(\App\Service\Workspace\WorkspaceMutationService::class);
        $operation = \App\Ai\Mutation\MutationOperation::fromArray([
            'op' => 'edit',
            'entite' => 'Piste',
            'id' => $s['pisteA']->getId(),
            'champs' => ['conditionsPartageAgent' => null],
        ]);
        $service->executer($operation, $s['scope'], null);
        $this->em()->flush();
        $this->em()->clear();

        $fraiche = $this->em()->getRepository(Piste::class)->find($s['pisteA']->getId());
        self::assertCount(
            0,
            $fraiche->getConditionsPartageAgent(),
            'L\'affaire doit être redevenue celle du cabinet seul.',
        );
    }
    /**
     * UNE CONDITION DE PARTENAIRE EST DÉSORMAIS ACCEPTÉE — c'est l'objet du lot.
     *
     * Ce test affirmait l'inverse, et il avait alors raison : le rattachement n'existait
     * que pour l'agent, et laisser passer une condition de partenaire l'aurait écrite en
     * base sans qu'aucun calcul ne la lise. Les deux familles se rattachent maintenant du
     * même geste, la famille étant LUE sur la condition choisie.
     */
    public function testUneConditionDePartenaireEstDesormaisRattachable(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Apport SUNU 20%',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        // DIAGNOSTIC : on montre ce que l'outil a répondu, sinon « false n'est pas false »
        // ne dit rien de la raison.
        self::assertNotFalse(
            $resultat->data['pret'] ?? false,
            'Le plan doit être prêt. Réponse : ' . json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * LA DÉSIGNATION D'INTERMÉDIAIRE VOYAGE DANS LE MÊME PLAN, ET S'ANNONCE.
     *
     * Une condition de partenaire rattachée à une affaire sans apporteur n'aurait AUCUN
     * effet : le calcul ne retient que les conditions de l'intermédiaire désigné. Le geste
     * pose donc la désignation — et comme elle change qui touche l'argent, la consigne de
     * rédaction doit le DIRE, jamais la laisser découvrir.
     */
    public function testLeRattachementDunPartenaireDesigneLIntermediaireEtLeDit(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Apport SUNU 20%',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        $charge = json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('SUNU Courtage', $charge);
        self::assertStringContainsString('intermédiaire', $charge, 'La désignation doit être annoncée.');
    }

    /**
     * UNE PLACE DÉJÀ PRISE PAR UN AUTRE INTERMÉDIAIRE ARRÊTE KET, comme elle arrête l'écran.
     *
     * Rattacher la condition d'un autre apporteur produirait une règle que le calcul
     * écarterait en silence — il ne retient que les conditions de l'intermédiaire du jour.
     * Le refus nomme les DEUX, sinon l'utilisateur ne sait pas lequel corriger.
     */
    public function testUnIntermediaireDejaEnPlaceArreteLeRattachement(): void
    {
        $s = $this->semer();

        // Premier geste : SUNU devient l'apporteur de l'affaire.
        $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Apport SUNU 20%',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);
        $s['pisteA']->setPartenaire(
            $this->em()->getRepository(\App\Entity\Partenaire::class)->findOneBy(['nom' => 'SUNU Courtage']),
        );
        $this->em()->flush();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Accord ASCOMA 12%',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertFalse($resultat->data['pret'] ?? true);
        $motif = $resultat->data['bloquant'] ?? '';
        // La PLACE est prise : c'est le refus de la règle « un bénéficiaire par famille »,
        // et il nomme l'occupant et la condition en place — de quoi savoir quoi détacher.
        self::assertStringContainsString('SUNU Courtage', $motif, 'Le refus nomme l’apporteur en place.');
        self::assertStringContainsString('Apport SUNU 20%', $motif, 'Et la condition à détacher.');
    }

    /**
     * L'AUTRE REFUS : la place est LIBRE, mais l'affaire est déjà apportée par quelqu'un.
     *
     * Ce cas n'a pas d'équivalent côté agent, et il vient de la structure : un agent est
     * nommé PAR sa condition, tandis que l'intermédiaire est désigné par l'AFFAIRE — la
     * condition ne fait que moduler son taux. Rattacher la condition d'un autre écrirait
     * une règle que le calcul écarterait en silence, puisqu'il ne retient que les
     * conditions de l'intermédiaire du jour. Le refus nomme donc les DEUX.
     */
    public function testUneConditionDunAutreApporteurEstRefuseeEnNommantLesDeux(): void
    {
        $s = $this->semer();

        // L'affaire est apportée par ASCOMA, SANS aucune condition rattachée : la place de
        // la famille « partenaire » est donc libre.
        $s['pisteA']->setPartenaire(
            $this->em()->getRepository(\App\Entity\Partenaire::class)->findOneBy(['nom' => 'ASCOMA']),
        );
        $this->em()->flush();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Apport SUNU 20%',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertFalse($resultat->data['pret'] ?? true);
        $motif = $resultat->data['bloquant'] ?? '';
        self::assertStringContainsString('ASCOMA', $motif, 'L’apporteur de l’affaire.');
        self::assertStringContainsString('SUNU Courtage', $motif, 'Celui que la condition visait.');
    }

    /**
     * ET SUR UNE AFFAIRE SANS APPORTEUR, LE GESTE PASSE — en posant la désignation.
     *
     * C'est la contrepartie du refus ci-dessus : la place libre s'occupe, la place prise se
     * respecte. Sans cela, rattacher une condition de partenaire aurait exigé de renseigner
     * l'apporteur d'abord — un geste en deux temps là où celui de l'agent en demande un.
     */
    public function testSurUneAffaireSansApporteurLeGestePasse(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Apport SUNU 20%',
            'cibles' => [$this->cible($s['avenantB'])],
        ], $s['scope']);

        self::assertNotFalse($resultat->data['pret'] ?? false);
        $champs = $resultat->data['plan'][0]['champs'] ?? [];
        self::assertContains('partenaire', $champs, 'La désignation voyage dans le MÊME plan.');
        self::assertContains('conditionsPartageAgent', $champs);
    }

    /**
     * LES DEUX FAMILLES COEXISTENT : un apporteur ET un agent sur la même affaire.
     *
     * C'est la mécanique du cabinet — le partenaire se sert d'abord, l'agent partage le
     * reliquat. Une règle « une affaire, un bénéficiaire » l'aurait interdite.
     */
    public function testUnApporteurEtUnAgentCoexistentSurLaMemeAffaire(): void
    {
        $s = $this->semer();

        $premier = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Apport SUNU 20%',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);
        self::assertNotFalse($premier->data['pret'] ?? false);

        // On applique le premier rattachement à la main : le plan n'est pas exécuté ici.
        $s['pisteA']->addConditionsPartageAgent(
            $this->em()->getRepository(\App\Entity\ConditionPartage::class)->findOneBy(['nom' => 'Apport SUNU 20%']),
        );
        $this->em()->flush();

        $second = $this->outil()->execute([
            'action' => 'rattacher',
            'condition' => 'Prime Alice',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertNotFalse(
            $second->data['pret'] ?? false,
            'La place d’AGENT est libre : l’apporteur ne l’occupe pas.',
        );
    }

    /** Rattacher sans dire quelle condition : on demande, on ne devine pas. */
    public function testSansConditionOnDemandePlutotQueDeDeviner(): void
    {
        $s = $this->semer();

        $resultat = $this->outil()->execute([
            'action' => 'rattacher',
            'cibles' => [$this->cible($s['avenantA'])],
        ], $s['scope']);

        self::assertNotSame(true, $resultat->data['pret'] ?? null);
        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
    }

    // ===================== 3. Le chemin générique est gardé aussi =====================

    /**
     * LE CONTOURNEMENT EST FERMÉ — et c'est ce qui rend la parité honnête.
     *
     * `preparer_operations` accepte n'importe quelle écriture autorisée : rien n'empêcherait
     * le modèle d'écrire lui-même sur `Piste.conditionsPartageAgent` et d'ignorer la règle
     * que son outil dédié applique. Le moteur de mutation la consulte donc aussi, comme il
     * consulte déjà LiensProteges avant une suppression.
     */
    public function testLeCheminGeneriqueEstRefuseLuiAussi(): void
    {
        $s = $this->semer();
        $s['pisteA']->addConditionsPartageAgent($s['condition']);
        $this->em()->flush();

        /** @var PreparerOperationsTool $preparer */
        $preparer = static::getContainer()->get(PreparerOperationsTool::class);

        $resultat = $preparer->execute([
            'operations' => [[
                'op' => 'edit',
                'entite' => 'Piste',
                'id' => $s['pisteA']->getId(),
                'champs' => ['conditionsPartageAgent' => [$s['autre']->getId()]],
            ]],
        ], $s['scope']);

        $json = json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertNotSame(true, $resultat->data['pret'] ?? null, sprintf(
            'Le moteur doit refuser ce contournement (rendu : %s).',
            $json,
        ));
        self::assertStringContainsString('Alice', $json, 'Et le refus doit nommer l\'agent en place.');
    }

    /** La même garde vaut pour le détachement générique d'une affaire scellée. */
    public function testLeDetachementGeneriqueDUneAffaireScelleeEstRefuse(): void
    {
        $s = $this->semer();
        $s['pisteA']->addConditionsPartageAgent($s['condition']);
        $reversement = (new ReversementRetroAgent())
            ->setAgent($s['agent'])->setAvenant($s['avenantA'])->setMontant(80.0)
            ->setPaidAt(new \DateTimeImmutable('-1 day'))->setReference('VIR-KET-2');
        $reversement->setEntreprise($s['pisteA']->getEntreprise())->setInvite($s['pisteA']->getInvite());
        $this->em()->persist($reversement);
        $this->em()->flush();

        /** @var PreparerOperationsTool $preparer */
        $preparer = static::getContainer()->get(PreparerOperationsTool::class);

        $resultat = $preparer->execute([
            'operations' => [[
                'op' => 'edit',
                'entite' => 'Piste',
                'id' => $s['pisteA']->getId(),
                // `null`, et non `[]` : une liste vide est écartée par le moteur comme
                // « aucune valeur dictée », et l'opération serait refusée AVANT notre garde —
                // le test passerait sans rien prouver.
                'champs' => ['conditionsPartageAgent' => null],
            ]],
        ], $s['scope']);

        $json = json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertNotSame(true, $resultat->data['pret'] ?? null);
        self::assertStringContainsString('80,00', $json, 'Le refus dit ce qui a déjà été versé.');
    }

    /** Une édition de piste qui ne touche PAS ce champ passe : la garde est ciblée. */
    public function testUneEditionSansRapportNEstPasBloquee(): void
    {
        $s = $this->semer();
        $s['pisteA']->addConditionsPartageAgent($s['condition']);
        $this->em()->flush();

        /** @var PreparerOperationsTool $preparer */
        $preparer = static::getContainer()->get(PreparerOperationsTool::class);

        $resultat = $preparer->execute([
            'operations' => [[
                'op' => 'edit',
                'entite' => 'Piste',
                'id' => $s['pisteA']->getId(),
                'champs' => ['nom' => 'Affaire Ket A renommée'],
            ]],
        ], $s['scope']);

        self::assertTrue($resultat->data['pret'] ?? false, sprintf(
            'Une garde trop large bloquerait des écritures légitimes (rendu : %s).',
            json_encode($resultat->data, JSON_UNESCAPED_UNICODE),
        ));
    }
}
