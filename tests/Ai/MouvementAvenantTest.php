<?php

namespace App\Tests\Ai;

use App\Ai\Mouvement\MouvementAvenant;
use App\Ai\Mouvement\MouvementAvenantBuilder;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerMouvementAvenantTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\DashboardDataProvider;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * LES QUATRE MOUVEMENTS D'UNE POLICE, EN ZÉRO QUESTION.
 *
 * Ce que ces tests protègent, dans l'ordre d'importance :
 *  1. un RENOUVELLEMENT ne demande RIEN : le premier appel de l'outil renvoie
 *     « pret » avec zéro manquant et zéro question — c'est la promesse tenue ;
 *  2. le décalque est EXACT : période, prorata, échéancier décalé, reconduction
 *     des partenaires et des conditions de partage, tâches NON reprises ;
 *  3. l'exécution referme la boucle métier : la police bascule de statut et sort
 *     de la vigie des échéances (sans quoi la boussole la réclamerait encore).
 */
class MouvementAvenantTest extends WebTestCase
{
    use ClockSensitiveTrait;

    /**
     * « Aujourd'hui » du builder, FIGÉ : les fixtures datent la police de base de 2026, et
     * le builder juge désormais si une période est déjà échue. Sans horloge figée, ces
     * tests changeraient de sens le jour où 2027 serait passé.
     */
    private const AUJOURDHUI = '2026-10-09 10:00:00';

    private const ENT   = 'PHPUnit-KetMouvement';
    private const OWNER = 'phpunit-ketmouvement-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PreparerMouvementAvenantTool $outil;
    private MouvementAvenantBuilder $builder;
    private WorkspaceMutationService $mutation;

    protected function setUp(): void
    {
        self::mockTime(self::AUJOURDHUI);
        $this->client   = static::createClient();
        $this->em       = static::getContainer()->get(EntityManagerInterface::class);
        $this->outil    = static::getContainer()->get(PreparerMouvementAvenantTool::class);
        $this->builder  = static::getContainer()->get(MouvementAvenantBuilder::class);
        $this->mutation = static::getContainer()->get(WorkspaceMutationService::class);
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
        $n = self::ENT;
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        // Cycle de FK Avenant ↔ Piste : dissocier les deux liens croisés d'abord.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :n', ['n' => $n]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :n', ['n' => $n]);
        // Les risques CIBLÉS vivent désormais dans la table de liaison condition_partage_risque,
        // dont les deux clés étrangères sont en ON DELETE CASCADE : supprimer une condition
        // emporte ses rattachements, et le risque reste au catalogue. Plus rien à dissocier ici.

        foreach ([
            'DELETE f FROM feedback f JOIN tache t ON f.tache_id = t.id JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE t FROM tache t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n',
            // Mouvements financiers posés sur un successeur (règle d'abandon).
            'DELETE pp FROM paiement_prime pp JOIN entreprise e ON pp.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE p FROM paiement p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ar FROM article ar JOIN entreprise e ON ar.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE n FROM note n JOIN entreprise e ON n.entreprise_id = e.id WHERE e.nom = :n',
            // Sinistres du ratio S/P (avant les clients, qui en sont les assurés).
            'DELETE o FROM offre_indemnisation_sinistre o JOIN entreprise e ON o.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ns FROM notification_sinistre ns JOIN entreprise e ON ns.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE cp FROM condition_partage cp JOIN entreprise e ON cp.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE a FROM avenant a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE tr FROM tranche tr JOIN entreprise e ON tr.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE cpp FROM chargement_pour_prime cpp JOIN entreprise e ON cpp.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE r FROM revenu_pour_courtier r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE co FROM cotation co JOIN entreprise e ON co.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE tv FROM type_revenu tv JOIN entreprise e ON tv.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ch FROM chargement ch JOIN entreprise e ON ch.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE pa FROM partenaire pa JOIN entreprise e ON pa.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ri FROM risque ri JOIN entreprise e ON ri.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ct FROM contact ct JOIN entreprise e ON ct.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE g FROM groupe g JOIN entreprise e ON g.entreprise_id = e.id WHERE e.nom = :n',
            // Après les clients (qui portent la clé étrangère), avant les invités (gestionnaires).
            'DELETE pf FROM portefeuille pf JOIN entreprise e ON pf.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE ass FROM assureur ass JOIN entreprise e ON ass.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n',
        ] as $sql) {
            $conn->executeStatement($sql, ['n' => $n]);
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $n]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Police de base COMPLÈTE : 01/01/2026 → 31/12/2026 (365 j), prime 12 000
     * (10 000 + 1 600 + 400), deux tranches, un revenu, un partenaire, deux
     * conditions de partage (une applicable, une non), et une tâche avec son
     * compte-rendu — la tâche est là pour vérifier qu'elle n'est JAMAIS reprise.
     *
     * @return array{ent: Entreprise, inv: Invite, user: Utilisateur, base: Avenant, piste: Piste, cotation: Cotation, autreRisque: Risque}
     */
    private function seed(): array
    {
        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $this->em->persist($ent);
        $user->setConnectedTo($ent);

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($user)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $scoper = function (object $e) use ($ent, $inv): object {
            $e->setEntreprise($ent);
            if (method_exists($e, 'setInvite')) {
                $e->setInvite($inv);
            }
            $this->em->persist($e);

            return $e;
        };

        $client   = $scoper((new Client())->setNom('ACME Mouvement')->setExonere(false));
        $assureur = $scoper((new Assureur())
            ->setNom('Assureur MVT')->setEmail('mvt@assureur.test')
            ->setNumimpot('IMP-MVT')->setIdnat('IDNAT-MVT')->setRccm('RCCM-MVT'));
        $risque   = $scoper((new Risque())
            ->setNomComplet('Incendie MVT')->setCode('MVT-RQ')->setDescription('Risque incendie')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true));
        $autreRisque = $scoper((new Risque())
            ->setNomComplet('Auto MVT')->setCode('MVT-RQ2')->setDescription('Risque auto')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true));
        $partenaire = $scoper((new Partenaire())->setNom('Partenaire MVT')->setPart(35.0));

        $typePrimeNette = $scoper((new Chargement())->setNom('Prime nette'));
        $typeTva        = $scoper((new Chargement())->setNom('TVA'));
        $typeRevenu     = $scoper((new TypeRevenu())
            ->setNom('Commission IARD')->setPourcentage(16.0)
            ->setShared(true)->setMultipayments(false)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR)
            ->setModeCalcul(TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT)
            ->setTypeChargement($typePrimeNette));

        $piste = $scoper((new Piste())
            ->setNom('Incendie ACME 2026')
            ->setClient($client)->setRisque($risque)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Entrepôt principal')
            ->setExercice(2026)
            ->setRenewalCondition(Piste::RENEWAL_CONDITION_RENEWABLE));
        $piste->setPartenaire($partenaire);

        // Condition APPLICABLE (générale, sans ciblage de risque) : doit être reconduite.
        $piste->addConditionsPartageExceptionnelle($scoper((new ConditionPartage())
            ->setNom('Rétro 35 %')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(35.0)
            ->setUniteMesure(ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire)));

        // Condition NON applicable au risque de la piste : ne doit PAS être reconduite.
        $exclue = $scoper((new ConditionPartage())
            ->setNom('Rétro Auto seulement')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(10.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($partenaire));
        $exclue->addProduit($autreRisque);
        $piste->addConditionsPartageExceptionnelle($exclue);

        $cotation = $scoper((new Cotation())->setNom('Offre Incendie 2026')->setDuree(12)->setAssureur($assureur));
        $cotation->setPiste($piste);

        foreach ([['Prime nette', 10000.0, $typePrimeNette], ['TVA', 1600.0, $typeTva], ['Frais ARCA', 400.0, $typeTva]] as [$nom, $montant, $type]) {
            $ch = $scoper((new ChargementPourPrime())->setNom($nom)->setMontantFlatExceptionel($montant)->setType($type));
            $cotation->addChargement($ch);
        }
        foreach ([['T1', 60.0, '2026-01-15'], ['T2', 40.0, '2026-07-15']] as [$nom, $pct, $date]) {
            $tr = $scoper((new Tranche())->setNom($nom)->setPourcentage($pct)->setPayableAt(new DateTimeImmutable($date)));
            $cotation->addTranche($tr);
        }
        $cotation->addRevenu($scoper((new RevenuPourCourtier())
            ->setNom('Commission courtier')->setTypeRevenu($typeRevenu)->setTauxExceptionel(18.0)));

        // Tâche + compte-rendu de l'exercice écoulé : ils ne doivent JAMAIS suivre.
        $tache = $scoper((new Tache())
            ->setDescription('Relancer ACME sur la police 2026')
            ->setToBeEndedAt(new DateTimeImmutable('2026-02-01'))
            ->setClosed(false));
        $tache->setCotation($cotation);
        $tache->addFeedback($scoper((new Feedback())->setDescription('Client relancé le 10/01')->setType(0)));

        $base = $scoper((new Avenant())
            ->setReferencePolice('POL-MVT-1')
            ->setNumero('1')
            ->setDescription('Police incendie ACME')
            ->setStartingAt(new DateTimeImmutable('2026-01-01'))
            ->setEndingAt(new DateTimeImmutable('2026-12-31'))
            ->setCotation($cotation));

        $this->em->flush();
        $this->client->loginUser($user);

        return compact('ent', 'inv', 'user', 'base', 'piste', 'cotation', 'autreRisque', 'partenaire');
    }

    private function scope(array $s): AiScope
    {
        return new AiScope($s['ent'], $s['inv']);
    }

    /** Retrouve une opération du décalque par son entité. */
    private function op(array $decalque, string $entite, string $op = 'create'): array
    {
        foreach ($decalque['operations'] as $operation) {
            if ($operation['entite'] === $entite && $operation['op'] === $op) {
                return $operation;
            }
        }
        self::fail(sprintf('Aucune opération « %s %s » dans le décalque.', $op, $entite));
    }

    /** Éléments d'une collection d'une opération. */
    private function collection(array $operation, string $nom): array
    {
        foreach ($operation['collections'] ?? [] as $collection) {
            if ($collection['collection'] === $nom) {
                return $collection['elements'];
            }
        }

        return [];
    }

    // ───────────────────────── 1. La promesse : zéro question ─────────────────────────

    /**
     * LE test de la fonctionnalité : « renouvelle cette police » sans autre
     * argument produit un plan PRÊT — aucun champ manquant, aucune question, et
     * les boutons de validation (uiAction) sont là.
     */
    public function testRenouvellementNeDemandeAucuneInformation(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertTrue($resultat->data['pret'] ?? false, sprintf(
            'Le plan doit être prêt du premier coup (manquants : %s).',
            json_encode($resultat->data['manquants'] ?? [], JSON_UNESCAPED_UNICODE),
        ));
        $this->assertEmpty($resultat->data['manquants'] ?? [], 'Un renouvellement à l’identique ne manque de rien.');
        $this->assertArrayNotHasKey('aDemander', $resultat->data, 'Aucune question ne doit être posée.');
        $this->assertNotNull($resultat->uiAction, 'Un plan s’accompagne TOUJOURS des boutons valider / annuler.');
        $this->assertNotEmpty($resultat->data['defauts'] ?? [], 'Les défauts appliqués doivent être restitués pour être annoncés.');
        $this->assertSame([], $resultat->data['ecarts'] ?? null, 'Sans consigne particulière, il n’y a aucun écart.');
    }

    /**
     * TOUT ce que le mouvement écrira figure dans le plan ET dans le budget : la
     * piste dérivée, ses DEUX conditions de partage, la cotation, ses 3 composantes
     * de prime, ses 2 tranches, son revenu, la tâche de suivi, le nouvel avenant et
     * la mise à jour de la police de base. Treize écritures, treize facturées —
     * rien ne se glisse hors du chiffrage.
     */
    public function testToutFigureDansLePlanEtDansLeBudget(): void
    {
        $s = $this->seed();
        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['pret']);
        $this->assertSame(13, $resultat->data['budget']['enregistrements'], sprintf(
            'Le budget doit couvrir les 13 enregistrements du plan (ventilation : %s).',
            json_encode($resultat->data['budget']['parEtape'], JSON_UNESCAPED_UNICODE),
        ));
        $this->assertGreaterThan(0, $resultat->data['budget']['coutEstime'], 'Un coût est réellement chiffré.');

        // Les deux étapes du plan sont inventoriées, et le socle est indécochable.
        $etapes = [];
        foreach ($resultat->data['etapes'] as $etape) {
            $etapes[$etape['libelle']] = $etape;
        }
        $this->assertArrayHasKey('Renouvellement de la police', $etapes);
        $this->assertTrue($etapes['Renouvellement de la police']['obligatoire']);
        $this->assertArrayHasKey(MouvementAvenantBuilder::ETAPE_TACHE, $etapes);
        $this->assertFalse($etapes[MouvementAvenantBuilder::ETAPE_TACHE]['obligatoire']);

        // L'aperçu montré au-dessus des boutons nomme la reconduction des conditions
        // de partage, en FRANÇAIS — c'est ce que l'utilisateur lit avant de valider.
        $apercu = json_encode($resultat->uiAction['apercu'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('"libelle":"Conditions de partage","creations":2', $apercu);
        $this->assertStringContainsString('"libelle":"Composition de la prime","creations":3', $apercu);
        $this->assertStringNotContainsString('conditionsPartageExceptionnelles', $apercu, 'Aucun nom technique sous les yeux de l’utilisateur.');
    }

    /** Les trois autres mouvements exigent une date — et c'est la SEULE question. */
    public function testProrogationEtResiliationReclamentLeurSeuleInformation(): void
    {
        $s = $this->seed();
        $scope = $this->scope($s);
        $id = $s['base']->getId();

        $prorogation = $this->outil->execute(['mouvement' => 'prorogation', 'avenantId' => $id], $scope);
        $this->assertFalse($prorogation->data['pret'] ?? true);
        $this->assertSame('duree', $prorogation->data['aDemander'][0]['champ'] ?? null);
        $this->assertNull($prorogation->uiAction, 'Sans plan, aucun bouton de validation.');

        $resiliation = $this->outil->execute(['mouvement' => 'resiliation', 'avenantId' => $id], $scope);
        $this->assertSame('dateEffet', $resiliation->data['aDemander'][0]['champ'] ?? null);

        // Fournie, la date suffit : plus aucune question.
        $avecDate = $this->outil->execute(
            ['mouvement' => 'resiliation', 'avenantId' => $id, 'dateEffet' => '2026-06-15'],
            $scope,
        );
        $this->assertTrue($avecDate->data['pret'] ?? false, 'La date fournie, le plan est prêt.');
        $this->assertArrayNotHasKey('aDemander', $avecDate->data);
    }

    // ───────────────────────── 2. L'exactitude du décalque ─────────────────────────

    /** Renouvellement : lendemain de l'échéance, même durée, tout reconduit à l'identique. */
    public function testDecalqueDUnRenouvellement(): void
    {
        $s = $this->seed();
        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s));

        $avenant = $this->op($d, 'Avenant')['champs'];
        $this->assertStringStartsWith('2027-01-01', $avenant['startingAt'], 'La couverture reprend le lendemain de l’échéance.');
        $this->assertStringStartsWith('2027-12-31', $avenant['endingAt'], 'Sur une durée identique à la police de base.');
        $this->assertSame('POL-MVT-1', $avenant['referencePolice'], 'La référence de police est reconduite.');
        $this->assertSame('2', $avenant['numero'], 'Le numéro d’avenant est incrémenté.');

        $piste = $this->op($d, 'Piste')['champs'];
        $this->assertSame((string) Piste::AVENANT_RENOUVELLEMENT, $piste['typeAvenant']);
        $this->assertSame('Renouvellement — Incendie ACME 2026', $piste['nom']);
        $this->assertSame(2027, $piste['exercice']);
        $this->assertSame($s['base']->getId(), $piste['avenantDeBase'], 'La piste dérivée pointe la police de base.');
        $this->assertSame($s['partenaire']->getId(), $piste['partenaire'], 'L\'intermédiaire est reconduit.');

        // Conditions de partage : TOUTES suivent — un engagement de rétrocommission
        // ne se perd pas au changement d'exercice.
        $conditions = $this->collection($this->op($d, 'Piste'), 'conditionsPartageExceptionnelles');
        $this->assertCount(2, $conditions, 'Les deux conditions de partage sont reconduites, aucune abandonnée.');
        $noms = array_map(static fn (array $c) => $c['champs']['nom'], $conditions);
        $this->assertEqualsCanonicalizing(['Rétro 35 %', 'Rétro Auto seulement'], $noms);

        $parNom = [];
        foreach ($conditions as $condition) {
            $parNom[$condition['champs']['nom']] = $condition['champs'];
        }
        // L'applicable garde son effet (condition générale, même taux)…
        $this->assertSame(35.0, $parNom['Rétro 35 %']['taux'], 'Le taux reste en POINTS, recopié brut.');
        $this->assertSame((string) ConditionPartage::CRITERE_PAS_RISQUES_CIBLES, $parNom['Rétro 35 %']['critereRisque']);
        // …celle qui ne s'appliquait pas est reconduite sous forme NEUTRE, sans
        // inventer une rétrocommission qui n'existait pas.
        $this->assertSame(10.0, $parNom['Rétro Auto seulement']['taux'], 'Le taux promis est conservé.');
        $this->assertSame((string) ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES, $parNom['Rétro Auto seulement']['critereRisque']);
        $this->assertNotEmpty(
            array_filter($d['avertissements'], static fn (string $a) => str_contains($a, 'Rétro Auto seulement')),
            'La neutralisation doit être annoncée, pas subie.',
        );
        $this->assertSame(2, $d['reconduit']['conditions'], 'Le décompte du plan et du budget couvre les deux.');

        $cotation = $this->op($d, 'Cotation');
        $chargements = $this->collection($cotation, 'chargements');
        $this->assertCount(3, $chargements);
        $this->assertSame(10000.0, $chargements[0]['champs']['montantFlatExceptionel'], 'Prime reconduite à l’identique.');
        $this->assertArrayHasKey('type', $chargements[0]['champs'], 'Sans type de chargement, la commission retomberait à 0.');

        // Échéancier décalé du même écart que la période (365 j).
        $tranches = $this->collection($cotation, 'tranches');
        $this->assertCount(2, $tranches);
        $this->assertStringStartsWith('2027-01-15', $tranches[0]['champs']['payableAt']);
        $this->assertStringStartsWith('2027-07-15', $tranches[1]['champs']['payableAt']);
        $this->assertSame(60.0, $tranches[0]['champs']['pourcentage']);

        $revenus = $this->collection($cotation, 'revenus');
        $this->assertCount(1, $revenus);
        $this->assertSame(18.0, $revenus[0]['champs']['tauxExceptionel'], 'Un taux ne se proratise pas.');

        // Le lien retour sur la police de base, et son statut « Renouvelé » : resté « En
        // cours », elle serait comptée parmi les polices actives à côté de son successeur.
        $lien = $this->op($d, 'Avenant', 'edit')['champs'];
        $this->assertSame('@mouvement', $lien['pisteDeRenouvellement']);
        $this->assertSame((string) Avenant::RENEWAL_STATUS_RENEWED, $lien['renewalStatus']);
    }

    /** Prorogation : prime au prorata des jours, échéancier réduit à une tranche. */
    public function testDecalqueDUneProrogationAuProrata(): void
    {
        $s = $this->seed();
        $d = $this->builder->construire(MouvementAvenant::Prorogation, $s['base'], ['dureeJours' => 20], $this->scope($s));

        $avenant = $this->op($d, 'Avenant')['champs'];
        $this->assertStringStartsWith('2027-01-01', $avenant['startingAt']);
        $this->assertStringStartsWith('2027-01-20', $avenant['endingAt'], '20 jours, bornes incluses.');
        $this->assertSame('Prorogation de 20 jours', $avenant['description']);

        // 20 / 365 = 0,054794… — appliqué à chaque composante.
        $chargements = $this->collection($this->op($d, 'Cotation'), 'chargements');
        $this->assertSame(547.95, $chargements[0]['champs']['montantFlatExceptionel'], 'Prime nette au prorata.');
        $this->assertSame(87.67, $chargements[1]['champs']['montantFlatExceptionel'], 'TVA au prorata.');
        $this->assertSame(21.92, $chargements[2]['champs']['montantFlatExceptionel'], 'Frais ARCA au prorata.');

        $tranches = $this->collection($this->op($d, 'Cotation'), 'tranches');
        $this->assertCount(1, $tranches, 'Une prorogation courte n’a pas d’échéancier.');
        $this->assertSame('Prime de prorogation', $tranches[0]['champs']['nom']);
        $this->assertSame(100, $tranches[0]['champs']['pourcentage']);

        $this->assertSame((string) Piste::AVENANT_PROROGATION, $this->op($d, 'Piste')['champs']['typeAvenant']);
    }

    /** Annulation / résiliation : un acte daté, sans prime, qui met fin à la police. */
    public function testDecalqueDUneResiliationNePorteAucunePrime(): void
    {
        $s = $this->seed();
        $d = $this->builder->construire(MouvementAvenant::Resiliation, $s['base'], ['dateEffet' => '2026-06-15'], $this->scope($s));

        $avenant = $this->op($d, 'Avenant')['champs'];
        $this->assertStringStartsWith('2026-06-15', $avenant['startingAt']);
        $this->assertStringStartsWith('2026-06-15', $avenant['endingAt']);
        $this->assertSame('Résiliation au 15/06/2026', $avenant['description']);

        $cotation = $this->op($d, 'Cotation');
        $this->assertSame([], $this->collection($cotation, 'chargements'), 'Aucune prime portée par l’acte.');
        $this->assertSame([], $this->collection($cotation, 'tranches'));
        $this->assertSame([], $this->collection($cotation, 'revenus'));
        $this->assertSame([], $this->collection($cotation, 'taches'), 'Aucune prime à recouvrer, donc aucune tâche de suivi.');

        // La cotation existe malgré tout : sans elle, le statut de la police et le
        // pipeline de renouvellement (INNER JOIN) ne se calculent pas.
        $this->assertArrayHasKey('champs', $cotation);

        $lien = $this->op($d, 'Avenant', 'edit')['champs'];
        $this->assertSame((string) Avenant::RENEWAL_STATUS_CANCELLED, $lien['renewalStatus'], 'La police morte sort des polices actives.');

        // L'intermédiaire suit même un acte de fin (la rétro reste due sur le couru).
        $this->assertArrayHasKey('partenaire', $this->op($d, 'Piste')['champs']);
    }

    /** Les tâches et comptes-rendus de la police de base ne suivent jamais ; une tâche NEUVE est créée. */
    public function testTachesDeBaseNonReprisesEtTacheDeSuiviAjoutee(): void
    {
        $s = $this->seed();
        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s));

        $taches = $this->collection($this->op($d, 'Cotation'), 'taches');
        $this->assertCount(1, $taches, 'Une seule tâche : celle du suivi du paiement.');

        $champs = $taches[0]['champs'];
        $this->assertStringNotContainsString('Relancer ACME', $champs['description'], 'La tâche de l’exercice écoulé n’est pas reprise.');
        $this->assertStringContainsString('paiement de la prime', $champs['description']);
        $this->assertStringContainsString('POL-MVT-1', $champs['description']);
        $this->assertSame('2027-01-15', $champs['toBeEndedAt'], 'Échéance = exigibilité de la première tranche.');
        $this->assertFalse($champs['closed']);
        $this->assertSame($s['inv']->getId(), $champs['executor']);

        // Étape distincte : c'est la seule que l'utilisateur puisse décocher.
        $this->assertSame(MouvementAvenantBuilder::ETAPE_TACHE, $taches[0]['etape']);
        $this->assertNotSame(MouvementAvenantBuilder::ETAPE_TACHE, $this->op($d, 'Piste')['etape']);
    }

    /**
     * Décocher la tâche de suivi n'emporte rien d'autre ; l'étape structurelle,
     * elle, est indécochable (c'est l'étape socle) — un « @renvoi » ne peut donc
     * jamais se retrouver orphelin.
     */
    public function testSeuleLaTacheDeSuiviEstDecochable(): void
    {
        $s = $this->seed();
        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s));

        $plan = MutationPlan::fromArray($d['operations']);
        $reduit = $plan->filtrerEtapes(['Renouvellement de la police']); // la tâche est décochée

        $this->assertCount(4, $reduit->operations, 'Les quatre opérations structurelles restent.');
        $cotation = null;
        foreach ($reduit->operations as $op) {
            if ($op->entityShortName === 'Cotation') {
                $cotation = $op;
            }
        }
        $this->assertNotNull($cotation);
        $noms = array_keys($cotation->collections); // map « nom de collection => ops enfant »
        $this->assertNotContains('taches', $noms, 'La tâche décochée disparaît du plan.');
        $this->assertContains('chargements', $noms, 'La composition de la prime, elle, reste.');
    }

    /** Un écart dicté est appliqué dans le même tour, et signalé comme tel. */
    public function testEcartDicteEstAppliqueEtSignale(): void
    {
        $s = $this->seed();
        $resultat = $this->outil->execute([
            'mouvement'   => 'renouvellement',
            'avenantId'   => $s['base']->getId(),
            'composantes' => [['nom' => 'Prime nette', 'montant' => 12000]],
        ], $this->scope($s));

        $this->assertTrue($resultat->data['pret'] ?? false);
        $this->assertNotEmpty($resultat->data['ecarts'], 'Un écart doit être restitué pour être annoncé — ce n’est plus « à l’identique ».');
        $this->assertSame(1, $resultat->data['reconduit']['chargements'], 'La composition dictée remplace celle de la police de base.');
    }

    /** Un numéro non numérique est reconduit tel quel, avec un avertissement. */
    public function testNumeroNonNumeriqueEstReconduitAvecAvertissement(): void
    {
        $s = $this->seed();
        $s['base']->setNumero('AV-A');
        $this->em->flush();

        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s));

        $this->assertSame('AV-A', $this->op($d, 'Avenant')['champs']['numero']);
        $this->assertNotEmpty(array_filter($d['avertissements'], static fn (string $a) => str_contains($a, 'AV-A')));
    }

    // ───────────────────────── 3. Garde-fous de l'outil ─────────────────────────

    /** Une police déjà mouvementée ne l'est pas deux fois : aucun plan, aucun bouton. */
    public function testPoliceDejaMouvementeeNeProduitAucunPlan(): void
    {
        $s = $this->seed();
        $derivee = (new Piste())
            ->setNom('Renouvellement — déjà fait')->setClient($s['piste']->getClient())->setRisque($s['piste']->getRisque())
            ->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $this->em->persist($derivee);
        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['dejaTraite'] ?? false);
        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNull($resultat->uiAction, 'Pas de plan, donc pas de bouton de validation.');
    }

    /**
     * L'IMPASSE SANS SORTIE, corrigée — puis remplacée par l'ADOPTION (décision métier du
     * 2026-10-13). Une police dont le renouvellement est AMORCÉ (opportunité dérivée créée,
     * aucun avenant émis) ne pouvait plus rien : l'outil refusait à vie. Il renvoyait ensuite
     * vers une écriture générique de l'avenant — sans les dates ni le statut du mouvement.
     *
     * Désormais le MOUVEMENT ADOPTE la proposition montée. Sans prime, il ne le peut pas : le
     * refus dit pourquoi, et désigne la proposition.
     */
    public function testMouvementAmorceSansPrimeDitPourquoiIlNAdoptePas(): void
    {
        $s = $this->seed();
        [, $proposition] = $this->amorcer($s, null);

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['mouvementAmorce'] ?? false, 'Amorcé, donc pas scellé.');
        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNull($resultat->uiAction, 'Aucun bouton : rien d’écrivable.');
        $this->assertSame([], $resultat->data['propositionsEnAttente'], 'Rien d’adoptable n’est offert au choix.');
        $this->assertStringContainsString('prime chiffrée', $resultat->data['bloquant']);
        $this->assertStringContainsString((string) $proposition->getNom(), $resultat->data['bloquant'], 'Le refus nomme la proposition.');
    }

    /**
     * MOUVEMENT AMORCÉ ET PROPOSITION CHIFFRÉE : Ket adopte, comme la boîte de l'écran. Le
     * plan n'écrit QUE l'avenant (sur la proposition, en précondition « premier avenant »),
     * le lien et le statut de la base, et la tâche de suivi — ni piste, ni cotation, ni
     * prime reconduite : ce qui a été négocié fait foi, durée comprise.
     */
    public function testMouvementAmorceEtChiffreKetAdopteLaProposition(): void
    {
        $s = $this->seed();
        [$derivee, $proposition] = $this->amorcer($s, 2500.0, 6);

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['pret'] ?? false, json_encode($resultat->data, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($resultat->uiAction);
        $decalque = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s), $proposition);
        $entites = array_map(static fn (array $op) => $op['op'] . ' ' . $op['entite'], $decalque['operations']);
        $this->assertNotContains('create Piste', $entites);
        $this->assertNotContains('create Cotation', $entites);
        $avenant = array_values(array_filter($decalque['operations'], static fn (array $op) => $op['op'] === 'create' && $op['entite'] === 'Avenant'))[0];
        $this->assertSame($proposition->getId(), $avenant['champs']['cotation']);
        $this->assertTrue($avenant['premierAvenant']);
        $this->assertSame('2027-01-01T00:00:00', $avenant['champs']['startingAt'], 'Au lendemain de l’échéance de la base.');
        $this->assertSame('2027-06-30T00:00:00', $avenant['champs']['endingAt'], 'Six mois : la durée de la proposition fait foi.');
        $this->assertSame($derivee->getId(), $decalque['adoptee']['pisteId']);
    }

    /**
     * Amorce un renouvellement : piste dérivée liée à la base, une proposition — chiffrée de
     * `$prime` si donné.
     *
     * @return array{0: Piste, 1: Cotation}
     */
    private function amorcer(array $s, ?float $prime, int $duree = 12): array
    {
        $derivee = (new Piste())
            ->setNom('Renouvellement — amorcé')->setClient($s['piste']->getClient())->setRisque($s['piste']->getRisque())
            ->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->setAvenantDeBase($s['base']);
        $this->em->persist($derivee);

        $proposition = (new Cotation())->setNom('Offre de renouvellement - SFA')->setDuree($duree);
        $proposition->setPiste($derivee);
        $proposition->setEntreprise($s['ent']);
        $derivee->addCotation($proposition);
        $this->em->persist($proposition);
        if ($prime !== null) {
            $chargement = (new ChargementPourPrime())->setNom('Prime nette')->setMontantFlatExceptionel($prime);
            $chargement->setEntreprise($s['ent']);
            $proposition->addChargement($chargement);
            $this->em->persist($chargement);
        }

        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();

        return [$derivee, $proposition];
    }

    /**
     * Variante sans proposition : l'étape qui reste n'est pas la même, et doit le dire —
     * c'est l'état réel de deux des polices bloquées en production.
     */
    public function testMouvementAmorceSansPropositionDemandeDEnMonterUne(): void
    {
        $s = $this->seed();

        $derivee = (new Piste())
            ->setNom('Renouvellement — sans proposition')->setClient($s['piste']->getClient())
            ->setRisque($s['piste']->getRisque())->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)
            ->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $this->em->persist($derivee);
        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['mouvementAmorce'] ?? false);
        $this->assertSame([], $resultat->data['propositionsEnAttente']);
        $this->assertStringContainsString('AUCUNE proposition', $resultat->data['prochaineEtape']);
    }

    /**
     * À l'inverse, une police dont le sort est SCELLÉ (avenant successeur émis) n'a plus
     * rien à écrire : pas de « prochaine étape », et surtout pas d'invitation à préparer
     * une écriture qui créerait un doublon.
     */
    public function testPoliceScelleeNAucuneEtapeRestante(): void
    {
        $s = $this->seed();

        $derivee = (new Piste())
            ->setNom('Renouvellement — abouti')->setClient($s['piste']->getClient())->setRisque($s['piste']->getRisque())
            ->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->setAvenantDeBase($s['base']);
        $this->em->persist($derivee);

        $cotationSuite = (new Cotation())->setNom('Offre validée')->setDuree(365);
        $cotationSuite->setPiste($derivee);
        $cotationSuite->setEntreprise($s['ent']);
        $derivee->addCotation($cotationSuite);
        $this->em->persist($cotationSuite);

        $successeur = (new Avenant())->setReferencePolice('POL-MVT-1')->setNumero('2')
            ->setDescription('Successeur')
            ->setStartingAt(new DateTimeImmutable('2027-01-01'))->setEndingAt(new DateTimeImmutable('2027-12-31'));
        $successeur->setEntreprise($s['ent'])->setInvite($s['inv']);
        $cotationSuite->addAvenant($successeur);
        $this->em->persist($successeur);

        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['dejaTraite'] ?? false);
        $this->assertArrayNotHasKey('mouvementAmorce', $resultat->data, 'Scellé, pas amorcé.');
        $this->assertArrayNotHasKey('prochaineEtape', $resultat->data, 'Il n’y a plus rien à écrire.');
        $this->assertStringContainsString('SCELLÉ', $resultat->data['note']);
        $this->assertStringNotContainsString('preparer_operations', $resultat->data['note']);
    }

    /** Référence ambiguë : la liste est proposée, aucun plan n'est préparé. */
    public function testReferenceAmbigueProposeLaListe(): void
    {
        $s = $this->seed();
        $jumeau = (new Avenant())
            ->setReferencePolice('POL-MVT-12')->setNumero('1')->setDescription('Jumeau')
            ->setStartingAt(new DateTimeImmutable('2026-01-01'))->setEndingAt(new DateTimeImmutable('2026-12-31'))
            ->setCotation($s['cotation']);
        $jumeau->setEntreprise($s['ent'])->setInvite($s['inv']);
        $this->em->persist($jumeau);
        $this->em->flush();

        $ambigu = $this->outil->execute(['mouvement' => 'renouvellement', 'police' => 'POL-MVT-'], $this->scope($s));
        $this->assertArrayHasKey('ambigu', $ambigu->data);
        $this->assertCount(2, $ambigu->data['ambigu']);
        $this->assertNull($ambigu->uiAction);

        // Une correspondance EXACTE tranche d'elle-même, malgré le préfixe commun.
        $exact = $this->outil->execute(['mouvement' => 'renouvellement', 'police' => 'POL-MVT-1'], $this->scope($s));
        $this->assertTrue($exact->data['pret'] ?? false, 'Une référence exacte n’est pas ambiguë.');
    }

    // ──────────────── Désigner la police comme le courtier la désigne ────────────────

    /**
     * « Kibali Goldmines SA a donné l'ordre de renouveler leur police. » Cette phrase,
     * dictée telle quelle le 2026-08-10, ne contient AUCUNE référence de police — et
     * l'outil, qui ne cherchait que sur `referencePolice`, répondait « aucune police »
     * à un client qui en avait sept.
     *
     * Le modèle ne pouvait pas rattraper : chercher le client puis ses polices, c'est
     * un SECOND tour d'outils, que l'architecture interdit. C'est donc au serveur de
     * faire ce chemin — et il le fait pour les deux façons de le dire.
     */
    public function testLaPoliceSeRetrouveParLeNomDuClient(): void
    {
        $s = $this->seed();

        $parArgument = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'client' => 'ACME Mouvement'],
            $this->scope($s),
        );
        $this->assertTrue($parArgument->data['pret'] ?? false, 'Le nom du client suffit à désigner la police.');
        $this->assertNotNull($parArgument->uiAction, 'Donc un vrai plan, avec son bouton.');

        // Le modèle range parfois le nom du client dans « police » : l'utilisateur, lui,
        // ne classe pas ses mots. On réessaie donc comme nom de client.
        $glisseDansPolice = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'police' => 'ACME Mouvement'],
            $this->scope($s),
        );
        $this->assertTrue($glisseDansPolice->data['pret'] ?? false, 'Un nom de client rangé dans « police » reste compris.');
    }

    /**
     * PLUSIEURS POLICES, UNE SEULE QUI COURT. Un client a rarement une police unique, et
     * chaque renouvellement en ajoute une à la chaîne. Rendre la liste entière au modèle,
     * c'est lui faire poser une question à laquelle l'utilisateur ne peut pas répondre :
     * il ne connaît pas les identifiants internes.
     *
     * « Leur police », pour un courtier, c'est celle qui est EN VIGUEUR. Le serveur la
     * retient donc — et l'ANNONCE dans « defauts », car un choix tu est un choix subi.
     */
    public function testEntrePlusieursPolicesCelleEnVigueurEstRetenueEtAnnoncee(): void
    {
        $s = $this->seed();

        // Périodes RELATIVES : ce test porte sur « aujourd'hui », pas sur l'année 2026.
        $s['base']->setStartingAt(new DateTimeImmutable('-1 month'))->setEndingAt(new DateTimeImmutable('+11 months'));

        $echue = (new Avenant())
            ->setReferencePolice('POL-MVT-ANCIENNE')->setNumero('9')->setDescription('Exercice écoulé')
            ->setStartingAt(new DateTimeImmutable('-3 years'))->setEndingAt(new DateTimeImmutable('-2 years'))
            ->setCotation($s['cotation']);
        $echue->setEntreprise($s['ent'])->setInvite($s['inv']);
        $this->em->persist($echue);
        $this->em->flush();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'client' => 'ACME Mouvement'],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['pret'] ?? false, 'Deux polices ne doivent pas bloquer : une seule court.');
        $this->assertSame($s['base']->getId(), $resultat->data['source']['avenantId'], 'La police EN VIGUEUR est retenue.');
        $this->assertStringContainsString(
            'EN VIGUEUR',
            implode(' ', $resultat->data['defauts']),
            'Le choix de la police est une hypothèse : elle se dit.',
        );
    }

    /**
     * QUAND ON NE TROUVE VRAIMENT RIEN, ON DIT OÙ L'ON A CHERCHÉ.
     *
     * Un « aucune police » nu laisse l'utilisateur deviner s'il s'est trompé de nom, de
     * rubrique ou de périmètre — et il répète sa demande à l'identique. Nommer les pistes
     * suivies transforme une impasse en question à laquelle il peut répondre d'un mot.
     */
    public function testAucunePoliceTrouveeNommeCeQuiAEteCherche(): void
    {
        $s = $this->seed();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'client' => 'Client Qui N Existe Pas'],
            $this->scope($s),
        );

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNull($resultat->uiAction);
        $this->assertStringContainsString('Client Qui N Existe Pas', $resultat->data['bloquant']);
        $this->assertContains('nom de client « Client Qui N Existe Pas »', $resultat->data['cherchePar']);
        $this->assertStringContainsString('cherchePar', $resultat->data['note']);
    }

    /**
     * Fail-closed : une police d'une autre entreprise est introuvable — et le refus PORTE
     * SA CONSIGNE. Un « INTROUVABLE » nu ne disait au modèle que ce qui manquait, jamais ce
     * qu'il devait faire : c'est dans ces vides qu'il improvisait (excuses, promesse de
     * rappeler l'outil, plan inventé).
     */
    public function testPoliceHorsEntrepriseEstRefuseeAvecConsigne(): void
    {
        $s = $this->seed();
        $autre = (new Entreprise())
            ->setNom(self::ENT . '-bis')->setLicence('L')->setAdresse('a')->setTelephone('t')
            ->setRccm('r')->setIdnat('i')->setNumimpot('n')->setUtilisateur($s['user']);
        $this->em->persist($autre);
        $inviteAutre = (new Invite())->setNom('X')->setUtilisateur($s['user'])->setEntreprise($autre)->setProprietaire(true);
        $this->em->persist($inviteAutre);
        $this->em->flush();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            new AiScope($autre, $inviteAutre),
        );
        $this->assertFalse($resultat->data['pret'] ?? true, 'Aucun plan hors du périmètre.');
        $this->assertNull($resultat->uiAction, 'Donc aucun bouton.');
        $this->assertArrayHasKey('bloquant', $resultat->data);
        $this->assertArrayHasKey('note', $resultat->data, 'Tout refus doit dire au modèle quoi faire.');
        $this->assertStringContainsString('AUCUN plan', $resultat->data['note']);
        $this->assertStringNotContainsString(
            'Présente le plan',
            implode(' ', array_map(strval(...), array_filter($resultat->data, is_scalar(...)))),
            'Aucune consigne ne doit contredire le refus.'
        );

        $this->em->getConnection()->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT . '-bis']);
        $this->em->getConnection()->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT . '-bis']);
    }

    /**
     * LE BUG DU « PLAN FANTÔME », À LA SOURCE. Quand un plan attend déjà la décision de
     * l'utilisateur, le moteur REFUSE d'en préparer un second — mais son refus est un
     * STATUS_OK porteur de « pret: false ». L'outil de mouvement ne testait que le statut :
     * le refus passait, et l'outil lui agrafait une « consigne : Présente le plan et le
     * budget » avec défauts, source et éléments reconduits à l'appui.
     *
     * Le modèle recevait donc DEUX ORDRES CONTRAIRES et la matière d'un plan, sans aucune
     * uiAction : il rédigeait un plan en prose annonçant un bouton qui n'existerait jamais.
     * Le refus doit ressortir INTACT.
     */
    public function testLeRefusDuMoteurRessortIntactSansConsigneDePresentation(): void
    {
        $s = $this->seed();
        $scope = $this->scope($s);

        // Premier mouvement : un plan réel, qui reste en attente de décision.
        $premier = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $scope,
        );
        $this->assertTrue($premier->data['pret'] ?? false, 'Le premier plan doit être prêt.');
        $this->assertNotNull($premier->uiAction, 'Et porter sa barre de décision.');

        // Le fil porte désormais ce plan non tranché — c'est cet ÉTAT qui arme le verrou,
        // et il n'atteint les outils que par la conversation portée dans le scope.
        $scopeAvecFil = new AiScope($s['ent'], $s['inv'], $this->filAvecPlanEnAttente($s, $premier));

        $second = $this->outil->execute(
            ['mouvement' => 'prorogation', 'avenantId' => $s['base']->getId(), 'dureeJours' => 30],
            $scopeAvecFil,
        );

        $this->assertFalse($second->data['pret'] ?? true, 'Aucun second plan tant que le premier attend.');
        $this->assertNull($second->uiAction, 'Et surtout aucun second bouton.');
        $this->assertTrue($second->data['planEnAttente'] ?? false, 'Le refus du verrou doit être reconnaissable.');
        $this->assertArrayNotHasKey(
            'consigne',
            $second->data,
            'Le refus ne doit PAS être habillé d’une consigne « Présente le plan et le budget ».'
        );
        foreach (['defauts', 'source', 'reconduit', 'ecarts'] as $matiereDePlan) {
            $this->assertArrayNotHasKey(
                $matiereDePlan,
                $second->data,
                sprintf('« %s » donnerait au modèle de quoi rédiger un plan en prose.', $matiereDePlan)
            );
        }
    }

    /**
     * L'ABANDON REND LA POLICE À SES MOUVEMENTS — SANS LA DÉTRUIRE.
     *
     * `Piste::avenantDeBase` est un OneToOne en cascade:['remove'] : supprimer
     * l'opportunité dérivée emporterait la POLICE qu'elle fait évoluer, ses
     * propositions, ses échéanciers et ses paiements. Le contrôleur HTTP dissociait à
     * la main ; le chemin générique des plans de l'assistant, lui, ne le faisait pas.
     * Ce test est le garde-fou : après exécution, la police doit être VIVANTE.
     */
    public function testAbandonSupprimeLOpportuniteMaisPreserveLaPolice(): void
    {
        $s = $this->seed();
        $scope = $this->scope($s);
        $baseId = $s['base']->getId();

        $derivee = (new Piste())
            ->setNom('Renouvellement — à abandonner')->setClient($s['piste']->getClient())
            ->setRisque($s['piste']->getRisque())->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)
            ->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        // Les DEUX sens du lien, comme en production.
        $derivee->setAvenantDeBase($s['base']);
        $this->em->persist($derivee);

        $proposition = (new Cotation())->setNom('Offre à abandonner')->setDuree(365);
        $proposition->setPiste($derivee);
        $proposition->setEntreprise($s['ent']);
        $derivee->addCotation($proposition);
        $this->em->persist($proposition);

        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();
        $deriveeId = $derivee->getId();

        $resultat = $this->outil->execute([
            'mouvement' => 'renouvellement',
            'avenantId' => $baseId,
            'abandonnerMouvementExistant' => true,
        ], $scope);

        $this->assertTrue($resultat->data['pret'] ?? false, 'L’abandon doit produire un vrai plan.');
        $this->assertTrue($resultat->data['abandon'] ?? false);
        $this->assertNotNull($resultat->uiAction, 'Donc un vrai bouton de validation.');

        // AVERTISSEMENT : le plan exige le mot de passe, et la consigne impose de
        // prévenir AVANT d'agir.
        $this->assertTrue($resultat->data['requiresPassword'] ?? false, 'Une suppression exige le mot de passe.');
        $this->assertStringContainsString('IRRÉVERSIBLE', $resultat->data['consigne']);
        $this->assertStringContainsString('impacts', $resultat->data['consigne']);

        $plan = MutationPlan::fromArray($resultat->uiAction['plan']);
        $refs = MutationReferences::live();
        foreach ($plan->operationsOrdonnees() as $op) {
            $this->mutation->executer($op, $scope, $s['user'], $refs);
        }
        $this->em->flush();
        $this->em->clear();

        // L'opportunité a disparu…
        $this->assertNull(
            $this->em->getRepository(Piste::class)->find($deriveeId),
            'L’opportunité dérivée doit être supprimée.'
        );

        // … et LA POLICE EST VIVANTE, rendue à ses quatre mouvements.
        $police = $this->em->getRepository(Avenant::class)->find($baseId);
        $this->assertNotNull($police, 'La police de base ne doit JAMAIS être emportée par la cascade.');
        $this->assertNull($police->getPisteDeRenouvellement(), 'Elle est de nouveau libre de tout mouvement.');
        $this->assertNotNull($police->getCotation(), 'Sa proposition d’origine est intacte.');
    }

    /**
     * Un mouvement ABOUTI s'abandonne aussi, par la même règle que l'écran : son avenant
     * successeur n'a vécu aucun mouvement financier, il part avec l'opportunité.
     */
    public function testAbandonDUnMouvementAboutiSansFinanceEstPermis(): void
    {
        $s = $this->seed();

        $derivee = (new Piste())
            ->setNom('Renouvellement — abouti')->setClient($s['piste']->getClient())
            ->setRisque($s['piste']->getRisque())->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)
            ->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->setAvenantDeBase($s['base']);
        $this->em->persist($derivee);

        $cotationSuite = (new Cotation())->setNom('Offre validée')->setDuree(365);
        $cotationSuite->setPiste($derivee);
        $cotationSuite->setEntreprise($s['ent']);
        $derivee->addCotation($cotationSuite);
        $this->em->persist($cotationSuite);

        $successeur = (new Avenant())->setReferencePolice('POL-MVT-1')->setNumero('2')
            ->setDescription('Successeur')
            ->setStartingAt(new DateTimeImmutable('2027-01-01'))->setEndingAt(new DateTimeImmutable('2027-12-31'));
        $successeur->setEntreprise($s['ent'])->setInvite($s['inv']);
        $cotationSuite->addAvenant($successeur);
        $this->em->persist($successeur);

        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();

        $resultat = $this->outil->execute([
            'mouvement' => 'renouvellement',
            'avenantId' => $s['base']->getId(),
            'abandonnerMouvementExistant' => true,
        ], $this->scope($s));

        $this->assertTrue($resultat->data['pret'] ?? false, json_encode($resultat->data, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($resultat->data['abandon'] ?? false);
        $this->assertNotNull($resultat->uiAction, 'Un vrai plan, donc un vrai bouton.');
    }

    /**
     * Un fil portant un plan NON TRANCHÉ, tel que AssistantIaController l'enregistre après
     * avoir présenté un plan : c'est cet état que PlanEnAttente lit pour armer le verrou.
     */
    private function filAvecPlanEnAttente(array $s, AiToolResult $resultat): AssistantConversation
    {
        $conversation = (new AssistantConversation())->setTitre('Verrou mouvement');
        $conversation->setEntreprise($s['ent'])->setInvite($s['inv']);
        $conversation->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Plan présenté.')
            ->setMeta(['mutationPlan' => ['plan' => $resultat->uiAction['plan'] ?? []]]));
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    // ───────────────────────── 4. L'exécution referme la boucle ─────────────────────────

    /**
     * Bout en bout : le plan s'exécute, la police bascule de statut et SORT de la
     * vigie des échéances. Sans ce dernier point, la boussole continuerait à
     * réclamer un renouvellement déjà fait.
     */
    public function testExecutionDuRenouvellementSortLaPoliceDeLaVigie(): void
    {
        $s = $this->seed();
        $scope = $this->scope($s);
        $baseId = $s['base']->getId();

        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        $avant = array_map(static fn (Avenant $a) => $a->getId(), $dashboard->getAllRenouvellements($s['ent'], 400));
        $this->assertContains($baseId, $avant, 'La police est bien candidate au renouvellement avant le mouvement.');

        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $scope);
        $plan = MutationPlan::fromArray($d['operations']);
        $refs = MutationReferences::live();
        foreach ($plan->operationsOrdonnees() as $op) {
            $this->mutation->executer($op, $scope, $s['user'], $refs);
        }
        $this->em->flush();
        $this->em->clear();

        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $derivee = $base->getPisteDeRenouvellement();
        $this->assertNotNull($derivee, 'La police de base pointe désormais son opportunité dérivée.');
        $this->assertSame($baseId, $derivee->getAvenantDeBase()?->getId(), 'Le lien retour (OneToOne) est posé : c’est lui qui pilote le statut affiché.');
        $this->assertSame(Piste::AVENANT_RENOUVELLEMENT, $derivee->getTypeAvenant());
        $this->assertNotNull($derivee->getPartenaire(), 'L’intermédiaire est reconduit en base.');
        // TOUTES les conditions de partage sont réellement écrites — c'est l'engagement
        // de rétrocommission qui est en jeu, aucune ne doit se perdre en chemin.
        $this->assertCount(2, $derivee->getConditionsPartageExceptionnelles(), 'Les deux conditions de partage sont écrites en base.');
        $taux = array_map(
            static fn (ConditionPartage $c) => $c->getTaux(),
            $derivee->getConditionsPartageExceptionnelles()->toArray(),
        );
        $this->assertEqualsCanonicalizing([35.0, 10.0], $taux, 'Les taux promis sont conservés à l’identique.');

        $cotation = $derivee->getCotations()->first();
        $this->assertNotFalse($cotation, 'La proposition reconduite existe.');
        $this->assertCount(3, $cotation->getChargements(), 'La composition de la prime est reconduite.');
        $this->assertCount(2, $cotation->getTranches(), 'L’échéancier est reconduit.');
        $this->assertCount(1, $cotation->getTaches(), 'La tâche de suivi du paiement est créée.');
        $this->assertCount(1, $cotation->getAvenants(), 'Le nouveau contrat est rattaché.');

        $nouveau = $cotation->getAvenants()->first();
        $this->assertSame('2027-01-01', $nouveau->getStartingAt()->format('Y-m-d'));
        $this->assertSame('2027-12-31', $nouveau->getEndingAt()->format('Y-m-d'));

        $apres = array_map(static fn (Avenant $a) => $a->getId(), $dashboard->getAllRenouvellements($s['ent'], 400));
        $this->assertNotContains($baseId, $apres, 'La police renouvelée ne doit plus être réclamée par la vigie.');
    }

    /**
     * LE REVERS EXACT DU TEST PRÉCÉDENT, et l'état réel des polices de l'incident : le
     * mouvement a été AMORCÉ — l'opportunité dérivée existe, les deux sens du lien sont
     * posés — mais AUCUN avenant successeur n'en est issu. Le sort n'est donc PAS scellé :
     * la police reste ÉCHUE et la vigie doit continuer à la réclamer, puisque l'action
     * due (faire valider la proposition de renouvellement) ne l'est pas encore.
     *
     * C'est ce que l'assistant a confondu avec « renouvelée », en annonçant qu'il ne
     * restait aucune police échue.
     */
    public function testRenouvellementAmorceSansSuccesseurLaisseLaPoliceDansLaVigie(): void
    {
        $s = $this->seed();
        $baseId = $s['base']->getId();

        // La police est ÉCHUE : c'est la situation de l'incident.
        $s['base']->setEndingAt(new DateTimeImmutable('-30 days'));

        // Opportunité dérivée SANS cotation ni avenant : un renouvellement amorcé.
        $derivee = (new Piste())
            ->setNom('Renouvellement amorcé POL-MVT-1')
            ->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)
            ->setDescriptionDuRisque('Risque reconduit')
            ->setExercice(2027)
            ->setClient($s['piste']->getClient());
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->setAvenantDeBase($s['base']);
        $this->em->persist($derivee);
        $s['base']->setPisteDeRenouvellement($derivee);

        // La vigie de l'assistant répond DANS le portefeuille de l'invité : sans
        // portefeuille, elle rend une liste vide — exactement comme la rubrique à l'écran.
        $portefeuille = (new \App\Entity\Portefeuille())->setNom('Portefeuille Mouvement');
        $portefeuille->setGestionnaire($s['inv']);
        $portefeuille->setEntreprise($s['ent']);
        $portefeuille->addClient($s['piste']->getClient());
        $this->em->persist($portefeuille);

        $this->em->flush();
        $this->em->clear();

        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        $vigie = $dashboard->getAllRenouvellements($s['ent'], 30);
        $ids = array_map(static fn (Avenant $a) => $a->getId(), $vigie);

        $this->assertContains(
            $baseId,
            $ids,
            'Un renouvellement AMORCÉ ne scelle rien : la police échue reste réclamée par la vigie.'
        );

        // Et la vigie la présente bien comme ÉCHUE, avec son retard nommé.
        $volet = static::getContainer()->get(\App\Ai\Tool\VigieEcheancesTool::class)
            ->execute(['volet' => 'renouvellements', 'horizonJours' => 30], $this->scope($s))
            ->data['volets']['renouvellements'];

        $this->assertSame([$baseId], array_column($volet['echues']['lignes'], 'id'));
        $this->assertSame(30, $volet['echues']['lignes'][0]['joursRetard']);
    }

    // ───────────────────────── 5. Parité avec l'interface (workspace) ─────────────────────────

    /**
     * Les quatre mouvements sont OFFERTS par la rubrique Avenants. La barre d'outils
     * comme le menu contextuel (clic droit) lisent les mêmes `attribute_actions` du
     * canevas de formulaire : les y déclarer suffit à équiper les deux surfaces.
     */
    public function testLesQuatreMouvementsSontOffertsParLaRubriqueAvenants(): void
    {
        $s = $this->seed();
        $canvas = static::getContainer()->get(\App\Services\CanvasBuilder::class)
            ->getEntityFormCanvas($s['base'], $s['ent']->getId());

        $actions = [];
        foreach ($canvas['parametres']['attribute_actions'] ?? [] as $action) {
            $actions[$action['label']] = $action;
        }

        foreach (['Renouveler à l\'identique', 'Proroger la police', 'Annuler la police', 'Résilier la police'] as $label) {
            $this->assertArrayHasKey($label, $actions, sprintf('L’action « %s » doit être proposée sur un avenant.', $label));
            $this->assertSame('ui:avenant.mouvement-request', $actions[$label]['event']);
            // Une police déjà mouvementée ne l'est pas deux fois : miroir exact du
            // garde « dejaTraite » de l'outil de l'assistant.
            $this->assertSame(['field' => 'hasPisteDerivee', 'value' => false], $actions[$label]['condition']);
        }
    }

    /** Le picker s'ouvre et son aperçu vient du serveur — pas d'un calcul refait en JS. */
    public function testPickerEtApercuViennentDuMemeMoteurQueLAssistant(): void
    {
        $s = $this->seed();
        $id = $s['base']->getId();

        $this->client->request('GET', '/admin/avenant/api/mouvement-picker/renouvellement/' . $id);
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-controller="mouvement-picker"', $html);
        $this->assertStringContainsString("Seule la prise d'effet est modifiable", $html, 'Un renouvellement ne demande rien d’autre.');
        $this->assertStringContainsString('value="2027-01-01"', $html, 'La prise d’effet est pré-remplie au lendemain de l’échéance.');
        $this->assertStringContainsString('01/01/2027', $html, 'La période dérivée est affichée avant validation.');

        // Prorogation : la durée saisie pilote l'aperçu, prorata compris.
        $this->client->request('GET', '/admin/avenant/api/mouvement-apercu/prorogation/' . $id . '?dureeJours=20');
        $this->assertResponseIsSuccessful();
        $apercu = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($apercu['pret']);
        $this->assertStringContainsString('20/01/2027', $apercu['html']);
        $this->assertStringContainsString('prorata', $apercu['html']);

        // Sans date, une résiliation n'est pas prête : le bouton reste inactif.
        $this->client->request('GET', '/admin/avenant/api/mouvement-apercu/resiliation/' . $id);
        $sansDate = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($sansDate['pret']);
    }

    /**
     * La boîte s'ouvre DÉJÀ renseignée : le client est nommé, et l'aperçu est calculé
     * avec la valeur proposée dans le champ. Régression corrigée : une prorogation
     * s'ouvrait sur « Client non renseigné » (l'identité de la police n'accompagnait
     * pas la réponse tant qu'aucune durée n'était fournie) puis se corrigeait seule.
     */
    public function testLaBoiteSOuvreRenseigneeSansIntituleNegatif(): void
    {
        $s = $this->seed();

        foreach (['renouvellement', 'prorogation', 'annulation', 'resiliation'] as $mouvement) {
            $this->client->request('GET', sprintf('/admin/avenant/api/mouvement-picker/%s/%d', $mouvement, $s['base']->getId()));
            $this->assertResponseIsSuccessful($mouvement);
            $html = $this->client->getResponse()->getContent();

            $this->assertStringContainsString('ACME Mouvement', $html, sprintf('%s : le client doit être nommé dès l’ouverture.', $mouvement));
            $this->assertStringNotContainsString('non renseigné', $html, sprintf('%s : aucun intitulé négatif.', $mouvement));
            $this->assertStringNotContainsString('data-mouvement-picker-target="executer" disabled', $html, sprintf('%s : le bouton est actif d’emblée.', $mouvement));

            // Chaque mouvement expose son champ unique au pattern maison, à icône
            // incrustée — pour le renouvellement, une prise d'effet facultative et
            // pré-remplie (le bouton est actif d'emblée, vérifié ci-dessus).
            $this->assertStringContainsString('jsb-picker-field', $html, sprintf('%s : les champs suivent le pattern maison.', $mouvement));
            $this->assertStringContainsString('jsb-picker-field-icon', $html, sprintf('%s : icône incrustée dans le champ.', $mouvement));
        }
    }

    /**
     * L'écran écrit EXACTEMENT ce que l'assistant écrirait : même piste dérivée, même
     * cotation reconduite, même tâche de suivi, même double lien.
     */
    public function testExecutionDepuisLInterfaceProduitLeMemeResultatQueKet(): void
    {
        $s = $this->seed();
        $baseId = $s['base']->getId();

        $this->client->request(
            'POST',
            '/admin/avenant/api/mouvement/renouvellement/' . $baseId,
            [], [], ['CONTENT_TYPE' => 'application/json'], '{}',
        );
        $this->assertResponseIsSuccessful();
        $this->assertTrue(json_decode($this->client->getResponse()->getContent(), true)['success']);

        $this->em->clear();
        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $derivee = $base->getPisteDeRenouvellement();
        $this->assertNotNull($derivee);
        $this->assertSame($baseId, $derivee->getAvenantDeBase()?->getId());
        $this->assertSame(Piste::AVENANT_RENOUVELLEMENT, $derivee->getTypeAvenant());

        $cotation = $derivee->getCotations()->first();
        $this->assertCount(3, $cotation->getChargements());
        $this->assertCount(2, $cotation->getTranches());
        $this->assertCount(1, $cotation->getTaches(), 'La tâche de suivi du paiement est créée, comme via Ket.');
        $this->assertCount(1, $cotation->getAvenants());
        $this->assertSame('2027-01-01', $cotation->getAvenants()->first()->getStartingAt()->format('Y-m-d'));

        // Rejouer le même mouvement est refusé (409) : pas de doublon depuis l'écran non plus.
        $this->client->request('POST', '/admin/avenant/api/mouvement/renouvellement/' . $baseId, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertResponseStatusCodeSame(409);
    }

    /** Une date manquante est refusée proprement (422), jamais devinée. */
    public function testResiliationSansDateEstRefuseeProprement(): void
    {
        $s = $this->seed();
        $this->client->request(
            'POST',
            '/admin/avenant/api/mouvement/resiliation/' . $s['base']->getId(),
            [], [], ['CONTENT_TYPE' => 'application/json'], '{}',
        );
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('date', mb_strtolower(json_decode($this->client->getResponse()->getContent(), true)['message']));
    }

    /** Une résiliation exécutée sort la police des « polices actives » du tableau de bord. */
    public function testExecutionDUneResiliationEteintLaPolice(): void
    {
        $s = $this->seed();
        $scope = $this->scope($s);
        $baseId = $s['base']->getId();

        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        $this->assertSame(1, $dashboard->getPoliciesActives($s['ent']), 'La police est active avant la résiliation.');

        $d = $this->builder->construire(MouvementAvenant::Resiliation, $s['base'], ['dateEffet' => '2026-06-15'], $scope);
        $refs = MutationReferences::live();
        foreach (MutationPlan::fromArray($d['operations'])->operationsOrdonnees() as $op) {
            $this->mutation->executer($op, $scope, $s['user'], $refs);
        }
        $this->em->flush();
        $this->em->clear();

        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $this->assertSame(Avenant::RENEWAL_STATUS_CANCELLED, $base->getRenewalStatus(), 'Le statut stocké est mis à jour…');
        $this->assertSame(
            Piste::AVENANT_RESILIATION,
            $base->getPisteDeRenouvellement()?->getTypeAvenant(),
            '…et l’opportunité dérivée porte bien une résiliation.',
        );

        // Ni la police résiliée ni l'acte de résiliation ne sont des polices actives :
        // l'acte ne couvre rien. Il naissait « En cours » et gonflait ce compte.
        $this->assertSame(0, $dashboard->getPoliciesActives($s['ent']), 'Une police résiliée ne laisse aucune police active.');
        $acte = $base->getPisteDeRenouvellement()->getCotations()->first()->getAvenants()->first();
        $this->assertSame(Avenant::RENEWAL_STATUS_CANCELLED, $acte->getRenewalStatus(), 'L’acte de résiliation naît « Annulé / résilié ».');
    }

    // ───────────────────────── 6. Statut de la base, abandon, prise d'effet ─────────────────────────

    /** Rend la police de base ÉCHUE depuis 18 mois (01/01/2025 → 31/03/2025) : la situation de #168. */
    private function vieillir(array $s): void
    {
        $s['base']->setStartingAt(new DateTimeImmutable('2025-01-01'))->setEndingAt(new DateTimeImmutable('2025-03-31'));
        $this->em->flush();
    }

    /** Exécute un mouvement par le moteur unique, comme l'écran et Ket. */
    private function executer(array $s, MouvementAvenant $mouvement, array $args = []): void
    {
        // Après un em->clear(), les entités du jeu sont détachées : on les relit.
        if (!$this->em->contains($s['ent'])) {
            foreach (['ent' => Entreprise::class, 'inv' => Invite::class, 'user' => Utilisateur::class, 'base' => Avenant::class] as $cle => $classe) {
                $s[$cle] = $this->em->getRepository($classe)->find($s[$cle]->getId());
            }
        }
        $scope = $this->scope($s);
        $d = $this->builder->construire($mouvement, $s['base'], $args, $scope);
        $this->assertArrayHasKey('operations', $d, json_encode($d, JSON_UNESCAPED_UNICODE));
        $refs = MutationReferences::live();
        foreach (MutationPlan::fromArray($d['operations'])->operationsOrdonnees() as $op) {
            $this->mutation->executer($op, $scope, $s['user'], $refs);
        }
        $this->em->flush();
        $this->em->clear();
    }

    /** Le tableau de bord mémoïse ses avenants par requête : on lit un état frais. */
    private function tableauDeBord(): DashboardDataProvider
    {
        $dashboard = static::getContainer()->get(DashboardDataProvider::class);
        (new \ReflectionProperty($dashboard, 'cacheAvenants'))->setValue($dashboard, []);

        return $dashboard;
    }

    /** Chaque mouvement écrit sur la police de base le statut de SON sort. */
    public function testChaqueMouvementEcritSonStatutSurLaBase(): void
    {
        $s = $this->seed();
        $attendus = [
            [MouvementAvenant::Renouvellement, [], Avenant::RENEWAL_STATUS_RENEWED],
            [MouvementAvenant::Prorogation, ['dureeJours' => 20], Avenant::RENEWAL_STATUS_EXTENDED],
            [MouvementAvenant::Annulation, ['dateEffet' => '2026-06-15'], Avenant::RENEWAL_STATUS_CANCELLED],
            [MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15'], Avenant::RENEWAL_STATUS_CANCELLED],
        ];

        foreach ($attendus as [$mouvement, $args, $statut]) {
            $d = $this->builder->construire($mouvement, $s['base'], $args, $this->scope($s));
            $this->assertSame((string) $statut, $this->op($d, 'Avenant', 'edit')['champs']['renewalStatus'], $mouvement->value);
            $this->assertSame($statut, $mouvement->statutDeLaBase());
        }
    }

    /**
     * POINT 1 — DEUX QUESTIONS, DEUX ENSEMBLES, aucun double comptage.
     *  - PORTEFEUILLE EN VIGUEUR : la police qui couvre aujourd'hui. La base (2026) l'est
     *    jusqu'au 31/12/2026 ; à partir de 2027, c'est son successeur — jamais les deux.
     *  - PRODUCTION D'UN EXERCICE : par date d'effet, quel que soit le statut. La base reste
     *    dans 2026 (sa prime a été facturée), le successeur compte dans 2027 : chaque prime
     *    une fois, dans son exercice. Le statut « Renouvelé » écrit sur la base n'efface rien.
     */
    public function testUnRenouvellementNeDoubleNiLesPolicesNiLaProduction(): void
    {
        $s = $this->seed();
        $primes2026 = $this->tableauDeBord()->getPrimesTotales($s['ent'], 2026);
        $commissions2026 = $this->tableauDeBord()->getCommissionsTotales($s['ent'], 2026);
        $this->assertGreaterThan(0.0, $primes2026);
        $this->assertSame(1, $this->tableauDeBord()->getPoliciesActives($s['ent']));

        $this->executer($s, MouvementAvenant::Renouvellement);

        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $base->getRenewalStatus());
        $this->assertSame(
            Avenant::RENEWAL_STATUS_RUNNING,
            $base->getPisteDeRenouvellement()->getStatutBaseAvantMouvement(),
            'L’opportunité dérivée mémorise le statut d’avant le mouvement.',
        );

        $dashboard = $this->tableauDeBord();
        $this->assertSame(1, $dashboard->getPoliciesActives($s['ent']), 'Le 09/10/2026, seule la base couvre.');
        $this->assertEqualsWithDelta($primes2026, $dashboard->getPrimesTotales($s['ent'], 2026), 0.01, 'La production 2026 est intacte : ni effacée par le statut, ni doublée.');
        $this->assertEqualsWithDelta($commissions2026, $dashboard->getCommissionsTotales($s['ent'], 2026), 0.01);
        $this->assertEqualsWithDelta($primes2026, $dashboard->getPrimesTotales($s['ent'], 2027), 0.01, 'Le successeur produit en 2027, à l’identique.');

        self::mockTime('2027-03-01 10:00:00');
        $this->assertSame(1, $this->tableauDeBord()->getPoliciesActives($s['ent']), 'En 2027, seul le successeur couvre : la base renouvelée n’est plus comptée.');
    }

    /**
     * Le détail des revenus suit l'exercice (même assiette que les cartes) — SAUF le solde :
     * il porte sur TOUS les avenants non soldés, et nomme la part née avant l'exercice. Un
     * impayé de 2026 reste visible quand on regarde 2027.
     */
    public function testLeDetailDesRevenusSuitLExerciceMaisPasLeSolde(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Renouvellement);
        $dashboard = $this->tableauDeBord();

        $d2026 = $dashboard->getRevenusPercusBreakdown($s['ent'], 2026);
        $d2027 = $dashboard->getRevenusPercusBreakdown($s['ent'], 2027);
        foreach ([2026 => $d2026, 2027 => $d2027] as $annee => $detail) {
            $this->assertEqualsWithDelta($dashboard->getCommissionsTotales($s['ent'], $annee), $detail['ttc'], 0.01, (string) $annee);
        }
        $this->assertGreaterThan(0.0, $d2026['ttc']);

        // Rien n'est encaissé : le solde est la commission des DEUX polices, quel que soit
        // l'exercice regardé ; seule la part « antérieure » change.
        $total = $d2026['ttc'] + $d2027['ttc'];
        $this->assertEqualsWithDelta($total, $d2026['solde'], 0.01);
        $this->assertEqualsWithDelta($total, $d2027['solde'], 0.01, 'L’impayé 2026 reste visible en regardant 2027.');
        $this->assertEqualsWithDelta(0.0, $d2026['soldeAnterieur'], 0.01);
        $this->assertEqualsWithDelta($d2026['ttc'], $d2027['soldeAnterieur'], 0.01, 'Dont exercices antérieurs : la police 2026.');
        $this->assertSame(0.0, $dashboard->getRevenusPercusBreakdown($s['ent'], 2025)['ttc'], 'Aucune production en 2025.');
    }

    /**
     * Le ratio S/P d'un assureur rapporte les sinistres des polices EN VIGUEUR (même
     * référence, survenus pendant leur période) à leurs primes : un sinistre d'une autre
     * police, ou hors période, n'y entre plus.
     */
    public function testLeRatioSPNeRetientQueLesSinistresDesPolicesEnVigueur(): void
    {
        $s = $this->seed();
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
        $assureur = $this->em->getRepository(Avenant::class)->find($s['base']->getId())->getCotation()->getAssureur();
        $client = $s['piste']->getClient();

        foreach ([['POL-MVT-1', '2026-05-01', 1000.0], ['POL-MVT-1', '2025-05-01', 7000.0], ['AUTRE-POLICE', '2026-05-01', 9000.0]] as [$ref, $quand, $montant]) {
            $sinistre = (new \App\Entity\NotificationSinistre())->setReferencePolice($ref)->setOccuredAt(new DateTimeImmutable($quand));
            $sinistre->setAssureur($assureur);
            $sinistre->setAssure($this->em->getRepository(Client::class)->find($client->getId()));
            $sinistre->setEntreprise($ent);
            $this->em->persist($sinistre);
            $offre = (new \App\Entity\OffreIndemnisationSinistre())->setMontantPayable($montant)->setBeneficiaire('ACME');
            $offre->setNotificationSinistre($sinistre);
            $offre->setEntreprise($ent);
            $this->em->persist($offre);
        }
        $this->em->flush();

        $ligne = $this->tableauDeBord()->getTopAssureursAvecIndicateurs($ent)[0];
        $this->assertEqualsWithDelta(1000.0, $ligne['sinistresIndemnises'], 0.01, 'Seul le sinistre de la police en vigueur, dans sa période, compte.');
    }

    /** Une police résiliée sort du portefeuille en vigueur mais GARDE la production qu'elle a faite. */
    public function testUneResiliationGardeLaProductionDeLaPolice(): void
    {
        $s = $this->seed();
        $primes2026 = $this->tableauDeBord()->getPrimesTotales($s['ent'], 2026);

        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);

        $dashboard = $this->tableauDeBord();
        $this->assertSame(0, $dashboard->getPoliciesActives($s['ent']));
        $this->assertEqualsWithDelta($primes2026, $dashboard->getPrimesTotales($s['ent'], 2026), 0.01, 'L’annulation n’efface pas les primes déjà produites.');
    }

    /**
     * POINTS 2 ET 3 — abandonner le mouvement depuis l'écran (« Supprimer la piste
     * dérivée ») rend à la base EXACTEMENT son statut d'avant — ici « Prorogé », pas un
     * « En cours » supposé — et emporte l'avenant successeur : aucun double comptage ne
     * peut revenir.
     */
    public function testAbandonDepuisLEcranRestitueLeStatutExactEtEmporteLeSuccesseur(): void
    {
        $s = $this->seed();
        $baseId = $s['base']->getId();
        $s['base']->setRenewalStatus(Avenant::RENEWAL_STATUS_EXTENDED);
        $this->em->flush();
        $this->executer($s, MouvementAvenant::Renouvellement);
        $this->assertGreaterThan(0.0, $this->tableauDeBord()->getPrimesTotales($s['ent'], 2027), 'Le successeur produit en 2027.');
        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $base->getRenewalStatus());
        $deriveeId = $base->getPisteDeRenouvellement()->getId();
        $successeurId = $base->getPisteDeRenouvellement()->getCotations()->first()->getAvenants()->first()->getId();
        $this->em->clear();

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $baseId);
        $this->assertResponseIsSuccessful();
        $this->em->clear();

        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $this->assertNotNull($base, 'La police de base survit.');
        $this->assertSame(Avenant::RENEWAL_STATUS_EXTENDED, $base->getRenewalStatus(), 'Elle retrouve EXACTEMENT son statut d’avant.');
        $this->assertNull($this->em->getRepository(Piste::class)->find($deriveeId), 'L’opportunité dérivée est supprimée.');
        $this->assertNull($this->em->getRepository(Avenant::class)->find($successeurId), 'Le successeur part avec elle (cascade).');
        $this->assertEqualsWithDelta(0.0, $this->tableauDeBord()->getPrimesTotales($s['ent'], 2027), 0.01, 'Plus aucune trace du successeur dans la production 2027.');
    }

    /** Un statut changé à la main après le mouvement est une décision : l'abandon n'y touche pas. */
    public function testAbandonNEcrasePasUnStatutPoseALaMain(): void
    {
        $s = $this->seed();
        $baseId = $s['base']->getId();
        $this->executer($s, MouvementAvenant::Renouvellement);

        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $base->setRenewalStatus(Avenant::RENEWAL_STATUS_LOST);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $baseId);
        $this->assertResponseIsSuccessful();
        $this->em->clear();

        $this->assertSame(Avenant::RENEWAL_STATUS_LOST, $this->em->getRepository(Avenant::class)->find($baseId)->getRenewalStatus());
    }

    /** POINT 4 — une prise d'effet AVANT le lendemain de l'échéance chevaucherait la base : refusée. */
    public function testUnePriseDEffetQuiChevaucheLaBaseEstRefusee(): void
    {
        $s = $this->seed();

        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], ['dateEffet' => '2026-12-15'], $this->scope($s));
        $this->assertArrayNotHasKey('operations', $d);
        $this->assertStringContainsString('chevaucheraient du 15/12/2026 au 31/12/2026', $d['bloquant']);

        $this->client->request(
            'POST',
            '/admin/avenant/api/mouvement/renouvellement/' . $s['base']->getId(),
            [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['dateEffet' => '2026-12-15']),
        );
        $this->assertResponseStatusCodeSame(422);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Avenant::class)->find($s['base']->getId())->getPisteDeRenouvellement(), 'Rien n’est écrit.');
    }

    /** POINT 4 — une prise d'effet APRÈS le lendemain est acceptée, mais nommée : interruption de couverture. */
    public function testUnePriseDEffetTardiveAnnonceUneInterruptionDeCouverture(): void
    {
        $s = $this->seed();

        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], ['dateEffet' => '2027-02-01'], $this->scope($s));
        $avenant = $this->op($d, 'Avenant')['champs'];
        $this->assertStringStartsWith('2027-02-01', $avenant['startingAt']);
        $this->assertStringStartsWith('2028-01-31', $avenant['endingAt'], 'Même durée que la base (365 jours).');
        $this->assertCount(1, $d['ecarts']);
        $this->assertStringContainsString('Interruption de couverture de 31 jours', $d['ecarts'][0]);
        $this->assertStringContainsString('du 01/01/2027 au 31/01/2027', $d['ecarts'][0]);
    }

    /** La prise d'effet pré-remplie (le lendemain de l'échéance) est la continuité : aucun écart. */
    public function testLaPriseDEffetAuLendemainNEstPasUnEcart(): void
    {
        $s = $this->seed();

        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], ['dateEffet' => '2027-01-01'], $this->scope($s));
        $this->assertSame([], $d['ecarts']);
        $this->assertSame('2027-01-01', $this->builder->dateEffetParDefaut($s['base'])->format('Y-m-d'));
    }

    /** POINT 5 — renouvellement ET prorogation annoncent une période déjà échue avant validation. */
    public function testUnePeriodeDejaEchueEstAnnonceeAvantValidation(): void
    {
        $s = $this->seed();
        $this->vieillir($s);
        $scope = $this->scope($s);

        $renouvellement = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], ['dateEffet' => '2025-04-01'], $scope);
        $this->assertStringContainsString('(01/04/2025 → 29/06/2025) est déjà échue', implode(' ', $renouvellement['avertissements']));

        $prorogation = $this->builder->construire(MouvementAvenant::Prorogation, $s['base'], ['dureeJours' => 30], $scope);
        $this->assertStringContainsString('(01/04/2025 → 30/04/2025) est déjà échue', implode(' ', $prorogation['avertissements']));

        // Une reprise à une date récente n'est plus échue : plus d'avertissement, mais
        // l'interruption de couverture est nommée.
        $reprise = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], ['dateEffet' => '2026-10-09'], $scope);
        $this->assertStringNotContainsString('déjà échue', implode(' ', $reprise['avertissements']));
        $this->assertStringContainsString('Interruption de couverture', implode(' ', $reprise['ecarts']));

        // Le picker de l'écran pré-remplit le lendemain de l'échéance et l'avertissement est VISIBLE.
        $this->client->request('GET', '/admin/avenant/api/mouvement-picker/renouvellement/' . $s['base']->getId());
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('value="2025-04-01"', $html);
        $this->assertStringContainsString('est déjà échue', $html);
    }

    /**
     * POINT 6 — Ket ne crée pas un avenant né expiré : quand la période par défaut serait
     * déjà échue, la date d'effet devient la seule question, avertissement à l'appui.
     */
    public function testKetDemandeLaDatePlutotQueDeCreerUnAvenantNeExpire(): void
    {
        $s = $this->seed();
        $this->vieillir($s);

        $this->assertFalse(MouvementAvenant::Renouvellement->exigeDate());
        $this->assertTrue(MouvementAvenant::Renouvellement->exigeDate(periodeParDefautEchue: true));

        $resultat = $this->outil->execute(['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()], $this->scope($s));
        $this->assertFalse($resultat->data['pret'] ?? true, 'Aucun plan : la date manque.');
        $this->assertNull($resultat->uiAction, 'Aucun bouton de validation.');
        $this->assertSame('dateEffet', $resultat->data['aDemander'][0]['champ']);
        $this->assertSame('2025-04-01', $resultat->data['aDemander'][0]['dateSuggeree'], 'Le lendemain reste proposé, pour une régularisation.');
        $this->assertStringContainsString('déjà échue', $resultat->data['aDemander'][0]['question']);
        $this->assertStringContainsString('déjà échue', implode(' ', $resultat->data['avertissements']), 'L’avertissement est relayé.');
        $this->assertStringContainsString('avertissements', $resultat->data['note']);

        // Avec une date d'effet, le plan se prépare.
        $avecDate = $this->outil->execute(['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId(), 'dateDebut' => '2026-10-09'], $this->scope($s));
        $this->assertTrue($avecDate->data['pret'] ?? false, json_encode($avecDate->data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * L'ACTE d'une résiliation ne couvre rien : il n'entre jamais dans la vigie des
     * échéances, son badge est neutre, et aucun mouvement ne peut lui être appliqué.
     */
    public function testLActeDeResiliationNAttendAucuneSuite(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);

        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $acte = $base->getPisteDeRenouvellement()->getCotations()->first()->getAvenants()->first();

        $suite = static::getContainer()->get(\App\Services\AvenantRenouvellementResolver::class)->resoudre($acte);
        $this->assertSame(Avenant::RENEWAL_STATUS_CANCELLED, $suite['code']);
        $this->assertTrue($suite['acte']);
        $this->assertSame('Acte de résiliation', $suite['statut']);

        $echeances = array_map(static fn (Avenant $a) => $a->getId(), $this->tableauDeBord()->getAllRenouvellements($s['ent'], 400));
        $this->assertNotContains($acte->getId(), $echeances, 'Un acte échu n’est pas une police à renouveler.');

        $this->client->request('GET', '/admin/avenant/api/mouvement-picker/renouvellement/' . $acte->getId());
        $this->assertResponseStatusCodeSame(409);

        $ket = $this->outil->execute(['mouvement' => 'renouvellement', 'avenantId' => $acte->getId()], $this->scope($s));
        $this->assertFalse($ket->data['pret'] ?? true);
        $this->assertStringContainsString('acte d’une résiliation', $ket->data['bloquant']);
    }

    // ───────────────────────── 7. Abandon : la règle financière unique ─────────────────────────

    /**
     * Exécute un renouvellement, puis pose sur l'avenant successeur le mouvement financier
     * demandé. Rend [id base, id opportunité dérivée, id successeur].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function renouvelerPuisFinancer(array $s, string $mouvement): array
    {
        $this->executer($s, MouvementAvenant::Renouvellement);
        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $derivee = $base->getPisteDeRenouvellement();
        $cotation = $derivee->getCotations()->first();
        $successeur = $cotation->getAvenants()->first();
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());

        if (str_starts_with($mouvement, 'sinistre:')) {
            // Un sinistre se rattache à sa police par la référence et la date de survenance.
            $sinistre = (new \App\Entity\NotificationSinistre())
                ->setReferencePolice($successeur->getReferencePolice())
                ->setOccuredAt(new DateTimeImmutable(substr($mouvement, 9)));
            $sinistre->setAssureur($cotation->getAssureur());
            $sinistre->setAssure($derivee->getClient());
            $sinistre->setEntreprise($ent);
            $this->em->persist($sinistre);
        } elseif ($mouvement === 'encaissementPrime') {
            $paiement = (new \App\Entity\PaiementPrime())
                ->setReference('PP-MVT-1')->setMontant(500.0)->setPaidAt(new DateTimeImmutable('2027-01-20'))
                ->setDescription('Avis de règlement')->setTranche($cotation->getTranches()->first());
            $paiement->setEntreprise($ent);
            $this->em->persist($paiement);
        } else {
            $note = (new \App\Entity\Note())->setNom('Note de commission')->setType(0)
                ->setAddressedTo(\App\Entity\Note::TO_ASSUREUR)->setReference('N-MVT-1')
                ->setValidated(true)->setSignature('');
            $note->setEntreprise($ent);
            $this->em->persist($note);
            $article = (new \App\Entity\Article())->setQuantite(1.0);
            $article->setNote($note)->setRevenuFacture($cotation->getRevenus()->first());
            $article->setEntreprise($ent);
            $this->em->persist($article);
            if ($mouvement === 'encaissementNote') {
                $paiement = (new \App\Entity\Paiement())->setMontant(100.0)->setReference('ENC-MVT-1')
                    ->setPaidAt(new DateTimeImmutable('2027-02-01'))->setNote($note);
                $paiement->setEntreprise($ent);
                $this->em->persist($paiement);
            }
        }
        $this->em->flush();
        $ids = [$base->getId(), $derivee->getId(), $successeur->getId()];
        $this->em->clear();

        return $ids;
    }

    /** ÉCRAN — un successeur qui a encaissé une prime ne peut pas être effacé par l'abandon. */
    public function testLEcranRefuseDAbandonnerUnSuccesseurQuiAEncaisse(): void
    {
        $s = $this->seed();
        [$baseId, $deriveeId, $successeurId] = $this->renouvelerPuisFinancer($s, 'encaissementPrime');

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $baseId);
        $this->assertResponseStatusCodeSame(409);
        $message = json_decode($this->client->getResponse()->getContent(), true)['message'];
        $this->assertStringContainsString(sprintf('#%d', $successeurId), $message, 'Le successeur est nommé.');
        $this->assertStringContainsString('1 encaissement de prime', $message);

        $this->em->clear();
        $base = $this->em->getRepository(Avenant::class)->find($baseId);
        $this->assertSame($deriveeId, $base->getPisteDeRenouvellement()?->getId(), 'Le lien n’a pas été coupé.');
        $this->assertSame(Avenant::RENEWAL_STATUS_RENEWED, $base->getRenewalStatus(), 'Le statut n’a pas été restitué.');
        $this->assertNotNull($this->em->getRepository(Avenant::class)->find($successeurId), 'Le successeur est intact.');
    }

    /** ÉCRAN — une note émise sur le successeur bloque aussi, et son encaissement est nommé. */
    public function testLEcranRefuseDAbandonnerUnSuccesseurFacture(): void
    {
        $s = $this->seed();
        [$baseId] = $this->renouvelerPuisFinancer($s, 'encaissementNote');

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $baseId);
        $this->assertResponseStatusCodeSame(409);
        $message = json_decode($this->client->getResponse()->getContent(), true)['message'];
        $this->assertStringContainsString('1 note émise', $message);
        $this->assertStringContainsString('1 encaissement de note', $message);
    }

    /** ÉCRAN — un sinistre déclaré sur le successeur interdit de l'effacer : un sinistre porte toujours une police. */
    public function testLEcranRefuseDAbandonnerUnSuccesseurSinistre(): void
    {
        $s = $this->seed();
        [$baseId, $deriveeId] = $this->renouvelerPuisFinancer($s, 'sinistre:2027-02-10');

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $baseId);
        $this->assertResponseStatusCodeSame(409);
        $message = json_decode($this->client->getResponse()->getContent(), true)['message'];
        $this->assertStringContainsString('1 sinistre déclaré', $message);
        $this->assertStringContainsString('un sinistre doit toujours porter une police existante', $message);
        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Piste::class)->find($deriveeId));
    }

    /** KET — même refus pour un sinistre déclaré sur le successeur. */
    public function testKetRefuseDAbandonnerUnSuccesseurSinistre(): void
    {
        $s = $this->seed();
        [$baseId] = $this->renouvelerPuisFinancer($s, 'sinistre:2027-02-10');
        $ket = $this->outil->execute(['mouvement' => 'renouvellement', 'avenantId' => $baseId, 'abandonnerMouvementExistant' => true], $this->scope($s));
        $this->assertFalse($ket->data['pret'] ?? true);
        $this->assertNull($ket->uiAction);
        $this->assertStringContainsString('1 sinistre déclaré', $ket->data['bloquant']);
    }

    /** Un sinistre de la POLICE DE BASE, survenu dans sa période, ne bloque pas l'abandon : la base survit. */
    public function testUnSinistreDeLaBaseNeBloquePasLAbandon(): void
    {
        $s = $this->seed();
        // Même référence, mais survenu pendant la période de la BASE (2026) : il appartient à
        // la base, qui survit à l'abandon. Rien ne s'y oppose.
        [$baseId] = $this->renouvelerPuisFinancer($s, 'sinistre:2026-05-01');
        $ket = $this->outil->execute(['mouvement' => 'renouvellement', 'avenantId' => $baseId, 'abandonnerMouvementExistant' => true], $this->scope($s));
        $this->assertTrue($ket->data['pret'] ?? false, json_encode($ket->data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * KET — même règle, même message : l'abandon d'un successeur facturé est refusé sans plan,
     * et le moteur de mutation lui-même refuse une suppression directe de l'opportunité.
     */
    public function testKetAppliqueLaMemeRegleQueLEcran(): void
    {
        $s = $this->seed();
        [$baseId, $deriveeId] = $this->renouvelerPuisFinancer($s, 'note');
        $scope = $this->scope($s);

        $ket = $this->outil->execute(['mouvement' => 'renouvellement', 'avenantId' => $baseId, 'abandonnerMouvementExistant' => true], $scope);
        $this->assertFalse($ket->data['pret'] ?? true);
        $this->assertNull($ket->uiAction);
        $this->assertStringContainsString('1 note émise', $ket->data['bloquant']);

        // Plan générique (preparer_operations) : le moteur oppose la même règle.
        $op = MutationPlan::fromArray([['op' => 'delete', 'entite' => 'Piste', 'id' => $deriveeId]])->operationsOrdonnees()[0];
        $user = $this->em->getRepository(Utilisateur::class)->find($s['user']->getId());
        try {
            $this->mutation->executer($op, $scope, $user);
            self::fail('La suppression devait être refusée.');
        } catch (\App\Service\Workspace\MutationException $e) {
            $this->assertStringContainsString('Impossible d’abandonner ce mouvement', $e->getMessage());
        }
        $this->assertNotNull($this->em->getRepository(Piste::class)->find($deriveeId));
    }

    // ───────────────────────── 8. Échéances « à régulariser » après annulation / résiliation ─────────────────────────

    /** Les échéances (ids) qu'un décalque met « à régulariser ». */
    private function tranchesARegulariser(array $decalque): array
    {
        return array_values(array_map(
            static fn (array $op) => $op['id'],
            array_filter($decalque['operations'], static fn (array $op) => $op['entite'] === 'Tranche' && $op['op'] === 'edit'),
        ));
    }

    private function tranche(array $s, string $nom): Tranche
    {
        return $this->em->getRepository(Tranche::class)->findOneBy(['nom' => $nom, 'cotation' => $s['cotation']->getId()]);
    }

    /**
     * LA BORNE, au jour près et au sens LARGE : T2 est exigible le 15/07/2026. Une
     * résiliation la VEILLE ou LE JOUR MÊME la met « à régulariser » (elle couvre une
     * période où la police n'existe plus) ; LE LENDEMAIN, non. T1 (15/01) n'est jamais
     * concernée. Aucun montant ne change.
     */
    public function testLaBorneDeLaDateDEffetEstAuJourEtAuSensLarge(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2')->getId();

        foreach (['2026-07-14' => [$t2], '2026-07-15' => [$t2], '2026-07-16' => []] as $effet => $attendu) {
            $d = $this->builder->construire(MouvementAvenant::Resiliation, $s['base'], ['dateEffet' => $effet], $this->scope($s));
            $this->assertSame($attendu, $this->tranchesARegulariser($d), 'Résiliation au ' . $effet);
            $this->assertSame(count($attendu), $d['regularisation']['nombre']);
        }

        // Un renouvellement ne touche aucune échéance.
        $this->assertSame([], $this->tranchesARegulariser($this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s))));
    }

    /** Exécutée, la résiliation pose l'état et mémorise l'état d'avant — sans changer un montant. */
    public function testLaResiliationMetLEcheanceARegulariserSansToucherAuxMontants(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);

        $t2 = $this->tranche($s, 'T2');
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $t2->getRegularisation());
        $this->assertNull($t2->getRegularisationAvant());
        $this->assertSame(Piste::AVENANT_RESILIATION, $t2->getRegularisationPar()?->getTypeAvenant());
        $this->assertSame(40.0, $t2->getPourcentage(), 'Le montant de l’échéance ne change pas.');
        $this->assertSame('2026-07-15', $t2->getPayableAt()->format('Y-m-d'));
        $this->assertNull($this->tranche($s, 'T1')->getRegularisation(), 'T1, exigible avant l’acte, reste due.');
    }

    /** Une échéance déjà encaissée, même en partie, n'est pas touchée : elle est signalée (ristourne possible). */
    public function testUneEcheanceDejaEncaisseeNEstPasToucheeMaisSignalee(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2');
        $paiement = (new \App\Entity\PaiementPrime())->setReference('PP-T2')->setMontant(100.0)
            ->setPaidAt(new DateTimeImmutable('2026-07-20'))->setDescription('Acompte')->setTranche($t2);
        $paiement->setEntreprise($s['ent']);
        $this->em->persist($paiement);
        $this->em->flush();
        $this->em->clear();

        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $d = $this->builder->construire(MouvementAvenant::Resiliation, $base, ['dateEffet' => '2026-06-15'], $this->scope($s));
        $this->assertSame([], $this->tranchesARegulariser($d));
        $this->assertCount(1, $d['regularisation']['ristournePossible']);
        $this->assertStringContainsString('« T2 » déjà encaissée', implode(' ', $d['avertissements']));
        $this->assertStringContainsString('ristourne', implode(' ', $d['avertissements']));
    }

    /**
     * CAS DE LA COMMISSION DÉJÀ FACTURÉE : l'échéance passe bien « à régulariser », mais la
     * note est nommée, dans l'aperçu (et Ket) comme sur la fiche.
     */
    public function testUneCommissionDejaFactureeEstSignalee(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2');
        $note = (new \App\Entity\Note())->setNom('Commission T2')->setType(\App\Entity\Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(\App\Entity\Note::TO_ASSUREUR)->setValidated(true)->setSignature('');
        $note->setEntreprise($s['ent']);
        $this->em->persist($note);
        $article = (new \App\Entity\Article())->setQuantite(1.0);
        $article->setNote($note)->setTranche($t2)->setRevenuFacture($s['cotation']->getRevenus()->first());
        $article->setEntreprise($s['ent']);
        $this->em->persist($article);
        $this->em->flush();
        $refNote = $note->getReference(); // numéro attribué à la validation
        $this->em->clear();

        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $d = $this->builder->construire(MouvementAvenant::Resiliation, $base, ['dateEffet' => '2026-06-15'], $this->scope($s));
        $this->assertSame([$t2->getId()], $this->tranchesARegulariser($d), 'Elle passe bien à régulariser.');
        $this->assertStringContainsString('Commission déjà facturée : note n° ' . $refNote . ', à régulariser auprès de l’assureur.', implode(' ', $d['avertissements']));
        $this->assertSame('Commission déjà facturée : note n° ' . $refNote . ', à régulariser auprès de l’assureur.', $d['regularisation']['echeances'][0]['commissionFacturee']);

        // L'aperçu de l'écran le dit aussi.
        $this->client->request('GET', '/admin/avenant/api/mouvement-apercu/resiliation/' . $base->getId() . '?dateEffet=2026-06-15');
        $html = json_decode($this->client->getResponse()->getContent(), true)['html'];
        $this->assertStringContainsString('à régulariser', $html);
        $this->assertStringContainsString('note n° ' . $refNote, $html);
        $this->assertStringContainsString('par note de crédit', $html);
    }

    /** Ket prépare exactement les mêmes écritures que l'écran (même builder). */
    public function testKetPrepareLesMemesEcrituresDeRegularisation(): void
    {
        $s = $this->seed();
        $ket = $this->outil->execute(['mouvement' => 'resiliation', 'avenantId' => $s['base']->getId(), 'dateEffet' => '2026-06-15'], $this->scope($s));
        $this->assertTrue($ket->data['pret'] ?? false, json_encode($ket->data, JSON_UNESCAPED_UNICODE));
        $ops = array_filter($ket->uiAction['plan'], static fn (array $op) => ($op['entite'] ?? '') === 'Tranche');
        $this->assertCount(1, $ops, 'Une écriture pour T2, dans le plan de Ket.');
        $this->assertSame(1, $ket->data['regularisation']['nombre']);
    }

    /**
     * ABANDON : les échéances reprennent EXACTEMENT leur état d'avant — y compris un état
     * non nul (« maintenue »), et même après avoir été déclarées dues entre-temps.
     */
    public function testLAbandonRestitueLEtatDAvantDesEcheances(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2');
        (new \ReflectionProperty($t2, 'regularisation'))->setValue($t2, Tranche::REGULARISATION_MAINTENUE);
        $this->em->flush();

        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $t2 = $this->tranche($s, 'T2');
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $t2->getRegularisation());
        $this->assertSame(Tranche::REGULARISATION_MAINTENUE, $t2->getRegularisationAvant());
        $t2->declarerDue();
        $this->em->flush();
        $this->em->clear();

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $s['base']->getId());
        $this->assertResponseIsSuccessful();
        $this->em->clear();

        $t2 = $this->tranche($s, 'T2');
        $this->assertSame(Tranche::REGULARISATION_MAINTENUE, $t2->getRegularisation(), 'Exactement l’état d’avant le mouvement.');
        $this->assertNull($t2->getRegularisationPar());
        $this->assertNull($t2->getRegularisationAvant());
    }

    /** Une note de crédit déjà émise sur une échéance régularisée interdit l'abandon (écran et Ket). */
    public function testUneNoteDeCreditEmiseInterditLAbandon(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $t2 = $this->tranche($s, 'T2');
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
        $avoir = (new \App\Entity\Note())->setNom('Avoir T2')->setType(\App\Entity\Note::TYPE_NOTE_DE_CREDIT)
            ->setAddressedTo(\App\Entity\Note::TO_ASSUREUR)->setValidated(true)->setSignature('');
        $avoir->setEntreprise($ent);
        $this->em->persist($avoir);
        $article = (new \App\Entity\Article())->setQuantite(1.0);
        $article->setNote($avoir)->setTranche($t2);
        $article->setEntreprise($ent);
        $this->em->persist($article);
        $this->em->flush();
        $refNote = $avoir->getReference(); // numéro attribué à la validation
        $this->em->clear();

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $s['base']->getId());
        $this->assertResponseStatusCodeSame(409);
        $this->assertStringContainsString('note n° ' . $refNote, json_decode($this->client->getResponse()->getContent(), true)['message']);

        $ket = $this->outil->execute(['mouvement' => 'resiliation', 'avenantId' => $s['base']->getId(), 'abandonnerMouvementExistant' => true], $this->scope($s));
        $this->assertStringContainsString('note de crédit', $ket->data['bloquant'] ?? '');
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $this->tranche($s, 'T2')->getRegularisation(), 'Rien n’a bougé.');
    }

    // ───────────────────────── 9. Les lecteurs : l'échéance à régulariser sort du recouvrement ─────────────────────────

    /** Résiliation au 15/06/2026 exécutée, indicateurs de T2 chargés. */
    private function resilierEtLireT2(array $s): Tranche
    {
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $t2 = $this->tranche($s, 'T2');
        static::getContainer()->get(\App\Services\Tranche\TranchePaiementService::class)->chargerIndicateurs([$t2]);

        return $t2;
    }

    /** La fiche : statut, badge neutre, explication, soldes à zéro — et le montant non réclamé reste visible. */
    public function testLaFicheDeLEcheanceDitPourquoiElleNEstPlusReclamee(): void
    {
        $s = $this->seed();
        $t2 = $this->resilierEtLireT2($s);

        $this->assertSame('À régulariser', $t2->statutPaiement);
        $this->assertSame('a_regulariser', $t2->regularisationEtat);
        $this->assertSame('faible', $t2->urgenceNiveau, 'Badge neutre, pas une alarme de retard.');
        $this->assertSame(0.0, (float) $t2->primeSoldeDue);
        $this->assertSame(0.0, (float) $t2->solde_restant_du);
        $this->assertSame(0.0, (float) $t2->commissionExigible);
        $this->assertGreaterThan(0.0, $t2->primeARegulariser, 'Le montant non réclamé reste visible.');
        $this->assertGreaterThan(0.0, (float) $t2->primeTranche, 'La prime de l’échéance ne change pas.');
        $this->assertStringContainsString('la résiliation de la police à effet du 15/06/2026', $t2->regularisationExplication);
        $this->assertStringContainsString('clôturez-la sans avoir', $t2->regularisationExplication, 'Rien de facturé : la voie est la clôture.');
        $this->assertNull($t2->commissionDejaFacturee);
    }

    /** Sur la fiche aussi : la commission déjà facturée est nommée. */
    public function testLaFicheNommeLaCommissionDejaFacturee(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2');
        $note = (new \App\Entity\Note())->setNom('Commission T2')->setType(\App\Entity\Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(\App\Entity\Note::TO_ASSUREUR)->setValidated(true)->setSignature('');
        $note->setEntreprise($s['ent']);
        $this->em->persist($note);
        $article = (new \App\Entity\Article())->setQuantite(1.0);
        $article->setNote($note)->setTranche($t2)->setRevenuFacture($s['cotation']->getRevenus()->first());
        $article->setEntreprise($s['ent']);
        $this->em->persist($article);
        $this->em->flush();
        $refNote = $note->getReference(); // numéro attribué à la validation

        $t2 = $this->resilierEtLireT2($s);
        $this->assertSame('Commission déjà facturée : note n° ' . $refNote . ', à régulariser auprès de l’assureur.', $t2->commissionDejaFacturee);
    }

    /** Chips et Ket : hors de toute dette ; seul l'axe « régularisation » (ou la liste sans filtre) la montre. */
    public function testLEcheanceSortDesChipsEtDuSuiviDesImpayes(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $service = static::getContainer()->get(\App\Services\Tranche\TranchePaiementService::class);
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
        $noms = static fn (array $r) => array_map(static fn (Tranche $t) => $t->getNom(), $r['items']);

        $this->assertNotContains('T2', $noms($service->lister($ent, ['prime' => 'impayee'], null, null, 1, 50)), 'Plus dans les primes impayées.');
        $this->assertNotContains('T2', $noms($service->lister($ent, ['commission' => 'impayee'], null, null, 1, 50)));
        $this->assertSame(['T2'], $noms($service->lister($ent, ['regularisation' => 'a_regulariser'], null, null, 1, 50)), 'Retrouvée par son chip.');
        $this->assertContains('T2', $noms($service->lister($ent, [], null, null, 1, 50)), 'Toujours visible sans filtre.');

        $ket = static::getContainer()->get(\App\Ai\Tool\SuiviImpayesTool::class)->execute(['axes' => ['prime' => 'impayee']], $this->scope($s));
        $this->assertNotContains('T2', array_column($ket->data['lignes'] ?? [], 'tranche'), 'Ket ne la réclame plus.');
    }

    /** Agrégats : police, proposition et client ne comptent plus la prime ni la commission non réclamées. */
    public function testLesSoldesAgregesExcluentLEcheanceARegulariser(): void
    {
        $s = $this->seed();
        $canvas = static::getContainer()->get(\App\Services\CanvasBuilder::class);
        $helper = static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class);

        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $canvas->loadAllCalculatedValues($base);
        $soldePrimeAvant = (float) $base->primeSoldeDue;
        $soldeComAvant = (float) $base->solde_restant_du;

        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $t2 = $this->tranche($s, 'T2');
        $partT2 = $helper->getTrancheARegulariser($t2);
        $this->assertGreaterThan(0.0, $partT2['prime']);

        $base = $this->em->getRepository(Avenant::class)->find($s['base']->getId());
        $canvas->loadAllCalculatedValues($base);
        $this->assertEqualsWithDelta($soldePrimeAvant - $partT2['prime'], (float) $base->primeSoldeDue, 0.01, 'Solde de prime de la police.');
        $this->assertEqualsWithDelta($soldeComAvant - $partT2['commission'], (float) $base->solde_restant_du, 0.01, 'Solde de commission (bandeau du tableau de bord).');
        $this->assertEqualsWithDelta($partT2['prime'], (float) $base->primeARegulariser, 0.01);

        $stats = $helper->getIndicateursGlobaux($this->em->getRepository(Entreprise::class)->find($s['ent']->getId()), false, ['clientCible' => $s['piste']->getClient()]);
        $this->assertEqualsWithDelta($partT2['prime'], $stats['prime_a_regulariser'], 0.01, 'Agrégats du client.');
    }

    /** Le relevé de compte (écran, lien public, e-mail, Ket) n'en réclame plus le solde au client. */
    public function testLeReleveDeCompteNeReclamePlusLEcheance(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $client = $this->em->getRepository(Client::class)->find($s['piste']->getClient()->getId());
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
        $contexte = static::getContainer()->get(\App\Service\Soa\SoaContextBuilder::class)->build($client, $ent, null, true);

        $lignes = [];
        foreach ($contexte['tranches'] as $ligne) {
            $lignes[$ligne['tranche']->getNom()] = $ligne;
        }
        $this->assertTrue($lignes['T2']['aRegulariser'] ?? false, 'Toujours sur le relevé, avec sa mention.');
        $this->assertSame(0.0, $lignes['T2']['primeSolde']);
        $this->assertArrayNotHasKey('aRegulariser', $lignes['T1'], 'T1 reste due.');
    }

    // ───────────────────────── 10. Régulariser une échéance, et le rattrapage ─────────────────────────

    /**
     * Les actions existent, chacune conditionnée à l'état où elle a un sens : l'AVOIR quand
     * une commission a été facturée, la CLÔTURE quand rien ne l'a été — jamais les deux.
     */
    public function testLesActionsDeRegularisationSontOffertes(): void
    {
        $s = $this->seed();
        $canvas = static::getContainer()->get(\App\Services\CanvasBuilder::class)->getEntityFormCanvas($this->tranche($s, 'T2'), $s['ent']->getId());
        $actions = [];
        foreach ($canvas['parametres']['attribute_actions'] ?? [] as $action) {
            $actions[$action['label']] = $action;
        }

        $this->assertSame(['field' => 'regularisationVoie', 'value' => 'avoir'], $actions['Régulariser par note de crédit']['condition']);
        $this->assertStringContainsString('type=credit', $actions['Régulariser par note de crédit']['url']);
        $this->assertSame(['field' => 'regularisationVoie', 'value' => 'cloture'], $actions['Clôturer sans avoir']['condition']);
        $this->assertStringEndsWith('/cloturer', $actions['Clôturer sans avoir']['url']);
        $this->assertSame('ui:tranche.regularisation-request', $actions['Clôturer sans avoir']['event'], 'Passe par la confirmation.');
        $this->assertSame(['field' => 'regularisationVoie', 'value' => 'cloturee'], $actions['Rouvrir la régularisation']['condition']);
        $this->assertSame(['field' => 'regularisationEtat', 'value' => 'a_regulariser'], $actions['Déclarer due quand même']['condition']);
        $this->assertSame(['field' => 'regularisationEtat', 'value' => 'maintenue'], $actions['Remettre à régulariser']['condition']);
    }

    /** Résiliation au 15/06/2026 exécutée ; rend T2 rechargée avec ses indicateurs. */
    private function t2Lue(array $s): Tranche
    {
        $this->em->clear();
        $t2 = $this->tranche($s, 'T2');
        static::getContainer()->get(\App\Services\Tranche\TranchePaiementService::class)->chargerIndicateurs([$t2]);

        return $t2;
    }

    /**
     * RIEN À CRÉDITER : la voie offerte est la clôture ; clôturée, l'échéance se lit
     * « Régularisée » (sans note), reste hors du recouvrement et du relevé ; on peut la
     * rouvrir, et un geste incohérent est refusé.
     */
    public function testCloturerSansAvoirPuisRouvrir(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $id = $this->tranche($s, 'T2')->getId();
        $this->assertSame('cloture', $this->t2Lue($s)->regularisationVoie, 'Rien de facturé : la clôture est la voie offerte.');

        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/cloturer');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('régularisée sans avoir', json_decode($this->client->getResponse()->getContent(), true)['message']);

        $t2 = $this->t2Lue($s);
        $this->assertSame(Tranche::REGULARISATION_CLOTUREE, $t2->getRegularisation());
        $this->assertSame('Régularisée', $t2->statutPaiement);
        $this->assertSame('regularisee', $t2->regularisationEtat);
        $this->assertSame('cloturee', $t2->regularisationVoie);
        $this->assertStringContainsString('Régularisée sans avoir', $t2->regularisationExplication);
        $this->assertSame(0.0, (float) $t2->primeSoldeDue, 'Toujours hors du recouvrement.');
        $this->assertSame(0, $this->em->getRepository(\App\Entity\Article::class)->count(['tranche' => $id]), 'Aucune note émise.');

        $service = static::getContainer()->get(\App\Services\Tranche\TranchePaiementService::class);
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
        $noms = static fn (array $r) => array_map(static fn (Tranche $t) => $t->getNom(), $r['items']);
        $this->assertNotContains('T2', $noms($service->lister($ent, ['prime' => 'impayee'], null, null, 1, 50)));
        $this->assertSame(['T2'], $noms($service->lister($ent, ['regularisation' => 'regularisee'], null, null, 1, 50)), 'Chip « Régularisée ».');

        $client = $this->em->getRepository(Client::class)->find($s['piste']->getClient()->getId());
        $soa = static::getContainer()->get(\App\Service\Soa\SoaContextBuilder::class)->build($client, $ent, null, true);
        $ligne = array_values(array_filter($soa['tranches'], static fn (array $l) => $l['tranche']->getNom() === 'T2'))[0];
        $this->assertSame(0.0, $ligne['primeSolde'], 'Le relevé ne la réclame pas.');

        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/cloturer');
        $this->assertResponseStatusCodeSame(409, 'Déjà clôturée.');
        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/maintenir');
        $this->assertResponseStatusCodeSame(409, 'Une échéance clôturée se rouvre d’abord.');

        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/rouvrir');
        $this->assertResponseIsSuccessful();
        $t2 = $this->t2Lue($s);
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $t2->getRegularisation());
        $this->assertSame('À régulariser', $t2->statutPaiement);
        $this->assertSame('cloture', $t2->regularisationVoie);
    }

    /** Une commission facturée : la clôture n'est pas offerte, et l'endpoint la refuse. */
    public function testCloturerEstRefuseeQuandUneCommissionAEteFacturee(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2');
        $note = (new \App\Entity\Note())->setNom('Commission T2')->setType(\App\Entity\Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(\App\Entity\Note::TO_ASSUREUR)->setReference('N-T2-5')->setValidated(true)->setSignature('');
        $note->setEntreprise($s['ent']);
        $this->em->persist($note);
        $article = (new \App\Entity\Article())->setQuantite(1.0);
        $article->setNote($note)->setTranche($t2)->setRevenuFacture($s['cotation']->getRevenus()->first());
        $article->setEntreprise($s['ent']);
        $this->em->persist($article);
        $this->em->flush();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);

        $this->assertSame('avoir', $this->t2Lue($s)->regularisationVoie, 'La voie offerte est la note de crédit.');
        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $t2->getId() . '/cloturer');
        $this->assertResponseStatusCodeSame(409);
        $this->assertStringContainsString('note de crédit', json_decode($this->client->getResponse()->getContent(), true)['message']);
        $this->em->clear();
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $this->tranche($s, 'T2')->getRegularisation());
    }

    /** L'abandon du mouvement restitue l'état d'avant, clôture comprise. */
    public function testLAbandonRestitueLEtatDAvantApresUneCloture(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $id = $this->tranche($s, 'T2')->getId();
        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/cloturer');
        $this->assertResponseIsSuccessful();

        $this->client->request('DELETE', '/admin/avenant/api/delete-piste-derivee/' . $s['base']->getId());
        $this->assertResponseIsSuccessful();
        $t2 = $this->t2Lue($s);
        $this->assertNull($t2->getRegularisation(), 'Exactement l’état d’avant le mouvement.');
        $this->assertNull($t2->getRegularisationPar());
        $this->assertNotSame('Régularisée', $t2->statutPaiement);
        $this->assertGreaterThan(0.0, (float) $t2->primeSoldeDue, 'De nouveau réclamée.');
    }

    /** « Déclarer due » remet l'échéance dans le recouvrement ; « Remettre » l'en ressort ; un geste incohérent est refusé. */
    public function testDeclarerDuePuisRemettreARegulariser(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $id = $this->tranche($s, 'T2')->getId();
        $service = static::getContainer()->get(\App\Services\Tranche\TranchePaiementService::class);
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
        $impayees = fn () => array_map(static fn (Tranche $t) => $t->getNom(), $service->lister($ent, ['prime' => 'impayee'], null, null, 1, 50)['items']);

        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/maintenir');
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertSame(Tranche::REGULARISATION_MAINTENUE, $this->tranche($s, 'T2')->getRegularisation());
        $this->assertContains('T2', $impayees(), 'Déclarée due : elle revient dans le recouvrement.');

        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/maintenir');
        $this->assertResponseStatusCodeSame(409, 'Déjà déclarée due.');

        $this->client->request('POST', '/admin/tranche/api/regularisation/' . $id . '/remettre');
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $this->tranche($s, 'T2')->getRegularisation());
        $this->assertNotContains('T2', $impayees());
    }

    /**
     * « Régulariser par note de crédit » : la fenêtre de facturation, en type crédit, reprend
     * la commission déjà facturée ; une fois l'avoir émis, l'échéance se lit « Régularisée ».
     */
    public function testUneNoteDeCreditRegulariseLEcheance(): void
    {
        $s = $this->seed();
        $t2 = $this->tranche($s, 'T2');
        $revenu = $s['cotation']->getRevenus()->first();
        $note = (new \App\Entity\Note())->setNom('Commission T2')->setType(\App\Entity\Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(\App\Entity\Note::TO_ASSUREUR)->setReference('N-T2-7')->setValidated(true)->setSignature('');
        $note->setEntreprise($s['ent']);
        $note->setAssureur($s['cotation']->getAssureur());
        $this->em->persist($note);
        $article = (new \App\Entity\Article())->setQuantite(1.0);
        $article->setNote($note)->setTranche($t2)->setRevenuFacture($revenu);
        $article->setEntreprise($s['ent']);
        $this->em->persist($article);
        $this->em->flush();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);

        $this->client->request('GET', '/admin/note/facturation-picker?type=credit&ids=' . $t2->getId());
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Note de crédit', $html);
        $this->assertStringContainsString('data-facturation-picker-type-value="credit"', $html);
        // Le vocabulaire de l'avoir, pas celui de la facture : rien à encaisser.
        $this->assertStringContainsString('Commissions à créditer', $html);
        $this->assertStringContainsString('Montant à créditer', $html);
        $this->assertStringNotContainsString('Où vous faire payer', $html);
        $this->assertStringNotContainsString('suivi du recouvrement', $html);

        $this->client->request('POST', '/admin/note/facturation', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'type' => 'credit',
            'destinataire' => 'assureur',
            'lignes' => [['trancheId' => $t2->getId(), 'revenuId' => $revenu->getId()]],
        ]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Note de crédit', json_decode($this->client->getResponse()->getContent(), true)['message']);

        $this->em->clear();
        $t2 = $this->tranche($s, 'T2');
        static::getContainer()->get(\App\Services\Tranche\TranchePaiementService::class)->chargerIndicateurs([$t2]);
        $this->assertSame('Régularisée', $t2->statutPaiement);
        $this->assertSame('regularisee', $t2->regularisationEtat);
        $this->assertStringContainsString('Régularisée par la note de crédit', $t2->regularisationExplication);
    }

    /**
     * RATTRAPAGE : --dry-run liste sans écrire ; le mode réel marque et affiche le même
     * récapitulatif ; un second passage ne marque plus rien (idempotence).
     */
    public function testLaCommandeDeRattrapageEstIdempotenteEtSaitSimuler(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        // Situation d'avant la règle : la résiliation existe, l'échéance n'a pas été marquée.
        $this->tranche($s, 'T2')->leverRegularisation();
        $this->em->flush();
        $this->em->clear();

        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $commande = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:echeances:regulariser'));

        $commande->execute(['--rattrapage' => true, '--dry-run' => true]);
        $sortie = $commande->getDisplay();
        $this->assertStringContainsString('SIMULATION', $sortie);
        $this->assertStringContainsString('POL-MVT-1', $sortie, 'La police.');
        $this->assertStringContainsString('T2 · 15/07/2026', $sortie, 'L’échéance.');
        $this->assertStringContainsString('Résiliation au 15/06/2026', $sortie, 'L’acte en cause.');
        $this->assertStringContainsString('serait marquée', $sortie);
        $this->em->clear();
        $this->assertNull($this->tranche($s, 'T2')->getRegularisation(), 'Rien n’est écrit en simulation.');

        $commande->execute(['--rattrapage' => true]);
        $this->assertStringContainsString('1 échéance marquée', $commande->getDisplay());
        $this->em->clear();
        $this->assertSame(Tranche::REGULARISATION_A_REGULARISER, $this->tranche($s, 'T2')->getRegularisation());

        $commande->execute(['--rattrapage' => true]);
        $this->assertStringContainsString('0 échéance marquée, 1 déjà marquée', $commande->getDisplay(), 'Idempotente.');
    }

    // ───────────────────────── 11. Tous les lecteurs, rubrique par rubrique ─────────────────────────

    /**
     * Les soldes des rubriques Assureurs, Partenaires, Groupes, Risques et Contacts passent
     * par le même agrégat (getIndicateursGlobaux) : chacun perd exactement la part de T2.
     */
    public function testLesSoldesDesRubriquesExcluentLEcheanceARegulariser(): void
    {
        $s = $this->seed();
        $client = $this->em->getRepository(Client::class)->find($s['piste']->getClient()->getId());
        $groupe = (new \App\Entity\Groupe())->setNom('Groupe MVT')->setDescription('Groupe de test');
        $groupe->setEntreprise($s['ent']);
        $this->em->persist($groupe);
        $client->setGroupe($groupe);
        $contact = (new \App\Entity\Contact())->setNom('Contact MVT')->setTelephone('+243000000001')
            ->setType(\App\Entity\Contact::TYPE_CONTACT_PRODUCTION)->setClient($client);
        $contact->setEntreprise($s['ent']);
        $this->em->persist($contact);
        $this->em->flush();
        $ids = [
            'Assureur'   => [Assureur::class, $s['cotation']->getAssureur()->getId()],
            'Partenaire' => [Partenaire::class, $s['partenaire']->getId()],
            'Groupe'     => [\App\Entity\Groupe::class, $groupe->getId()],
            'Risque'     => [Risque::class, $s['piste']->getRisque()->getId()],
            'Contact'    => [\App\Entity\Contact::class, $contact->getId()],
        ];
        $canvas = static::getContainer()->get(\App\Services\CanvasBuilder::class);
        $soldes = function () use ($ids, $canvas): array {
            $this->em->clear();
            $lus = [];
            foreach ($ids as $rubrique => [$classe, $id]) {
                $entite = $this->em->getRepository($classe)->find($id);
                $canvas->loadAllCalculatedValues($entite);
                $lus[$rubrique] = [(float) $entite->primeSoldeDue, (float) $entite->solde_restant_du];
            }

            return $lus;
        };

        $avant = $soldes();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $partT2 = static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class)
            ->getTrancheARegulariser($this->tranche($s, 'T2'));
        $this->assertGreaterThan(0.0, $partT2['prime']);
        $this->assertGreaterThan(0.0, $partT2['commission']);

        foreach ($soldes() as $rubrique => [$prime, $commission]) {
            $this->assertEqualsWithDelta($avant[$rubrique][0] - $partT2['prime'], $prime, 0.01, $rubrique . ' : solde de prime.');
            $this->assertEqualsWithDelta($avant[$rubrique][1] - $partT2['commission'], $commission, 0.01, $rubrique . ' : solde de commission.');
        }
    }

    /** Ket : indicateur_calcule (même agrégat) et paiements_prime (même hydratation que l'écran). */
    public function testLesOutilsDeKetNeReclamentPlusLEcheance(): void
    {
        $s = $this->seed();
        $indicateur = static::getContainer()->get(\App\Ai\Tool\IndicateurCalculeTool::class);
        $args = ['code' => 'prime_totale_solde', 'entite' => 'Client', 'id' => $s['piste']->getClient()->getId()];
        $this->em->clear(); // le client relu de la base connaît sa piste
        $avant = (float) $indicateur->execute($args, $this->scope($s))->data['valeur'];

        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $t2 = $this->tranche($s, 'T2');
        $partT2 = static::getContainer()->get(\App\Services\Canvas\Indicator\IndicatorCalculationHelper::class)->getTrancheARegulariser($t2);
        $this->em->clear();
        $this->assertEqualsWithDelta($avant - $partT2['prime'], (float) $indicateur->execute($args, $this->scope($s))->data['valeur'], 0.01, 'indicateur_calcule');

        $paiements = static::getContainer()->get(\App\Ai\Tool\PaiementsPrimeTool::class)->execute(['trancheId' => $t2->getId()], $this->scope($s));
        $this->assertSame('À régulariser', $paiements->data['tranche']['statutPaiement'], 'paiements_prime');
        $this->assertSame('À régulariser', $paiements->data['tranche']['urgence']);
        $this->assertSame(0.0, $paiements->data['prime']['solde']);
        $this->assertArrayNotHasKey('commissionExigible', $paiements->data);
        $this->assertStringContainsString('sort du recouvrement', $paiements->data['tranche']['regularisation'] ?? '');
    }

    /** L'export EtatDuPortefeuille : la ligne de T2 reste, sans solde réclamé. */
    public function testLExportEtatDuPortefeuilleNeReclamePlusLEcheance(): void
    {
        $s = $this->seed();
        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $this->em->clear();
        $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());

        $lignes = [];
        foreach (static::getContainer()->get(\App\Echange\Etat\EtatDuPortefeuille::class)->lignes($ent) as $ligne) {
            $lignes[$ligne['trancheNom']] = $ligne;
        }
        $this->assertSame(0.0, $lignes['T2']['primeSolde']);
        $this->assertSame(0.0, $lignes['T2']['commissionSolde']);
        $this->assertSame(0.0, $lignes['T2']['commissionExigible']);
        $this->assertGreaterThan(0.0, (float) $lignes['T2']['primeTotale'], 'La prime de l’échéance reste exportée.');
        $this->assertGreaterThan(0.0, (float) $lignes['T1']['primeSolde'], 'T1 reste due.');
    }

    /**
     * Badge d'urgence et recherche : T2 est échue au 09/10/2026 — sans l'acte, badge
     * CRITIQUE ; à régulariser, badge neutre. La recherche par axes de paiement
     * (JSBDynamicSearchService, celle des chips et de Ket) l'écarte aussi.
     */
    public function testLeBadgeDUrgenceEtLaRechercheEcartentLEcheance(): void
    {
        $s = $this->seed();
        $search = static::getContainer()->get(\App\Services\JSBDynamicSearchService::class);
        $Scope = \App\Services\Search\TranchePaiementScope::class;
        $noms = function (array $criteres) use ($s, $search): array {
            $this->em->clear();
            $ent = $this->em->getRepository(Entreprise::class)->find($s['ent']->getId());
            $r = $search->search(Tranche::class, $criteres, $ent, null, 1, 50);

            return array_map(static fn (Tranche $t) => $t->getNom(), $r['data']);
        };

        $this->tranche($s, 'T2')->setEcheanceAt(new DateTimeImmutable('2026-07-15'));
        $this->em->flush();
        $this->assertSame('critique', $this->t2Lue($s)->urgenceNiveau, 'Avant l’acte : échue et impayée.');
        $this->assertContains('T2', $noms([$Scope::AXE_PRIME => $Scope::IMPAYEE]));
        $this->assertContains('T2', $noms([$Scope::AXE_ECHEANCE => $Scope::ECHUE]));

        $this->executer($s, MouvementAvenant::Resiliation, ['dateEffet' => '2026-06-15']);
        $t2 = $this->t2Lue($s);
        $this->assertSame('faible', $t2->urgenceNiveau, 'Badge neutre.');
        $this->assertSame('À régulariser', $t2->urgenceRecouvrement);
        $this->assertNotContains('T2', $noms([$Scope::AXE_PRIME => $Scope::IMPAYEE]), 'Recherche : primes impayées.');
        $this->assertNotContains('T2', $noms([$Scope::AXE_COMMISSION => $Scope::IMPAYEE]));
        $this->assertNotContains('T2', $noms([$Scope::AXE_ECHEANCE => $Scope::ECHUE]), 'Recherche : échues.');
        $this->assertSame(['T2'], $noms([$Scope::AXE_REGULARISATION => $Scope::A_REGULARISER]));
        $this->assertContains('T2', $noms([]), 'Sans axe, la recherche la montre toujours.');
    }

    /** POINT 8 — le nom de l'opportunité dérivée ne porte qu'UN préfixe, celui du mouvement. */
    public function testLeNomDeLOpportuniteNePorteQuUnPrefixe(): void
    {
        $s = $this->seed();
        $s['piste']->setNom('Prorogation — Renouvellement — Incendie ACME 2026');

        $d = $this->builder->construire(MouvementAvenant::Renouvellement, $s['base'], [], $this->scope($s));
        $this->assertSame('Renouvellement — Incendie ACME 2026', $this->op($d, 'Piste')['champs']['nom']);
    }
}
