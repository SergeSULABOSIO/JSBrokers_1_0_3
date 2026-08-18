<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\RetroAgent\EligibiliteRetroAgent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RÈGLE N-1 INFORME, ELLE NE BLOQUE PAS.
 *
 * Le cabinet récompense deux efforts : apporter une NOUVELLE AFFAIRE, ou SATURER un
 * client existant sur une LIGNE que la société ne couvrait pas l'exercice précédent. Un
 * renouvellement n'est ni l'un ni l'autre.
 *
 * Ce que ce test verrouille, au-delà des trois verdicts :
 *
 *  - LE PÉRIMÈTRE EST CELUI DE LA SOCIÉTÉ. « Portefeuille GLOBAL » : une ligne qu'un
 *    AUTRE collaborateur couvrait déjà en N-1 n'est pas neuve. Interroger les seules
 *    affaires de l'agent rendrait tout apport « nouveau » et viderait la règle.
 *  - UNE PROPOSITION SANS SUITE NE COMPTE PAS. Un devis perdu n'a jamais mis le client
 *    au portefeuille : le compter refuserait une rétrocommission au motif d'un échec.
 *  - AUCUN VERDICT N'EMPÊCHE D'ENREGISTRER. Reprise de portefeuille, affaire réattribuée,
 *    client revenu après deux ans : le gestionnaire garde la décision.
 */
class EligibiliteRetroAgentTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-eligibilite-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Eligibilite SARL';
    private const EXERCICE = 2026;

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

    /**
     * Le service est instancié DIRECTEMENT, et non tiré du conteneur : il n'a qu'une
     * dépendance (le repository) et le conteneur de test l'inline tant qu'aucun autre
     * service ne le référence. Le construire ici évite d'ajouter un alias public au seul
     * bénéfice du test — et garde la mémoïsation interne isolée d'un cas à l'autre.
     */
    private function service(): EligibiliteRetroAgent
    {
        return new EligibiliteRetroAgent(
            static::getContainer()->get(\App\Repository\PisteRepository::class),
        );
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach (['avenant', 'cotation', 'piste', 'client', 'risque', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    public function testUnClientAbsentEnN1EstUneNouvelleAffaire(): void
    {
        $ids = $this->semer(passe: []);
        $piste = $this->em()->getRepository(Piste::class)->find($ids['pisteId']);

        self::assertSame(EligibiliteRetroAgent::NOUVELLE_AFFAIRE, $this->service()->verdict($piste));
        self::assertTrue($this->service()->estEligible($piste));
        self::assertNull($this->service()->avertissement($piste));
    }

    public function testUnClientPresentEnN1SurUnAutreRisqueEstUneNouvelleLigneSaturee(): void
    {
        // Le client était couvert en N-1, mais sur un AUTRE risque : la ligne est neuve.
        $ids = $this->semer(passe: ['autre']);
        $piste = $this->em()->getRepository(Piste::class)->find($ids['pisteId']);

        self::assertSame(EligibiliteRetroAgent::NOUVELLE_LIGNE_SATUREE, $this->service()->verdict($piste));
        self::assertTrue($this->service()->estEligible($piste));
    }

    public function testUneLigneDejaCouverteEnN1EstHorsRegleMaisResteEnregistrable(): void
    {
        $ids = $this->semer(passe: ['meme']);
        $piste = $this->em()->getRepository(Piste::class)->find($ids['pisteId']);

        self::assertSame(EligibiliteRetroAgent::LIGNE_EXISTANTE_N1, $this->service()->verdict($piste));
        self::assertFalse($this->service()->estEligible($piste));

        $avertissement = $this->service()->avertissement($piste);
        self::assertNotNull($avertissement, 'Le cas hors règle doit être ANNONCÉ.');
        self::assertStringContainsString((string) (self::EXERCICE - 1), $avertissement);
        // Et c'est un avertissement, pas un refus : rien dans ce service ne lève d'exception
        // ni ne bloque une écriture — le gestionnaire décide.
    }

    public function testUneLigneCouverteParUnAutreCollaborateurNEstPasNeuve(): void
    {
        // Le portefeuille interrogé est celui de la SOCIÉTÉ : l'affaire de N-1 appartient à
        // un second gestionnaire, elle compte quand même.
        $ids = $this->semer(passe: ['meme'], passeAutreGestionnaire: true);
        $piste = $this->em()->getRepository(Piste::class)->find($ids['pisteId']);

        self::assertSame(EligibiliteRetroAgent::LIGNE_EXISTANTE_N1, $this->service()->verdict($piste));
    }

    public function testUnePropositionSansSuiteNaJamaisMisLeClientAuPortefeuille(): void
    {
        // Affaire de N-1 sur le MÊME risque, mais restée au stade de proposition (aucun
        // avenant) : elle n'a jamais couvert le client, la ligne reste donc neuve.
        $ids = $this->semer(passe: ['meme'], passeSouscrite: false);
        $piste = $this->em()->getRepository(Piste::class)->find($ids['pisteId']);

        self::assertSame(
            EligibiliteRetroAgent::NOUVELLE_AFFAIRE,
            $this->service()->verdict($piste),
            'Un devis perdu ne doit pas priver l\'agent de sa rétrocommission.',
        );
    }

    public function testSansRisqueOuSansExerciceLeVerdictResteIndetermine(): void
    {
        // Rien à comparer : on ne conclut pas, et on n'accuse donc pas.
        $piste = (new Piste())->setNom('Piste nue')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice(self::EXERCICE);

        self::assertSame(EligibiliteRetroAgent::INDETERMINE, $this->service()->verdict($piste));
        self::assertTrue($this->service()->estEligible($piste), 'L\'indéterminé ne se traite pas comme un refus.');
    }

    /**
     * Une affaire de l'exercice courant, et éventuellement une affaire passée (exercice
     * N-1) sur le même risque (« meme ») ou sur un autre (« autre »).
     *
     * @param string[] $passe
     *
     * @return array{pisteId:int}
     */
    private function semer(array $passe, bool $passeSouscrite = true, bool $passeAutreGestionnaire = false): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Eligibilite Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $autre = (new Invite())->setNom('Autre gestionnaire')->setProprietaire(false);
        $autre->setEntreprise($entreprise);
        $em->persist($autre);

        $risques = [];
        foreach (['meme' => 'RM', 'autre' => 'RA'] as $cle => $code) {
            $risque = (new Risque())->setCode($code)->setNomComplet('Risque ' . $cle)->setDescription('Risque ' . $cle)
                ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
            $risque->setEntreprise($entreprise);
            $em->persist($risque);
            $risques[$cle] = $risque;
        }

        $client = (new Client())->setNom('Client Éligibilité')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        // Les affaires de l'exercice PRÉCÉDENT.
        foreach ($passe as $index => $cleRisque) {
            $pistePassee = (new Piste())->setNom('Piste N-1 ' . $index)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque passé')->setExercice(self::EXERCICE - 1)
                ->setClient($client)->setRisque($risques[$cleRisque]);
            $pistePassee->setEntreprise($entreprise)->setInvite($passeAutreGestionnaire ? $autre : $gestionnaire);
            $em->persist($pistePassee);

            $cotationPassee = (new Cotation())->setNom('Cotation N-1 ' . $index)->setDuree(365);
            $cotationPassee->setPiste($pistePassee);
            $cotationPassee->setEntreprise($entreprise);
            $em->persist($cotationPassee);

            if ($passeSouscrite) {
                $avenant = (new Avenant())->setReferencePolice('POL-N1-' . $index)->setNumero('0')
                    ->setDescription('Police N-1')
                    ->setStartingAt(new \DateTimeImmutable((self::EXERCICE - 1) . '-02-01'))
                    ->setEndingAt(new \DateTimeImmutable((self::EXERCICE - 1) . '-12-31'));
                $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
                $cotationPassee->addAvenant($avenant);
                $em->persist($avenant);
            }
        }

        // L'affaire de l'exercice COURANT, sur le risque « meme ».
        $piste = (new Piste())->setNom('Piste courante')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque courant')->setExercice(self::EXERCICE)
            ->setClient($client)->setRisque($risques['meme']);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        $em->flush();
        $ids = ['pisteId' => (int) $piste->getId()];
        $em->clear();

        return $ids;
    }
}
