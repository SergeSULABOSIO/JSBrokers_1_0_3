<?php

namespace App\Tests\Ai;

use App\Ai\FicheNormaliseur;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FicheNormaliseur::ficheEnrichie() = fiche stockée + la TOTALITÉ des indicateurs
 * calculés, sans troncature. Chemin des objets attachés au chat : sans les
 * indicateurs calculés, Ket ne voit pas qu'une cotation est souscrite (RÈGLE
 * isBound) et la croit « projet ». Et sans leur COMPLÉTUDE, elle comble les trous
 * d'une fiche partielle par des négations plausibles (bug KIN AVIA).
 */
class FicheNormaliseurTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-fichenorm-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit FicheNorm SARL';

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

    private function normaliseur(): FicheNormaliseur
    {
        return static::getContainer()->get(FicheNormaliseur::class);
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

    /** @return array{cotation: Cotation} */
    private function seedCotation(bool $valide): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('FicheNorm Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $client = (new Client())->setNom('Client FicheNorm')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste FicheNorm')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque test')->setExercice(2026)
            ->setClient($client)->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation FicheNorm')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(1000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(200.0)
            ->setShared(false)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);
        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        if ($valide) {
            $avenant = (new Avenant())->setReferencePolice('POL-FICHENORM')->setNumero('0')
                ->setDescription('Avenant validé')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($invite);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
        }

        $em->flush();

        return ['cotation' => $cotation];
    }

    public function testFicheEnrichieEstUnSupersetDeLaFicheStockee(): void
    {
        ['cotation' => $cotation] = $this->seedCotation(true);
        $normaliseur = $this->normaliseur();

        $fiche = $normaliseur->fiche($cotation);
        $contexte = $normaliseur->ficheEnrichie($cotation);

        foreach ($fiche as $cle => $valeur) {
            $this->assertArrayHasKey($cle, $contexte, "La fiche enrichie conserve l'attribut stocké « $cle ».");
            $this->assertSame($valeur, $contexte[$cle], "Un indicateur calculé ne masque jamais l'attribut stocké « $cle ».");
        }
    }

    /**
     * AUCUNE troncature : la fiche porte les indicateurs TARDIFS de la stratégie
     * (« reserve » est le dernier de la liste) et ne pose plus de marqueur
     * d'approfondissement. Une fiche complète est ce qui retire à Ket l'excuse de
     * deviner ce qu'elle ne voit pas.
     */
    public function testCotationSouscriteEtFicheComplete(): void
    {
        ['cotation' => $cotation] = $this->seedCotation(true);
        $contexte = $this->normaliseur()->ficheEnrichie($cotation);

        $this->assertSame('Souscrite', $contexte['statutSouscription']);
        $this->assertArrayNotHasKey('analyseApprofondie', $contexte, 'Plus de marqueur d’approfondissement : la fiche est complète.');
        $this->assertArrayHasKey('reserve', $contexte, 'Les indicateurs tardifs de la stratégie sont embarqués.');
    }

    public function testCotationSansAvenantEnAttente(): void
    {
        ['cotation' => $cotation] = $this->seedCotation(false);
        $contexte = $this->normaliseur()->ficheEnrichie($cotation);

        $this->assertSame('En attente', $contexte['statutSouscription']);
    }
}
