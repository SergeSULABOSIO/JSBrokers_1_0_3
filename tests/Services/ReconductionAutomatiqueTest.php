<?php

namespace App\Tests\Services;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use App\EventListener\ReconductionPartageListener;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE PARTAGE SUIT LA POLICE, QUEL QUE SOIT LE CHEMIN QUI ÉCRIT SA SUITE.
 *
 * ── CE QUE CE TEST FERME ────────────────────────────────────────────────────────────
 * La reconduction n'existait que là où quelqu'un pensait à l'appeler — le formulaire de
 * renouvellement et l'import de bordereau. Un plan d'écriture générique de l'assistant qui
 * pose `avenantDeBase` par une autre porte ne reconduisait rien, et le partage d'une police
 * disparaissait au renouvellement sans un mot.
 *
 * Ces tests écrivent la piste dérivée À LA MAIN — un `persist()`, un `flush()`, puis la fin
 * de requête. Jamais un appel au service. C'est précisément le « quatrième chemin » que le
 * plan redoutait : s'il repart avec son partage, tous les autres aussi.
 *
 * ── ET LE SECOND GESTE, CELUI DE LA PARITÉ ──────────────────────────────────────────
 * Le plan de l'assistant SAIT écrire les conditions, mais pas leur ciblage : `produits` est
 * `mapped: false` et passe par des routes dédiées. Une condition qui annonce « inclure ces
 * risques-ci » sans en nommer aucun est une règle cassée — inerte si elle inclut,
 * universelle si elle exclut. L'abonné complète alors le seul ciblage, sans rien créer.
 */
class ReconductionAutomatiqueTest extends WebTestCase
{
    private const ENT = 'PHPUnit-ReconductionAuto';
    private const OWNER = 'phpunit-reconduction-auto@test.local';

    private EntityManagerInterface $em;
    private ReconductionPartageListener $abonne;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->abonne = static::getContainer()->get(ReconductionPartageListener::class);
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
        $conn->executeStatement(
            'DELETE cpr FROM condition_partage_risque cpr
             JOIN condition_partage cp ON cpr.condition_partage_id = cp.id
             JOIN entreprise e ON cp.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => $n],
        );
        $conn->executeStatement(
            'DELETE pc FROM piste_condition_partage pc
             JOIN piste p ON pc.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => $n],
        );

        foreach (['avenant', 'cotation', 'condition_partage', 'piste', 'risque', 'client', 'assureur', 'partenaire', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => $n],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => $n]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Une police d'INCENDIE, son avenant, et une condition de partage CIBLÉE sur deux
     * risques — le cas où le ciblage a quelque chose à dire.
     *
     * @return array{ent: Entreprise, inv: Invite, base: Avenant, source: Piste,
     *               incendie: Risque, degats: Risque, partenaire: Partenaire}
     */
    private function semer(): array
    {
        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
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

        $client = $scoper((new Client())->setNom('Client Reconduction')->setExonere(false));
        $assureur = $scoper((new Assureur())->setNom('SFA Reconduction')->setEmail('sfa@reconduction.test')
            ->setNumimpot('IMP-REC')->setIdnat('IDNAT-REC')->setRccm('RCCM-REC'));

        $incendie = $scoper((new Risque())->setNomComplet('Incendie')->setCode('REC-INC')
            ->setDescription('Incendie')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true));
        $degats = $scoper((new Risque())->setNomComplet('Dégâts des eaux')->setCode('REC-DEG')
            ->setDescription('Dégâts des eaux')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true));

        $partenaire = $scoper((new Partenaire())->setNom('SUNU Reconduction')->setPart(20.0));

        $source = $scoper((new Piste())->setNom('Police Incendie')
            ->setClient($client)->setRisque($incendie)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Entrepôt')
            ->setExercice((int) (new DateTimeImmutable('-1 year'))->format('Y')));
        $source->setPartenaire($partenaire);

        $condition = (new ConditionPartage())->setNom('Apport SUNU 20 %')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($partenaire);
        $condition->addProduit($incendie);
        $condition->addProduit($degats);
        $scoper($condition);
        $source->addConditionsPartageExceptionnelle($condition);

        $cotation = $scoper((new Cotation())->setNom('Offre Incendie')->setDuree(12)->setAssureur($assureur));
        $cotation->setPiste($source);

        $base = $scoper((new Avenant())->setReferencePolice('REC-0001')->setNumero('0')
            ->setDescription('Police incendie')
            ->setStartingAt(new DateTimeImmutable('-1 year -1 day'))
            ->setEndingAt(new DateTimeImmutable('-1 day'))
            ->setCotation($cotation));

        $this->em->flush();
        $this->client->loginUser($user);

        return compact('ent', 'inv', 'base', 'source', 'incendie', 'degats', 'partenaire');
    }

    /** Une piste dérivée nue, écrite comme n'importe quel chemin générique l'écrirait. */
    private function derivee(array $s, string $nom = 'Renouvellement'): Piste
    {
        $derivee = (new Piste())->setNom($nom)
            ->setClient($s['source']->getClient())->setRisque($s['incendie'])
            ->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT)
            ->setDescriptionDuRisque('Entrepôt')
            ->setExercice((int) date('Y'));
        $derivee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->setAvenantDeBase($s['base']);

        return $derivee;
    }

    /**
     * LA FIN DE LA REQUÊTE — le moment où l'abonné tranche.
     *
     * Il n'agit pas au flush, et c'est délibéré : le plan de l'assistant écrit la piste
     * seule PUIS ses conditions, chacune avec son propre flush. Trancher au flush le
     * faisait reconduire sur une piste encore nue, que le plan garnissait ensuite —
     * quatre conditions au lieu de deux. Ces tests appellent donc exactement le geste que
     * `kernel.terminate` déclenche en vrai.
     */
    private function finDeRequete(): void
    {
        $this->abonne->reconduireLesEnAttente();
    }

    /** @return ConditionPartage[] les conditions propres d'une piste, relues en base */
    private function conditionsDe(int $pisteId): array
    {
        $this->em->clear();

        return $this->em->getRepository(ConditionPartage::class)->findBy(['piste' => $pisteId]);
    }

    /**
     * ⚠ LE CŒUR DU LOT : un `persist()` + `flush()` nu suffit.
     *
     * Aucun appel au service de reconduction. C'est le chemin qu'un plan d'écriture
     * générique emprunte — et celui qui ne reconduisait rien.
     */
    public function testUnePisteEcriteParNimporteQuelCheminRepartAvecSonPartage(): void
    {
        $s = $this->semer();

        $derivee = $this->derivee($s);
        $this->em->persist($derivee);
        $this->em->flush();
        $this->finDeRequete();
        $id = $derivee->getId();

        $conditions = $this->conditionsDe($id);
        self::assertCount(1, $conditions, 'La condition de la police de base a suivi.');
        self::assertSame(20.0, $conditions[0]->getTaux());

        $vises = array_map(static fn (Risque $r) => $r->getCode(), $conditions[0]->getProduits()->toArray());
        sort($vises);
        self::assertSame(['REC-DEG', 'REC-INC'], $vises, 'Avec son ciblage, pas une traduction.');

        $derivee = $this->em->getRepository(Piste::class)->find($id);
        self::assertSame(
            $s['partenaire']->getId(),
            $derivee->getPartenaire()?->getId(),
            'Et l’intermédiaire aussi : sans lui, la condition nommerait quelqu’un que l’affaire ne connaît pas.',
        );
    }

    /**
     * ⚠ LE SECOND GESTE — celui qui rend la parité avec l'assistant vraie.
     *
     * On reproduit ce que le plan de Ket écrit : la condition, avec son CRITÈRE, mais sans
     * ses risques — `produits` ne passe pas par un formulaire. Sans l'abonné, cette
     * condition resterait inerte (elle inclut une liste vide) et la rétrocommission
     * disparaîtrait, en silence.
     */
    public function testLeCiblageQueLePlanNeSaitPasEcrireEstComplete(): void
    {
        $s = $this->semer();

        $derivee = $this->derivee($s);
        $ecriteParLePlan = (new ConditionPartage())->setNom('Apport SUNU 20 %')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($s['partenaire']);
        $ecriteParLePlan->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->addConditionsPartageExceptionnelle($ecriteParLePlan);

        $this->em->persist($derivee);
        $this->em->flush();
        $this->finDeRequete();
        $id = $derivee->getId();

        $conditions = $this->conditionsDe($id);
        self::assertCount(1, $conditions, 'Rien n’est créé : on complète, on ne double pas.');

        $vises = array_map(static fn (Risque $r) => $r->getCode(), $conditions[0]->getProduits()->toArray());
        sort($vises);
        self::assertSame(['REC-DEG', 'REC-INC'], $vises, 'Le ciblage est allé se chercher sur la police de base.');
    }

    /**
     * ET LA POLICE DE BASE GARDE LES SIENS — le contrôle qui prouve que le ManyToMany
     * tient. Sous l'ancienne cardinalité, rattacher ces risques à la dérivée les aurait
     * retirés d'ici, cassant la rétrocommission de la police d'origine.
     */
    public function testLaPoliceDeBaseNePerdRien(): void
    {
        $s = $this->semer();
        $sourceId = $s['source']->getId();

        $derivee = $this->derivee($s);
        $this->em->persist($derivee);
        $this->em->flush();
        $this->finDeRequete();

        $conditions = $this->conditionsDe($sourceId);
        self::assertCount(1, $conditions);
        $vises = array_map(static fn (Risque $r) => $r->getCode(), $conditions[0]->getProduits()->toArray());
        sort($vises);
        self::assertSame(['REC-DEG', 'REC-INC'], $vises);
    }

    /**
     * ⚠ UN NOM AMBIGU NE COMPLÈTE RIEN.
     *
     * Deux conditions homonymes et ciblées dans la police de base : deviner laquelle a
     * servi de modèle reviendrait à choisir au hasard sur qui l'argent tombe. Mieux vaut un
     * ciblage vide, que l'utilisateur voit et corrige, qu'un ciblage faux qui paie sans
     * qu'il le sache.
     */
    public function testUnNomAmbiguNeCompleteRien(): void
    {
        $s = $this->semer();

        // Une homonyme, ciblée elle aussi, mais sur un seul risque.
        $homonyme = (new ConditionPartage())->setNom('Apport SUNU 20 %')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($s['partenaire']);
        $homonyme->addProduit($s['degats']);
        $homonyme->setEntreprise($s['ent'])->setInvite($s['inv']);
        $s['source']->addConditionsPartageExceptionnelle($homonyme);
        $this->em->persist($homonyme);
        $this->em->flush();

        $derivee = $this->derivee($s);
        $ecriteParLePlan = (new ConditionPartage())->setNom('Apport SUNU 20 %')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(20.0)
            ->setCritereRisque(ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES)
            ->setPartenaire($s['partenaire']);
        $ecriteParLePlan->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->addConditionsPartageExceptionnelle($ecriteParLePlan);

        $this->em->persist($derivee);
        $this->em->flush();
        $this->finDeRequete();
        $id = $derivee->getId();

        $conditions = $this->conditionsDe($id);
        self::assertCount(1, $conditions);
        self::assertCount(
            0,
            $conditions[0]->getProduits(),
            'On préfère l’absence visible au faux ciblage silencieux.',
        );
    }

    /**
     * UNE PISTE DÉRIVÉE COMPLÈTE N'EST PAS RETOUCHÉE, et rien n'est doublé quand elle est
     * réenregistrée — c'est la garde qui rend l'abonné sans danger.
     */
    public function testUnePisteDejaCompleteNEstPasRetouchee(): void
    {
        $s = $this->semer();

        $derivee = $this->derivee($s);
        $ajustee = (new ConditionPartage())->setNom('Taux renégocié')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)->setTaux(12.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($s['partenaire']);
        $ajustee->setEntreprise($s['ent'])->setInvite($s['inv']);
        $derivee->addConditionsPartageExceptionnelle($ajustee);

        $this->em->persist($derivee);
        $this->em->flush();
        $this->finDeRequete();
        $id = $derivee->getId();

        $conditions = $this->conditionsDe($id);
        self::assertCount(1, $conditions, 'Rien n’a été ajouté par-dessus la décision.');
        self::assertSame(12.0, $conditions[0]->getTaux());

        // Et un second enregistrement n'en ajoute pas davantage.
        $derivee = $this->em->getRepository(Piste::class)->find($id);
        $derivee->setDescriptionDuRisque('Entrepôt agrandi');
        $this->em->flush();
        $this->finDeRequete();

        self::assertCount(1, $this->conditionsDe($id));
    }
    /**
     * ⚠ ET LE CÂBLAGE TIENT POUR DE BON : une VRAIE requête suffit.
     *
     * Le formulaire de renouvellement appelait la reconduction lui-même ; il ne l'appelle
     * plus. Ce test passe par l'endpoint réel — donc par la terminaison de noyau — et
     * vérifie que la piste dérivée repart quand même avec le partage de sa police de base,
     * ciblage compris. Sans l'abonné, il ne resterait rien.
     */
    public function testLeFormulaireDeRenouvellementNAppellePlusRienEtReconduitQuandMeme(): void
    {
        $s = $this->semer();

        $this->client->request('POST', '/admin/piste/api/submit', [
            'idEntreprise' => $s['ent']->getId(),
            'idInvite' => $s['inv']->getId(),
            'idAvenant' => $s['base']->getId(),
            'nom' => 'Renouvellement par formulaire',
            'client' => $s['source']->getClient()->getId(),
            'risque' => $s['incendie']->getId(),
            'descriptionDuRisque' => 'Entrepôt',
            'exercice' => (string) ((int) date('Y') + 1),
            'typeAvenant' => (string) Piste::AVENANT_RENOUVELLEMENT,
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();

        $derivee = $this->em->getRepository(Piste::class)->findOneBy(['nom' => 'Renouvellement par formulaire']);
        self::assertNotNull($derivee, 'La piste dérivée est écrite.');
        self::assertSame($s['base']->getId(), $derivee->getAvenantDeBase()?->getId(), 'Et reliée à sa police de base.');

        $conditions = $this->em->getRepository(ConditionPartage::class)->findBy(['piste' => $derivee->getId()]);
        self::assertCount(1, $conditions, 'Le partage a suivi, sans que le contrôleur ait rien demandé.');

        $vises = array_map(static fn (Risque $r) => $r->getCode(), $conditions[0]->getProduits()->toArray());
        sort($vises);
        self::assertSame(['REC-DEG', 'REC-INC'], $vises, 'Avec son ciblage.');
    }
}
