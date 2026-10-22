<?php

namespace App\Tests\Security;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\SoaAccesToken;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LES ROUTES RÉELLES : une pièce .html déposée part en téléchargement, sous `nosniff` et
 * `sandbox`, par la route de l'espace de travail comme par le relevé public (chantier 18).
 */
final class ServiceDesPiecesTest extends WebTestCase
{
    private const ENT = 'PHPUNIT Service des pièces';
    private const EMAIL = 'phpunit-service-pieces@test.local';

    private KernelBrowser $navigateur;
    private ?string $fichier = null;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function nettoyer(): void
    {
        if ($this->fichier !== null && is_file($this->fichier)) {
            unlink($this->fichier);
        }
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::EMAIL]);
        foreach (['document', 'soa_acces_token', 'client', 'invite'] as $table) {
            $conn->executeStatement("DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::EMAIL]);
        $this->em()->clear();
    }

    /** @return array{document: int, token: string, utilisateur: Utilisateur} */
    private function semer(): array
    {
        $em = $this->em();
        $u = (new Utilisateur())->setEmail(self::EMAIL)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $em->persist($u);
        $e = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($u);
        $em->persist($e);
        $u->setConnectedTo($e);
        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true)->setUtilisateur($u)->setEntreprise($e);
        $em->persist($invite);
        $client = (new Client())->setNom('Client des pièces')->setExonere(false);
        $client->setEntreprise($e);
        $em->persist($client);

        $nomStocke = 'phpunit-piege-' . bin2hex(random_bytes(6)) . '.html';
        $this->fichier = \App\Tests\Support\DossierDesPieces::chemin(static::getContainer()) . '/' . $nomStocke;
        file_put_contents($this->fichier, '<!doctype html><html><body><script>alert(document.cookie)</script></body></html>');
        $document = (new Document())->setNom("Piège\"\r\nX-Injecte: 1")->setClient($client);
        $document->setNomFichierStocke($nomStocke);
        // Partagée : sinon le relevé public la refuserait avant même de la servir (option (c)).
        $document->setPartageeAvecLeClient(true);
        $document->setEntreprise($e)->setInvite($invite);
        $em->persist($document);

        $token = bin2hex(random_bytes(32));
        $acces = (new SoaAccesToken())->setToken($token)->setClient($client)->setExpiresAt(new \DateTimeImmutable('+30 days'));
        $acces->setEntreprise($e);
        $em->persist($acces);
        $em->flush();

        return ['document' => (int) $document->getId(), 'token' => $token, 'utilisateur' => $u];
    }

    private function exigerUnTelechargementDurci(string $route): void
    {
        $reponse = $this->navigateur->getResponse();
        self::assertSame(200, $reponse->getStatusCode(), $route);
        $disposition = (string) $reponse->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition, $route . ' : jamais affiché');
        self::assertDoesNotMatchRegularExpression('/[\r\n]/', $disposition, $route . ' : aucun en-tête injecté');
        self::assertFalse($reponse->headers->has('X-Injecte'), $route);
        self::assertStringNotContainsString('html', (string) $reponse->headers->get('Content-Type'), $route);
        self::assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'), $route);
        self::assertSame('sandbox', $reponse->headers->get('Content-Security-Policy'), $route);
    }

    public function testLaRouteDeLEspaceDeTravailTelechargeUnHtmlSansJamaisLAfficher(): void
    {
        $s = $this->semer();
        $this->navigateur->loginUser($s['utilisateur']);
        $this->navigateur->request('GET', sprintf('/admin/document/api/%d/download', $s['document']));
        $this->exigerUnTelechargementDurci('espace de travail');
    }

    public function testLeRelevePublicTelechargeUnHtmlSansJamaisLAfficher(): void
    {
        $s = $this->semer();
        $this->navigateur->request('GET', sprintf('/soa/%s/document/%d/telecharger', $s['token'], $s['document']));
        $this->exigerUnTelechargementDurci('relevé public');
    }
}
