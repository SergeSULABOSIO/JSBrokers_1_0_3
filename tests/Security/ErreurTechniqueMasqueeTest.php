<?php

namespace App\Tests\Security;

use App\Entity\Bordereau;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE TEXTE D'UNE ERREUR TECHNIQUE N'ARRIVE PAS À L'ÉCRAN.
 *
 * L'aperçu d'un bordereau dont le classeur est absent ou illisible affichait le message
 * brut du lecteur PhpSpreadsheet : « File "C:\…\public\uploads\documents\x.xlsx" does not
 * exist. » — le chemin du serveur, chez le courtier. L'écran reçoit désormais un message
 * du métier ; le détail part au journal (supervision).
 */
class ErreurTechniqueMasqueeTest extends WebTestCase
{
    private const EMAIL = 'phpunit-erreur-masquee@test.local';
    private const ENT = 'PHPUnit Erreur Masquée SARL';
    private const FICHIER = 'phpunit-classeur-absent-4f1c.xlsx';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE d FROM document d JOIN bordereau b ON b.id = d.bordereau_id JOIN entreprise e ON e.id = b.entreprise_id WHERE e.nom = ?', [self::ENT]);
        $conn->executeStatement('DELETE b FROM bordereau b JOIN entreprise e ON e.id = b.entreprise_id WHERE e.nom = ?', [self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN utilisateur u ON u.id = i.utilisateur_id WHERE u.email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = ?', [self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    /**
     * L'AUTRE MOITIÉ DE LA RÈGLE : un refus du métier passe toujours, mot pour mot. Les refus
     * d'un plan de suppression étaient levés en `\RuntimeException` nue : sans leur type, le
     * tri les aurait masqués comme une panne.
     */
    public function testLeRefusDUnPlanDeSuppressionResteLisible(): void
    {
        $moteur = static::getContainer()->get(\App\Service\Workspace\SuppressionEnCascade::class);
        $tri = static::getContainer()->get(\App\Service\Erreur\MessageDErreur::class);
        $motif = 'Ce client porte des notes émises : il ne peut pas être supprimé.';

        try {
            $moteur->executer(\App\Service\Workspace\PlanDeSuppression::refuse(\App\Entity\Client::class, 1, $motif));
            self::fail('Un plan refusé ne doit pas s\'exécuter.');
        } catch (\RuntimeException $refus) {
            self::assertSame($motif, $tri->pourLEcran($refus, 'repli technique'));
        }
    }

    public function testLApercuDUnClasseurAbsentNeDitPasLeCheminDuServeur(): void
    {
        $em = $this->em();
        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($user);
        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($entreprise);
        $user->setConnectedTo($entreprise);
        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true)->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $bordereau = (new Bordereau())
            ->setType(0)->setNom('Bordereau masqué')->setReference('BRD-MASQUE')
            ->setReceivedAt(new \DateTimeImmutable())->setPeriodeDebut(new \DateTimeImmutable())->setPeriodeFin(new \DateTimeImmutable())
            ->setMontantComHtPayableNow(0.0)->setMontantTaxePayableNow(0.0);
        $bordereau->setInvite($invite)->setEntreprise($entreprise);
        $em->persist($bordereau);
        // Le fichier n'existe pas sur le disque : le lecteur lève une exception technique.
        $document = (new Document())->setNom('Relevé assureur');
        $document->setNomFichierStocke(self::FICHIER);
        $document->setInvite($invite)->setEntreprise($entreprise);
        $bordereau->addDocument($document);
        $em->persist($document);
        $em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/bordereau/workspace-apercu/' . $bordereau->getId());
        $corps = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        foreach (['uploads', 'documents' . \DIRECTORY_SEPARATOR, self::FICHIER, 'does not exist', 'JSBrokers_1_0_3'] as $fuite) {
            self::assertStringNotContainsString($fuite, $corps, "Fuite à l'écran : « $fuite ».");
        }
        self::assertStringContainsString('n\u2019a pas pu \u00eatre lu', json_encode(json_decode($corps, true)) ?: $corps);
    }
}
