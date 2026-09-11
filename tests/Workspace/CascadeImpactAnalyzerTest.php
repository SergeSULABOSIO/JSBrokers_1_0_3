<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Workspace\CascadeImpactAnalyzer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'analyse d'impact annonce la portée d'une suppression AVANT de la demander.
 *
 * ⚠ CES TESTS PORTENT SUR DES DONNÉES RÉELLEMENT ENREGISTRÉES, et ce changement n'est pas
 * cosmétique. Ils s'appuyaient auparavant sur un graphe d'objets assemblé en mémoire par
 * le test lui-même : ils prouvaient que l'analyseur savait parcourir ce que le test lui
 * tendait, jamais qu'il disait vrai de la base. C'est exactement par là que le défaut est
 * passé — l'annonce était juste, la suppression emportait une police.
 *
 * L'analyse dérive désormais du PLAN réellement exécuté : c'est le même calcul qui
 * annonce et qui applique.
 */
class CascadeImpactAnalyzerTest extends KernelTestCase
{
    private const ENTREPRISE_NOM = 'PHPUnit CascadeImpact SARL';
    private const OWNER_EMAIL = 'phpunit-cia-owner@test.local';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function analyzer(): CascadeImpactAnalyzer
    {
        return self::getContainer()->get(CascadeImpactAnalyzer::class);
    }

    private function nettoyer(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;

        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :nom', ['nom' => $nom]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :nom', ['nom' => $nom]);

        foreach (['tranche', 'avenant', 'cotation', 'piste', 'contact', 'risque', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                sprintf('DELETE t FROM %s t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom', $table),
                ['nom' => $nom],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => $nom]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
    }

    /** @return array{entreprise: Entreprise, invite: Invite, client: Client, risque: Risque} */
    private function cabinet(): array
    {
        $em = $this->em();

        $utilisateur = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit CIA');
        $utilisateur->setVerified(true);
        $utilisateur->setPassword('x');
        $em->persist($utilisateur);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue du Test')
            ->setTelephone('+243000000000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($utilisateur);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur');
        $invite->setUtilisateur($utilisateur);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
        $em->persist($invite);

        $client = (new Client())->setNom('PHPUNIT-CIA-CLIENT')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $risque = (new Risque())->setNomComplet('Risque CIA')->setCode('CIA-RQ')
            ->setDescription('Risque de test')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $risque->setInvite($invite);
        $em->persist($risque);

        $em->flush();

        return ['entreprise' => $entreprise, 'invite' => $invite, 'client' => $client, 'risque' => $risque];
    }

    private function piste(array $cabinet, string $nom, int $type = Piste::AVENANT_SOUSCRIPTION): Piste
    {
        $piste = (new Piste())->setNom($nom)->setClient($cabinet['client'])->setRisque($cabinet['risque'])
            ->setTypeAvenant($type)->setDescriptionDuRisque('Description ' . $nom)->setExercice(2026);
        $piste->setEntreprise($cabinet['entreprise']);
        $piste->setInvite($cabinet['invite']);
        $this->em()->persist($piste);

        return $piste;
    }

    public function testDetecteLesEnfantsSupprimesEnCascade(): void
    {
        $cabinet = $this->cabinet();
        $client = $cabinet['client'];
        foreach (['Contact A', 'Contact B'] as $nom) {
            $contact = (new Contact())->setNom($nom)->setTelephone('+243000000001');
            $contact->setEntreprise($cabinet['entreprise']);
            $contact->setInvite($cabinet['invite']);
            $client->addContact($contact);
            $this->em()->persist($contact);
        }
        $this->em()->flush();

        $impact = $this->analyzer()->analyserSuppression($client);

        $contacts = array_filter($impact->enfants, static fn ($e) => $e['entite'] === 'Contact');
        $this->assertCount(1, $contacts);
        $this->assertSame(2, array_values($contacts)[0]['count']);
        $this->assertNotEmpty($impact->descriptions());
        $this->assertStringContainsString('2 Contacts', implode(' ', $impact->descriptions()));
    }

    public function testAucunBlocageSurEntiteNonPersistee(): void
    {
        // Sans identifiant, il n'y a rien en base à effacer : le plan est vide, pas bloqué.
        $impact = $this->analyzer()->analyserSuppression((new Client())->setNom('Nouveau'));

        $this->assertFalse($impact->estBloque());
        $this->assertSame([], $impact->enfants);
    }

    /**
     * LA PORTÉE ANNONCÉE DOIT ÊTRE LA PORTÉE RÉELLE. S'arrêter à une profondeur mentait
     * par omission : supprimer une opportunité annonçait « 1 Cotation liée » alors que
     * disparaissaient aussi son échéancier et les paiements déjà déclarés. On ne peut pas
     * demander de valider ce qu'on cache.
     */
    public function testLaCascadeEstSuivieEnProfondeur(): void
    {
        $cabinet = $this->cabinet();
        $piste = $this->piste($cabinet, 'Opportunité profonde');

        $cotation = (new Cotation())->setNom('Proposition')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($cabinet['entreprise']);
        $cotation->setInvite($cabinet['invite']);
        $this->em()->persist($cotation);

        $tranche = (new Tranche())->setNom('1re échéance');
        $tranche->setPayableAt(new DateTimeImmutable('2026-03-01'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($cabinet['entreprise']);
        $tranche->setInvite($cabinet['invite']);
        $this->em()->persist($tranche);
        $this->em()->flush();

        $impact = $this->analyzer()->analyserSuppression($piste);
        $parEntite = array_column($impact->enfants, 'count', 'entite');

        $this->assertSame(1, $parEntite['Cotation'] ?? 0, 'Profondeur 1.');
        $this->assertSame(1, $parEntite['Tranche'] ?? 0, 'Profondeur 2 — invisible auparavant.');
    }

    /**
     * LE LIEN QUI NE DOIT JAMAIS ÊTRE REMONTÉ. `Piste::avenantDeBase` est en
     * cascade:['remove'], mais le moteur le COUPE avant de supprimer : la police de base
     * survit. L'annoncer comme détruite serait un mensonge — aussi grave que taire une
     * vraie destruction.
     */
    public function testLaPoliceDeBaseNEstPasAnnonceeCommeDetruite(): void
    {
        $cabinet = $this->cabinet();
        $base = $this->piste($cabinet, 'Affaire de base');
        $cotation = (new Cotation())->setNom('Proposition de base')->setDuree(365);
        $cotation->setPiste($base);
        $cotation->setEntreprise($cabinet['entreprise']);
        $cotation->setInvite($cabinet['invite']);
        $this->em()->persist($cotation);

        $police = (new Avenant())->setReferencePolice('POL-CIA-1')->setDescription('Police')
            ->setStartingAt(new DateTimeImmutable('2026-01-01'))
            ->setEndingAt(new DateTimeImmutable('2026-12-31'))
            ->setCotation($cotation);
        $police->setEntreprise($cabinet['entreprise']);
        $police->setInvite($cabinet['invite']);
        $this->em()->persist($police);

        $derivee = $this->piste($cabinet, 'Renouvellement', Piste::AVENANT_RENOUVELLEMENT);
        $derivee->setAvenantDeBase($police);
        $police->setPisteDeRenouvellement($derivee);
        $this->em()->flush();

        $impact = $this->analyzer()->analyserSuppression($derivee);
        $parEntite = array_column($impact->enfants, 'count', 'entite');

        $this->assertArrayNotHasKey('Avenant', $parEntite, 'La police de base est protégée, pas détruite.');
        $this->assertArrayNotHasKey('Cotation', $parEntite, 'Ni la proposition qui la porte.');
        $this->assertArrayNotHasKey('Piste', $parEntite, "Ni l'affaire d'origine.");
    }
}
