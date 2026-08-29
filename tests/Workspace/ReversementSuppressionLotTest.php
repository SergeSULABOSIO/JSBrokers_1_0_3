<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SUPPRIMER UNE LIGNE DE RÉTROS INTERMÉDIAIRES DÉFAIT TOUT LE VIREMENT.
 *
 * ── POURQUOI CE N'EST PAS UN EXCÈS DE ZÈLE ──────────────────────────────────────────
 * Depuis que la rubrique replie chaque lot sur son porteur, la ligne sélectionnée
 * REPRÉSENTE un virement entier — elle en porte le total et le nombre d'échéances. N'en
 * supprimer qu'une aurait fait maigrir le décaissement d'un montant que l'écran ne
 * montrait même pas, et laissé une écriture comptable partielle.
 *
 * ── ET LA PORTÉE S'ARRÊTE AU LOT ────────────────────────────────────────────────────
 * Un virement voisin ne doit rien perdre : c'est la moitié du test, et c'est elle qui
 * tomberait si la suppression se mettait à raisonner sur le bénéficiaire ou la date au
 * lieu de la référence de lot.
 */
class ReversementSuppressionLotTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-supl-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Suppression Lot SARL';
    private const LOT_A = 'VIR-SUPL-A';

    private KernelBrowser $client;

    protected function setUp(): void
    {
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
        foreach (['document', 'reversement_retro_agent', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'UPDATE utilisateur u JOIN entreprise e ON u.connected_to_id = e.id
             SET u.connected_to_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Deux virements côte à côte : A de trois échéances, B d'une seule.
     *
     * @return array{aIds: int[], bId: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Suppression')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice Suppression')->setProprietaire(false)->setEntreprise($entreprise);
        $em->persist($agent);
        $em->flush();

        $ecrire = static function (float $montant, ?string $lot) use ($em, $entreprise, $agent): ReversementRetroAgent {
            $r = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('2026-08-01'))
                ->setReference($lot ?? 'SOLO-SUPL')
                ->setLotReference($lot);
            $r->setEntreprise($entreprise)->setInvite($agent);
            $em->persist($r);

            return $r;
        };

        $a = [$ecrire(10.0, self::LOT_A), $ecrire(20.0, self::LOT_A), $ecrire(30.0, self::LOT_A)];
        // B EST UN VERSEMENT VRAIMENT ISOLÉ : pas de référence de lot, comme le serveur
        // en écrit un quand une seule échéance est réglée.
        $b = $ecrire(7.0, null);
        $em->flush();

        $aIds = array_map(static fn (ReversementRetroAgent $r) => $r->getId(), $a);
        $bId = $b->getId();
        $em->clear();

        return ['aIds' => $aIds, 'bId' => $bId];
    }

    private function connecter(): void
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        $this->client->loginUser($utilisateur);
    }

    public function testSupprimerUneLigneDefaitLeVirementEntierEtLuiSeul(): void
    {
        $s = $this->semer();
        $this->connecter();

        // On désigne le PORTEUR, comme le fait la rubrique repliée.
        $this->client->request('DELETE', '/admin/reversementretroagent/api/delete/' . $s['aIds'][0]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();

        $repo = $this->em()->getRepository(ReversementRetroAgent::class);
        foreach ($s['aIds'] as $id) {
            self::assertNull($repo->find($id), "La ligne #{$id} du virement A est supprimée.");
        }

        // ET LE VIREMENT VOISIN N'A RIEN PERDU. Sans cette moitié, une suppression qui
        // raisonnerait sur le bénéficiaire — ils partagent le même — passerait au vert.
        self::assertNotNull($repo->find($s['bId']), 'Le virement B est intact.');
    }

    /**
     * Le geste se fait aussi depuis un membre qui n'est pas le porteur : la rubrique le
     * replie, mais l'assistant ou une recherche avancée peuvent désigner n'importe lequel.
     */
    public function testLeGesteVautDepuisNimporteQuelMembre(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->client->request('DELETE', '/admin/reversementretroagent/api/delete/' . $s['aIds'][2]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();

        $repo = $this->em()->getRepository(ReversementRetroAgent::class);
        self::assertNull($repo->find($s['aIds'][0]), 'Le porteur part aussi.');
        self::assertNull($repo->find($s['aIds'][1]));
        self::assertNotNull($repo->find($s['bId']));
    }

    /**
     * UN VERSEMENT ISOLÉ EST UN LOT D'UN MEMBRE : la règle n'a pas deux cas, et supprimer
     * une ligne sans lot ne doit surtout pas emporter les autres versements sans lot.
     */
    public function testUnVersementIsoleNEmportePasSesVoisins(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->client->request('DELETE', '/admin/reversementretroagent/api/delete/' . $s['bId']);

        self::assertResponseIsSuccessful();
        $this->em()->clear();

        $repo = $this->em()->getRepository(ReversementRetroAgent::class);
        self::assertNull($repo->find($s['bId']));
        foreach ($s['aIds'] as $id) {
            self::assertNotNull($repo->find($id), 'Le virement A est intact.');
        }
    }
}
