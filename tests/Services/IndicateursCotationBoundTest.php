<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RÈGLE MÉTIER : une cotation NON validée (proposition sans avenant) n'est qu'un projet.
 * Ses primes / commissions / rétros / réserve sont de simples PROJECTIONS et ne doivent
 * JAMAIS être agrégées — ni dans les listes du workspace (indicateurs Client/Portefeuille/…),
 * ni chez Ket (indicateur_calcule s'appuie sur le même getIndicateursGlobaux). Seules les
 * cotations BOUND (police concrétisée) comptent.
 */
class IndicateursCotationBoundTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-bound-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Bound SARL';

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

    private function helper(): IndicatorCalculationHelper
    {
        return static::getContainer()->get(IndicatorCalculationHelper::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['avenant', 'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :email", ['email' => self::OWNER_EMAIL]);
    }

    /** Cotation avec prime (1000) + une commission assureur (200), validée ou non selon $valide. */
    private function makeCotation(Entreprise $e, Invite $invite, Client $client, string $nom, bool $valide): Cotation
    {
        $em = $this->em();

        $piste = (new Piste())->setNom('Piste ' . $nom)->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque test bound')->setExercice(2026)
            ->setClient($client)->setEntreprise($e)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($e);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime ' . $nom)->setMontantFlatExceptionel(1000.0);
        $chargement->setEntreprise($e);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission ' . $nom)->setMontantflat(200.0)
            ->setShared(false)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($e);
        $em->persist($typeRevenu);
        $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $nom)->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($e);
        $em->persist($revenu);

        if ($valide) {
            $avenant = (new Avenant())->setReferencePolice('POL-' . $nom)->setNumero('0')
                ->setDescription('Avenant validé')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($e);
            $avenant->setInvite($invite);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
        }

        return $cotation;
    }

    public function testUnProjetNeCompteJamais(): void
    {
        $em = $this->em();

        $owner = new Utilisateur();
        $owner->setEmail(self::OWNER_EMAIL)->setNom('Bound Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner);
        $invite->setEntreprise($entreprise);
        $em->persist($invite);

        $clientBound  = (new Client())->setNom('Client Validé')->setExonere(false);
        $clientBound->setEntreprise($entreprise);
        $em->persist($clientBound);

        $clientProjet = (new Client())->setNom('Client Projet')->setExonere(false);
        $clientProjet->setEntreprise($entreprise);
        $em->persist($clientProjet);

        $this->makeCotation($entreprise, $invite, $clientBound, 'Validée', true);
        $this->makeCotation($entreprise, $invite, $clientProjet, 'Projet', false);

        $em->flush();
        $entrepriseId = $entreprise->getId();
        $boundId = $clientBound->getId();
        $projetId = $clientProjet->getId();
        $em->clear();

        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
        $helper = $this->helper();

        // Client VALIDÉ (police concrétisée) : prime et commission comptent.
        $statsBound = $helper->getIndicateursGlobaux($entreprise, false, [
            'clientCible' => $em->getRepository(Client::class)->find($boundId),
        ]);
        $this->assertEqualsWithDelta(1000.0, $statsBound['prime_totale'], 0.01, 'La prime d\'une police validée compte.');
        $this->assertGreaterThan(0.0, $statsBound['commission_totale'], 'La commission d\'une police validée compte.');

        // Client PROJET (que des propositions, aucun avenant) : rien ne compte.
        $statsProjet = $helper->getIndicateursGlobaux($entreprise, false, [
            'clientCible' => $em->getRepository(Client::class)->find($projetId),
        ]);
        $this->assertSame(0.0, $statsProjet['prime_totale'], 'Une proposition non validée ne compte AUCUNE prime.');
        $this->assertSame(0.0, $statsProjet['commission_totale'], 'Une proposition non validée ne compte AUCUNE commission.');
        $this->assertSame(0.0, $statsProjet['retro_commission_partenaire'], 'Aucune rétro sur une proposition.');
        $this->assertSame(0.0, $statsProjet['reserve'], 'Aucune réserve sur une proposition.');

        // Au niveau ENTREPRISE, seul le client validé alimente les totaux.
        $statsEntreprise = $helper->getIndicateursGlobaux($entreprise, false, []);
        $this->assertEqualsWithDelta(1000.0, $statsEntreprise['prime_totale'], 0.01, 'Seule la police validée alimente le total entreprise.');
    }
}
