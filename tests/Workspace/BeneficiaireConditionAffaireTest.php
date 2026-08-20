<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Form\ConditionPartageType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * LE BÉNÉFICIAIRE D'UNE CONDITION PROPRE À UNE AFFAIRE SE CHOISIT, IL NE SE DEVINE PAS.
 *
 * Le formulaire d'une condition n'exposait jamais le champ `partenaire` : il n'est renseigné
 * que lorsqu'on crée la condition depuis la fiche d'un intermédiaire. Ouvert depuis une
 * affaire, il ne proposait donc que `agent`, et son aide affirmait « laisser vide pour un
 * partenaire externe » — ce qui était FAUX : vide viole l'invariant « exactement un
 * bénéficiaire » et l'écriture est refusée en 422.
 *
 * Autrement dit, une condition pour l'intermédiaire était impossible à créer depuis une
 * affaire, alors que c'est précisément ce que le bloc promettait de faire. La question est
 * désormais posée franchement, et la réponse APPLIQUÉE — l'invariant est satisfait par
 * construction plutôt que découvert par un refus que rien n'expliquait.
 */
class BeneficiaireConditionAffaireTest extends WebTestCase
{
    private const ENT = 'PHPUnit-Beneficiaire';
    private const OWNER = 'phpunit-beneficiaire@test.local';

    private KernelBrowser $client;
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        foreach (['condition_partage', 'piste', 'client', 'partenaire', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{piste:Piste, partenaire:Partenaire, agent:Invite, invite:Invite, entreprise:Entreprise} */
    private function semer(bool $avecIntermediaire): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+243000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $this->em->persist($invite);
        $owner->setConnectedTo($entreprise);

        $agent = (new Invite())->setNom('Alice Agent')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $this->em->persist($agent);

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(5.0);
        $partenaire->setEntreprise($entreprise);
        $this->em->persist($partenaire);

        $client = (new Client())->setNom('Client Bénéf')->setExonere(false);
        $client->setEntreprise($entreprise);
        $this->em->persist($client);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Bénéf')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $this->em->persist($risque);

        $piste = (new Piste())->setNom('Piste Bénéf')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        if ($avecIntermediaire) {
            $piste->setPartenaire($partenaire);
        }
        $this->em->persist($piste);

        $this->em->flush();
        $this->client->loginUser($owner);

        return compact('piste', 'partenaire', 'agent', 'invite', 'entreprise');
    }

    /** Le formulaire d'une condition créée DEPUIS une affaire. */
    private function formulaireDepuisLaPiste(Piste $piste): string
    {
        $this->client->request(
            'GET',
            '/admin/conditionpartage/api/get-form?parent_id=' . $piste->getId() . '&parent_field_name=piste',
        );
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testLaQuestionEstPoseeQuandLAffaireAUnIntermediaire(): void
    {
        $s = $this->semer(true);
        $html = $this->formulaireDepuisLaPiste($s['piste']);

        self::assertStringContainsString('Cette part revient à', $html);
        // L'intermédiaire est NOMMÉ : « l'intermédiaire de cette affaire » seul obligerait
        // à aller vérifier de qui il s'agit.
        self::assertStringContainsString('SUNU Courtage', $html);
        self::assertStringContainsString('Un agent interne', $html);
    }

    public function testSansIntermediaireLOptionNEstPasProposee(): void
    {
        $s = $this->semer(false);
        $html = $this->formulaireDepuisLaPiste($s['piste']);

        // Proposer un choix qui ne peut pas être honoré, c'est promettre un refus. On ne
        // garde que la réponse possible.
        self::assertStringNotContainsString('SUNU Courtage', $html);
        self::assertStringContainsString('Un agent interne', $html);
    }

    public function testLeChoixIntermediairePoseLeBonBeneficiaire(): void
    {
        $s = $this->semer(true);

        $this->client->request('POST', '/admin/conditionpartage/api/submit', [
            'idEntreprise' => $s['entreprise']->getId(),
            'idInvite' => $s['invite']->getId(),
            'piste' => $s['piste']->getId(),
            'nom' => 'Taux négocié sur cette affaire',
            'taux' => '12',
            'seuil' => '0',
            'formule' => (string) ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
            'critereRisque' => (string) ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
            'beneficiaireType' => ConditionPartageType::BENEFICIAIRE_INTERMEDIAIRE,
        ]);

        // C'EST CE QUI ÉTAIT IMPOSSIBLE : la condition passe, sans 422.
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $condition = $this->em->getRepository(ConditionPartage::class)->findOneBy(['nom' => 'Taux négocié sur cette affaire']);
        self::assertNotNull($condition);
        self::assertSame($s['partenaire']->getId(), $condition->getPartenaire()?->getId(), 'Le bénéficiaire est l\'intermédiaire de l\'affaire.');
        self::assertNull($condition->getAgent(), 'Un seul bénéficiaire : jamais les deux.');
        self::assertTrue($condition->estValide(), 'L\'invariant est satisfait par construction.');
    }

    public function testLeChoixAgentPoseLAgentEtEffaceLIntermediaire(): void
    {
        $s = $this->semer(true);

        $this->client->request('POST', '/admin/conditionpartage/api/submit', [
            'idEntreprise' => $s['entreprise']->getId(),
            'idInvite' => $s['invite']->getId(),
            'piste' => $s['piste']->getId(),
            'nom' => 'Part d Alice sur cette affaire',
            'taux' => '15',
            'seuil' => '0',
            'formule' => (string) ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
            'critereRisque' => (string) ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
            'beneficiaireType' => ConditionPartageType::BENEFICIAIRE_AGENT,
            'agent' => $s['agent']->getId(),
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $condition = $this->em->getRepository(ConditionPartage::class)->findOneBy(['nom' => 'Part d Alice sur cette affaire']);
        self::assertNotNull($condition);
        self::assertSame($s['agent']->getId(), $condition->getAgent()?->getId());
        self::assertNull($condition->getPartenaire(), 'Le choix EFFACE l\'autre bénéficiaire, il ne le laisse pas traîner.');
    }

    public function testDepuisLaFicheDUnIntermediaireLaQuestionNeSePosePas(): void
    {
        $s = $this->semer(true);

        $this->client->request(
            'GET',
            '/admin/conditionpartage/api/get-form?parent_id=' . $s['partenaire']->getId() . '&parent_field_name=partenaire',
        );
        self::assertResponseIsSuccessful();

        // Le parent a déjà tranché : poser la question serait proposer de le contredire.
        self::assertStringNotContainsString('Cette part revient à', (string) $this->client->getResponse()->getContent());
    }

    public function testUnChoixIntermediaireSansIntermediaireResteRefuse(): void
    {
        $s = $this->semer(false);

        $this->client->request('POST', '/admin/conditionpartage/api/submit', [
            'idEntreprise' => $s['entreprise']->getId(),
            'idInvite' => $s['invite']->getId(),
            'piste' => $s['piste']->getId(),
            'nom' => 'Sans bénéficiaire possible',
            'taux' => '10',
            'seuil' => '0',
            'formule' => (string) ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL,
            'critereRisque' => (string) ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
            'beneficiaireType' => ConditionPartageType::BENEFICIAIRE_INTERMEDIAIRE,
        ]);

        // L'écran ne propose pas ce choix ; s'il arrive quand même — API, requête forgée —
        // l'invariant tient toujours. Le garde-fou n'est pas seulement dans l'affichage.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangerDIntermediaireReporteLesConditionsPropres(): void
    {
        $piste = new Piste();
        $ancien = (new Partenaire())->setNom('Ancien')->setPart(5.0);
        $nouveau = (new Partenaire())->setNom('Nouveau')->setPart(7.0);

        $piste->setPartenaire($ancien);

        $propre = (new ConditionPartage())->setNom('Taux négocié')->setTaux(12.0)->setPartenaire($ancien);
        $piste->addConditionsPartageExceptionnelle($propre);

        $agent = (new Invite())->setNom('Alice');
        $dAgent = (new ConditionPartage())->setNom('Part Alice')->setTaux(15.0)->setAgent($agent);
        $piste->addConditionsPartageExceptionnelle($dAgent);

        $piste->setPartenaire($nouveau);

        // Le calcul prendra cette condition pour le NOUVEL intermédiaire : l'écran doit
        // nommer le même, sans quoi les deux se contrediraient.
        self::assertSame($nouveau, $propre->getPartenaire());
        // Une condition d'agent ne regarde pas l'intermédiaire : y toucher poserait deux
        // bénéficiaires.
        self::assertNull($dAgent->getPartenaire());
        self::assertSame($agent, $dAgent->getAgent());
    }

    public function testRetirerLIntermediaireNEffaceAucuneCondition(): void
    {
        $piste = new Piste();
        $intermediaire = (new Partenaire())->setNom('Sortant')->setPart(5.0);
        $piste->setPartenaire($intermediaire);

        $propre = (new ConditionPartage())->setNom('Taux négocié')->setTaux(12.0)->setPartenaire($intermediaire);
        $piste->addConditionsPartageExceptionnelle($propre);

        $piste->setPartenaire(null);

        // Effacer le bénéficiaire romprait l'invariant ET supprimerait une saisie sans le
        // dire : la condition garde celui qu'elle nomme, à l'utilisateur de la retirer.
        self::assertSame($intermediaire, $propre->getPartenaire());
        self::assertCount(1, $piste->getConditionsPartageExceptionnelles());
    }

    public function testLAgentUniqueDeLAffaireEstDejaRempli(): void
    {
        $s = $this->semer(false);

        // L'affaire ne rémunère qu'un agent : c'est la seule réponse possible.
        $condition = (new ConditionPartage())->setNom('Part Alice')->setTaux(15.0)->setSeuil(0.0)
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($s['agent']);
        $condition->setEntreprise($s['entreprise']);
        $this->em->persist($condition);
        $s['piste']->addConditionsPartageAgent($condition);
        $this->em->flush();

        $html = $this->formulaireDepuisLaPiste($s['piste']);

        // Le sélecteur arrive DÉJÀ rempli : demander lequel ferait chercher une
        // information que l'écran possède déjà.
        self::assertMatchesRegularExpression(
            '/<option value="' . $s['agent']->getId() . '"[^>]*selected/',
            $html,
            'L\'agent unique de l\'affaire est proposé d\'office.',
        );
    }

    public function testAvecPlusieursAgentsAucuneReponseNEstDevinee(): void
    {
        $s = $this->semer(false);

        $second = (new Invite())->setNom('Bob Agent')->setProprietaire(false);
        $second->setEntreprise($s['entreprise']);
        $this->em->persist($second);

        foreach ([$s['agent'], $second] as $i => $agent) {
            $condition = (new ConditionPartage())->setNom('Part ' . $i)->setTaux(10.0)->setSeuil(0.0)
                ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
                ->setAgent($agent);
            $condition->setEntreprise($s['entreprise']);
            $this->em->persist($condition);
            $s['piste']->addConditionsPartageAgent($condition);
        }
        $this->em->flush();

        $html = $this->formulaireDepuisLaPiste($s['piste']);

        // Deux bénéficiaires possibles : en choisir un pour l'utilisateur, c'est deviner.
        self::assertDoesNotMatchRegularExpression('/<option value="\d+"[^>]*selected/', $html);
    }
}