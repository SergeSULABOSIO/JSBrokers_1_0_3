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
    private const ENT   = 'PHPUnit-KetMouvement';
    private const OWNER = 'phpunit-ketmouvement-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PreparerMouvementAvenantTool $outil;
    private MouvementAvenantBuilder $builder;
    private WorkspaceMutationService $mutation;

    protected function setUp(): void
    {
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
            'DELETE cp FROM condition_partage cp JOIN entreprise e ON cp.entreprise_id = e.id WHERE e.nom = :n',
            'DELETE pp FROM piste_partenaire pp JOIN piste p ON pp.piste_id = p.id JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n',
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
            'DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
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
        $piste->addPartenaire($partenaire);

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

        return compact('ent', 'inv', 'user', 'base', 'piste', 'cotation', 'autreRisque');
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
        $this->assertCount(1, $piste['partenaires'], 'Le partenaire est reconduit.');

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

        // Le lien retour sur la police de base, sans toucher à son statut.
        $lien = $this->op($d, 'Avenant', 'edit')['champs'];
        $this->assertSame('@mouvement', $lien['pisteDeRenouvellement']);
        $this->assertArrayNotHasKey('renewalStatus', $lien, 'Une police renouvelée reste en vigueur jusqu’à son échéance.');
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

        // Les partenaires suivent même un acte de fin (la rétro reste due sur le couru).
        $this->assertCount(1, $this->op($d, 'Piste')['champs']['partenaires']);
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
     * L'IMPASSE SANS SORTIE, corrigée. Une police dont le renouvellement est AMORCÉ
     * (opportunité dérivée créée, aucun avenant émis) ne pouvait plus rien : l'outil
     * refusait à vie, et la rubrique lui retirait ses quatre boutons de mouvement. Résultat
     * en production : l'utilisateur redemandait le renouvellement encore et encore, et
     * AUCUN bouton n'apparaissait jamais.
     *
     * Le refus doit désormais NOMMER l'écriture qui reste — faire valider la proposition —
     * pour que l'assistant enchaîne sur un vrai plan, donc un vrai bouton.
     */
    public function testMouvementAmorceNommeLEcritureQuiResteEtLaPropositionAValider(): void
    {
        $s = $this->seed();

        // Renouvellement amorcé : opportunité dérivée + proposition, mais AUCUN avenant.
        $derivee = (new Piste())
            ->setNom('Renouvellement — amorcé')->setClient($s['piste']->getClient())->setRisque($s['piste']->getRisque())
            ->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)->setDescriptionDuRisque('x')->setExercice(2027);
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $this->em->persist($derivee);

        $proposition = (new Cotation())->setNom('Offre de renouvellement - SFA')->setDuree(365);
        $proposition->setPiste($derivee);
        $proposition->setEntreprise($s['ent']);
        $derivee->addCotation($proposition);
        $this->em->persist($proposition);

        $s['base']->setPisteDeRenouvellement($derivee);
        $this->em->flush();

        $resultat = $this->outil->execute(
            ['mouvement' => 'renouvellement', 'avenantId' => $s['base']->getId()],
            $this->scope($s),
        );

        $this->assertTrue($resultat->data['mouvementAmorce'] ?? false, 'Amorcé, donc pas scellé.');
        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNull($resultat->uiAction, 'Aucun bouton pour le mouvement lui-même.');

        // La proposition à faire valider est DÉSIGNÉE, avec son identifiant.
        $this->assertSame(
            [$proposition->getId()],
            array_column($resultat->data['propositionsEnAttente'], 'cotationId'),
        );
        $this->assertStringContainsString('FAIRE VALIDER', $resultat->data['prochaineEtape']);

        // Et la consigne envoie l'assistant préparer CETTE écriture, dans le même tour.
        $this->assertStringContainsString('preparer_operations', $resultat->data['note']);
        $this->assertStringContainsString('MÊME TOUR', $resultat->data['note']);
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
     * À l'inverse, on n'abandonne PAS un mouvement dont le sort est scellé : un avenant
     * successeur porte la couverture, et le supprimer détruirait une police vivante.
     */
    public function testAbandonRefuseSurUnMouvementScelle(): void
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

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNull($resultat->uiAction, 'Aucun bouton : rien ne doit pouvoir être détruit ici.');
        $this->assertStringContainsString('SCELLÉ', $resultat->data['bloquant']);
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
        $this->assertCount(1, $derivee->getPartenaires(), 'Le partenaire est reconduit en base.');
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
        $this->assertStringContainsString('Aucune information ne vous est demandée', $html, 'Un renouvellement ne demande rien.');
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

            // Un renouvellement n'a aucun champ (il ne demande rien) ; les trois
            // autres exposent leur champ unique au pattern maison, à icône incrustée.
            if ($mouvement === 'renouvellement') {
                $this->assertStringNotContainsString('jsb-picker-field', $html, 'Un renouvellement ne demande rien : aucun champ.');
                continue;
            }
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

        // Le nouvel avenant de résiliation est actif, la police de base ne l'est plus.
        $this->assertSame(1, $dashboard->getPoliciesActives($s['ent']), 'La police résiliée cède sa place à l’acte de résiliation.');
    }
}
