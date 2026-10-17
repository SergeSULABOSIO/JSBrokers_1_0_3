<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE TEXTE RICHE EST ASSAINI À L'ÉCRITURE — et un texte simple reste intact.
 *
 * Un champ riche (ChampsEnTexteRiche) était stocké tel quel : `<p><img onerror=…></p>`
 * arrivait en base et repartait en HTML. L'écouteur (AssainissementDuTexteRicheListener)
 * agit au flush, donc pour toutes les portes : écran, Ket, import.
 */
class TexteRicheEcritureTest extends KernelTestCase
{
    private const EMAIL = 'phpunit-texte-riche@test.local';
    private const ENT = 'PHPUnit Texte Riche SARL';

    private EntityManagerInterface $em;
    private Entreprise $entreprise;
    private Invite $invite;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Texte riche')->setVerified(true)->setPassword('x');
        $this->em->persist($user);
        $this->entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $this->em->persist($this->entreprise);
        $this->invite = (new Invite())->setNom('Proprio')->setUtilisateur($user)->setEntreprise($this->entreprise)->setProprietaire(true);
        $this->em->persist($this->invite);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE g FROM groupe g JOIN entreprise e ON e.id = g.entreprise_id WHERE e.nom = ?', [self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN utilisateur u ON u.id = i.utilisateur_id WHERE u.email = ?', [self::EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = ?', [self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
    }

    private function groupe(string $description): Groupe
    {
        $groupe = (new Groupe())->setNom('Groupe')->setDescription($description);
        $groupe->setEntreprise($this->entreprise);
        $groupe->setInvite($this->invite);

        return $groupe;
    }

    private function relire(int $id): ?string
    {
        return $this->em->getConnection()->fetchOne('SELECT description FROM groupe WHERE id = ?', [$id]);
    }

    public function testUnTexteRichePiegeEstAssainiALaCreation(): void
    {
        $groupe = $this->groupe('<p>Clients <strong>prioritaires</strong> <img src=x onerror="alert(1)"></p>');
        $this->em->persist($groupe);
        $this->em->flush();

        $stocke = $this->relire($groupe->getId());
        self::assertStringContainsString('<strong>prioritaires</strong>', $stocke);
        self::assertStringNotContainsString('<img', $stocke, 'La charge est arrivée en base.');
    }

    public function testEtALaModification(): void
    {
        $groupe = $this->groupe('<p>Propre</p>');
        $this->em->persist($groupe);
        $this->em->flush();

        $groupe->setDescription('<p>Modifié <a href="javascript:alert(1)">clic</a></p>');
        $this->em->flush();

        self::assertStringNotContainsString('<a', $this->relire($groupe->getId()), 'La modification a gardé la charge.');
    }

    public function testUnTexteSimpleResteIntact(): void
    {
        // Ce que Ket ou un import écrivent : du texte, restitué partout comme du texte.
        $texte = 'Contact : direction@client.cd, remise 3+4=7 % « A<B »';
        $groupe = $this->groupe($texte);
        $this->em->persist($groupe);
        $this->em->flush();

        self::assertSame($texte, $this->relire($groupe->getId()), 'Un texte simple n\'est ni assaini ni encodé.');
    }

    public function testUnTexteRicheTropLongUneFoisMisEnFormeEstRefuseAvantLEcriture(): void
    {
        $groupe = $this->groupe('<p>' . str_repeat('<strong>x</strong> ', 20) . str_repeat('y', 150) . '</p>');
        $inspector = static::getContainer()->get(ChampsObligatoiresInspector::class);

        $erreurs = $inspector->incoherencesMetier($groupe, 'Groupe');
        self::assertArrayHasKey('description', $erreurs, 'Le débordement de la colonne doit être dit avant le flush.');
        self::assertStringContainsString('trop long une fois mis en forme', $erreurs['description'][0]);

        self::assertSame([], $inspector->incoherencesMetier($this->groupe('<p>Court</p>'), 'Groupe'));
    }
}
