<?php

namespace App\Tests\Security;

use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN COMPTE N'A QU'UNE SEULE INVITATION PAR CABINET.
 *
 * ── POURQUOI C'EST UNE QUESTION DE SECURITE ─────────────────────────────────
 * Tout le cloisonnement repose sur `CabinetActif`, qui resout l'invite par
 * `findOneBy(['utilisateur', 'entreprise'])`. Avec deux lignes, Doctrine en rend UNE,
 * arbitrairement -- selon l'ordre physique, le plan d'execution, le cache.
 *
 * Le perimetre effectif d'un compte devenait donc NON DETERMINISTE : memes utilisateur
 * et cabinet, et des droits qui changent d'une requete a l'autre. Un invite restreint
 * aux Pistes pouvait se voir servir le perimetre d'une seconde invitation oubliee. Le
 * gardien ne peut pas garantir ce que la base ne garantit pas.
 *
 * ── LES DEUX FACES DE LA CONTRAINTE ─────────────────────────────────────────
 * Elle doit MORDRE sur les comptes rattaches, et EPARGNER les invitations en attente --
 * `utilisateur_id` y est NULL tant que la personne n'a pas cree son compte, et un
 * cabinet doit pouvoir en avoir autant qu'il veut. SQL n'applique pas l'unicite aux
 * NULL, et c'est exactement ce qu'il faut ici ; encore faut-il le verifier, parce que
 * c'est le genre de detail qu'une migration « amelioree » casse sans s'en apercevoir.
 */
class UniciteDeLInvitationTest extends KernelTestCase
{
    use SemisDeDeuxCabinetsTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /** LA CONTRAINTE MORD : deux invitations du meme compte dans le meme cabinet. */
    public function testUnDeuxiemeInviteDansLeMemeCabinetEstRefuse(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $em = $this->em();

        $doublon = (new Invite())->setNom('Doublon');
        $doublon->setUtilisateur($em->getRepository(Utilisateur::class)->find($seed['b']['owner']))
            ->setEntreprise($em->getRepository(\App\Entity\Entreprise::class)->find($seed['b']['entreprise']))
            ->setProprietaire(false);
        $em->persist($doublon);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    /**
     * LA CONTRAINTE EPARGNE LES INVITATIONS EN ATTENTE.
     *
     * Sans cette seconde face, la premiere serait une regression deguisee en correctif :
     * un cabinet ne pourrait plus inviter deux personnes a la fois.
     */
    public function testPlusieursInvitationsEnAttenteRestentPossibles(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $em = $this->em();
        $entreprise = $em->getRepository(\App\Entity\Entreprise::class)->find($seed['b']['entreprise']);

        foreach (['Invitee 1', 'Invitee 2', 'Invitee 3'] as $nom) {
            $attente = (new Invite())->setNom($nom)->setEmail(strtolower(str_replace(' ', '', $nom)) . '@test.local');
            // `utilisateur` reste NULL : la personne n'a pas encore cree son compte.
            $attente->setEntreprise($entreprise)->setProprietaire(false);
            $em->persist($attente);
        }
        $em->flush();

        self::assertCount(
            3,
            $em->getRepository(Invite::class)->findBy(['entreprise' => $entreprise, 'utilisateur' => null]),
            'Un cabinet doit pouvoir avoir plusieurs invitations en attente : SQL n\'applique pas '
            . 'l\'unicite aux NULL, et c\'est precisement ce qu\'il faut ici.',
        );
    }

    /** Le meme compte dans DEUX cabinets differents reste parfaitement legitime. */
    public function testLeMemeCompteDansDeuxCabinetsResteAutorise(): void
    {
        $seed = $this->semerLesDeuxCabinets();

        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');

        $utilisateur = $this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']);
        self::assertCount(
            2,
            $this->em()->getRepository(Invite::class)->findBy(['utilisateur' => $utilisateur]),
            'La contrainte porte sur le COUPLE : un compte peut appartenir a plusieurs cabinets, '
            . 'et la bascule de workspace existe pour cela.',
        );
    }
}
