<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Entity\Utilisateur;
use App\Exception\PieceIntangibleException;
use App\Service\Retro\ReversementIntangible;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN REVERSEMENT ENREGISTRÉ NE SE SUPPRIME PAS (Serge, 2026-10-30 — ReversementIntangible).
 *
 * C'est une pièce comptable : il s'annule par une CONTRE-PASSATION du virement entier, et
 * l'original reste lisible à côté de son annulation. Ce test tient les deux gardiens :
 * l'écran (409 qui explique, rien n'est supprimé, ni le virement ni ses voisins) et le
 * dernier rempart, le flush (IntangibiliteDesReversementsListener), qui refuse même une
 * suppression ou une modification comptable faite directement par l'ORM.
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

    /** @param int[] $ids */
    private function tousPresents(array $ids): void
    {
        $this->em()->clear();
        $repo = $this->em()->getRepository(ReversementRetroAgent::class);
        foreach ($ids as $id) {
            self::assertNotNull($repo->find($id), "La ligne #{$id} est toujours là.");
        }
    }

    public function testLEcranRefuseDeSupprimerUnVirementEtDitPourquoi(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->client->request('DELETE', '/admin/reversementretroagent/api/delete/' . $s['aIds'][0]);

        self::assertResponseStatusCodeSame(409);
        $corps = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(ReversementIntangible::MOTIF_SUPPRESSION, $corps['message'] ?? null, 'Le refus dit quoi faire à la place.');
        $this->tousPresents([...$s['aIds'], $s['bId']]);
    }

    /** Un versement isolé ne se supprime pas davantage. */
    public function testUnVersementIsoleNeSeSupprimePasNonPlus(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->client->request('DELETE', '/admin/reversementretroagent/api/delete/' . $s['bId']);

        self::assertResponseStatusCodeSame(409);
        $this->tousPresents([$s['bId']]);
    }

    /** LE DERNIER REMPART : même l'ORM, sans passer par l'écran, ne supprime pas un reversement. */
    public function testLeFlushRefuseUneSuppressionDirecte(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $em->remove($em->getRepository(ReversementRetroAgent::class)->find($s['bId']));

        $this->expectException(PieceIntangibleException::class);
        $em->flush();
    }

    /** Ni la modification d'un champ comptable faite directement par l'ORM. */
    public function testLeFlushRefuseDeModifierUnMontant(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $em->getRepository(ReversementRetroAgent::class)->find($s['bId'])->setMontant(8.0);

        $this->expectException(PieceIntangibleException::class);
        $em->flush();
    }

    /** La référence et la description, elles, restent modifiables. */
    public function testLeFlushLaissePasserLaReferenceEtLaDescription(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $em->getRepository(ReversementRetroAgent::class)->find($s['bId'])
            ->setReference('SOLO-CORRIGE')->setDescription('Numéro d’ordre corrigé.');
        $em->flush();

        $em->clear();
        self::assertSame('SOLO-CORRIGE', $em->getRepository(ReversementRetroAgent::class)->find($s['bId'])->getReference());
    }
}
