<?php

namespace App\Tests\Services;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\InviteRepository;
use App\Service\Workspace\InviteCourant;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * QUI LIT ? — et quand le serveur ne le sait pas, personne ne voit rien (décisions de Serge
 * du 2026-10-21). `false` (aucune frontière) n'existe que dans un contexte système EXPLICITE,
 * nommé, qui refuse de s'ouvrir pendant une requête HTTP.
 */
final class InviteCourantTest extends TestCase
{
    private function courant(?Request $requete = null, ?Utilisateur $connecte = null, ?Invite $saFiche = null): InviteCourant
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($connecte);
        $invites = $this->createStub(InviteRepository::class);
        $invites->method('findOneBy')->willReturn($saFiche);
        $pile = new RequestStack();
        if ($requete !== null) {
            $pile->push($requete);
        }

        return new InviteCourant($security, $invites, $pile);
    }

    private static function avecId(object $o, int $id): object
    {
        (new \ReflectionProperty($o::class, 'id'))->setValue($o, $id);

        return $o;
    }

    private static function cabinet(int $id, ?Utilisateur $proprietaire = null): Entreprise
    {
        return self::avecId((new Entreprise())->setUtilisateur($proprietaire), $id);
    }

    public function testSansUtilisateurPersonneNeVoitRien(): void
    {
        self::assertNull($this->courant(new Request())->pour(self::cabinet(1)), 'requête HTTP anonyme');
        self::assertNull($this->courant()->pour(self::cabinet(1)), 'hors requête, sans contexte déclaré : rien non plus');
    }

    public function testConnecteSansInvitationIciNeVoitRien(): void
    {
        $utilisateur = self::avecId(new Utilisateur(), 7);
        self::assertNull($this->courant(new Request(), $utilisateur)->pour(self::cabinet(1, self::avecId(new Utilisateur(), 8))));
    }

    public function testLeProprietaireSansFicheResteAdministrateur(): void
    {
        $proprietaire = self::avecId(new Utilisateur(), 7);
        $lecteur = $this->courant(new Request(), $proprietaire)->pour(self::cabinet(1, $proprietaire));
        self::assertInstanceOf(Invite::class, $lecteur);
        self::assertTrue($lecteur->isProprietaire());
        self::assertNull($lecteur->getId(), 'une fiche de lecture, jamais persistée');
    }

    public function testLeContexteSystemeEstExpliciteEtBorne(): void
    {
        $courant = $this->courant();
        self::assertFalse($courant->enContexteSysteme('Commande de test', fn () => $courant->pour(self::cabinet(1))));
        self::assertNull($courant->pour(self::cabinet(1)), 'refermé à la sortie');
    }

    public function testLeContexteSystemeEstRefusePendantUneRequeteHttp(): void
    {
        $courant = $this->courant(new Request());
        $this->expectException(\LogicException::class);
        $courant->enContexteSysteme('Tentative depuis le web', fn () => true);
    }

    public function testUnInviteImposeLitPourLuiEtSeulementDansSonCabinet(): void
    {
        $courant = $this->courant();
        $invite = (new Invite())->setEntreprise(self::cabinet(1));
        $courant->imposer($invite);
        self::assertSame($invite, $courant->pour(self::cabinet(1)));
        self::assertNull($courant->pour(self::cabinet(2)), 'jamais un autre cabinet');

        $courant->relacher();
        self::assertNull($courant->pour(self::cabinet(1)), 'relâché : plus personne');
    }
}
