<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Services\DashboardDataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA FENÊTRE DU PIPELINE D'ÉCHÉANCE, éprouvée en base.
 *
 * Ce chemin DQL alimente à la fois le widget « renouvellements » du tableau de bord et la
 * vigie de l'assistant. Il avait divergé de la rubrique Avenants sur quatre points, chacun
 * silencieux, et leur cumul faisait annoncer à Ket « plus aucune police échue » quand
 * l'écran en affichait cinq. Un test par point, pour qu'aucun ne revienne :
 *
 *  1. borne basse à « now » → les polices ÉCHUES ne pouvaient pas entrer ;
 *  2. borne horodatée → une police expirant AUJOURD'HUI était ratée dès 00h01 ;
 *  3. filtre renewalCondition surnuméraire, absent de la rubrique ;
 *  4. INNER JOIN sur un assureur NULLABLE.
 */
class DashboardDataProviderRenouvellementsTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ddp-renouv@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit DDP Renouvellements SARL';

    private Entreprise $entreprise;
    private Invite $invite;

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['avenant', 'cotation', 'piste', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM]
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    private function amorcerCabinet(): void
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit DDP')->setVerified(true);
        $owner->setPassword('irrelevant');
        $em->persist($owner);

        $this->entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-DDP')->setAdresse('1 rue du Pipeline')
            ->setTelephone('+243000000012')->setRccm('RCCM-DDP')->setIdnat('IDNAT-DDP')->setNumimpot('IMP-DDP')
            ->setUtilisateur($owner);
        $em->persist($this->entreprise);

        $this->invite = (new Invite())->setNom('Gestionnaire DDP')->setUtilisateur($owner)
            ->setEntreprise($this->entreprise)->setProprietaire(true);
        $em->persist($this->invite);
    }

    /**
     * Une police sans piste dérivée, dont on maîtrise l'échéance et la condition de
     * renouvellement. Aucun assureur n'est posé sur la cotation : c'est le cas nominal du
     * point 4 (Cotation::assureur est nullable).
     */
    private function police(string $ref, string $echeance, ?int $renewalCondition = null): Avenant
    {
        $em = $this->em();
        $fin = new \DateTimeImmutable($echeance);

        $client = (new Client())->setNom('Client ' . $ref)->setExonere(false);
        $client->setEntreprise($this->entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste ' . $ref)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque')->setExercice(2026)->setClient($client);
        $piste->setEntreprise($this->entreprise)->setInvite($this->invite);
        if ($renewalCondition !== null) {
            $piste->setRenewalCondition($renewalCondition);
        }
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $ref)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($this->entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())->setCotation($cotation)->setReferencePolice($ref)->setNumero('0')
            ->setDescription('Avenant ' . $ref)
            ->setStartingAt($fin->modify('-365 days'))->setEndingAt($fin);
        $avenant->setEntreprise($this->entreprise)->setInvite($this->invite);
        $em->persist($avenant);

        return $avenant;
    }

    /** @return array<int, string> références des polices retournées par la fenêtre */
    private function fenetre(int $jours = 30): array
    {
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($this->entreprise->getId());
        $avenants = static::getContainer()->get(DashboardDataProvider::class)
            ->getAllRenouvellements($entreprise, $jours);

        return array_map(static fn (Avenant $a) => $a->getReferencePolice(), $avenants);
    }

    /** POINT 1 — les polices échues sont DANS la fenêtre, et en tête (tri par urgence). */
    public function testLesPolicesEchuesSontDansLaFenetreEtEnTete(): void
    {
        $this->amorcerCabinet();
        $this->police('POL-J10', '+10 days');
        $this->police('POL-ECHUE-2J', '-2 days');
        $this->police('POL-ECHUE-93J', '-93 days');
        $this->em()->flush();
        $this->em()->clear();

        $this->assertSame(['POL-ECHUE-93J', 'POL-ECHUE-2J', 'POL-J10'], $this->fenetre());
    }

    /** POINT 2 — la borne est à MINUIT : une police expirant aujourd'hui reste visible. */
    public function testUnePoliceExpirantAujourdhuiEstRetournee(): void
    {
        $this->amorcerCabinet();
        // Échéance en milieu de journée : avec une borne basse à « now », cette police
        // disparaissait de la vigie chaque après-midi.
        $this->police('POL-AUJOURDHUI', 'today 09:00');
        $this->em()->flush();
        $this->em()->clear();

        $this->assertSame(['POL-AUJOURDHUI'], $this->fenetre());
    }

    /**
     * POINT 3 — aucune condition de renouvellement n'exclut une police de la fenêtre. Une
     * police non renouvelable mais échue appelle quand même une DÉCISION : la retrancher
     * ici faisait annoncer par l'assistant un nombre inférieur à celui du chip.
     */
    public function testLaConditionDeRenouvellementNExclutAucunePolice(): void
    {
        $this->amorcerCabinet();
        $this->police('POL-RC-0', '-5 days', 0);
        $this->police('POL-RC-1', '-4 days', 1);
        $this->police('POL-RC-2', '-3 days', 2);
        $this->police('POL-RC-3', '-2 days', 3);
        $this->em()->flush();
        $this->em()->clear();

        $this->assertSame(['POL-RC-0', 'POL-RC-1', 'POL-RC-2', 'POL-RC-3'], $this->fenetre());
    }

    /** POINT 4 — une cotation SANS assureur ne doit pas faire disparaître sa police. */
    public function testUnePoliceSansAssureurResteVisible(): void
    {
        $this->amorcerCabinet();
        $this->police('POL-SANS-ASSUREUR', '-1 day');
        $this->em()->flush();
        $this->em()->clear();

        $this->assertSame(['POL-SANS-ASSUREUR'], $this->fenetre());
    }

    /** La borne haute reste EXCLUSIVE au 31ᵉ jour : « sous 30 jours » n'en déborde pas. */
    public function testLaBorneHauteExclutLeTrenteEtUniemeJour(): void
    {
        $this->amorcerCabinet();
        $this->police('POL-J30', '+30 days');
        $this->police('POL-J31', '+31 days');
        $this->em()->flush();
        $this->em()->clear();

        $this->assertSame(['POL-J30'], $this->fenetre(30));
        $this->assertSame(['POL-J30', 'POL-J31'], $this->fenetre(31));
    }
}
