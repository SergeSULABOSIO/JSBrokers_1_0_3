<?php

namespace App\Tests\Security;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\EventSubscriber\OwnerEventSubscriber;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * L'AUTEUR D'UNE FICHE NE SE RÉÉCRIT PAS.
 *
 * L'auteur (`invite`) est posé par le formulaire et par Ket (le demandeur) : c'est lui que la
 * frontière des tâches lit (« j'en suis l'auteur »). L'abonné `prePersist` cherchait l'invité
 * par l'e-mail du connecté, tous cabinets confondus, et l'écrasait : une invitation EN ATTENTE
 * portant le même e-mail dans un autre cabinet devenait l'auteur — la tâche quittait le cercle
 * de son vrai auteur et pointait vers un autre cabinet. Deux invitations : exception, plus
 * aucune fiche enregistrable.
 */
final class OwnerEventSubscriberTest extends TestCase
{
    private function evenement(object $entite): LifecycleEventArgs
    {
        return new LifecycleEventArgs($entite, $this->createStub(EntityManagerInterface::class));
    }

    private function abonne(array $invitationsParEmail): OwnerEventSubscriber
    {
        $security = $this->createStub(Security::class);
        $user = (new Utilisateur())->setEmail('serge@exemple.test');
        $security->method('getUser')->willReturn($user);

        $repo = $this->createStub(InviteRepository::class);
        $repo->method('findBy')->willReturn($invitationsParEmail);
        $repo->method('findOneByEmail')->willReturnCallback(static function () use ($invitationsParEmail) {
            if (\count($invitationsParEmail) > 1) {
                throw new \Doctrine\ORM\NonUniqueResultException();
            }

            return $invitationsParEmail[0] ?? null;
        });

        return new OwnerEventSubscriber($security, $repo);
    }

    private static function invite(Entreprise $e): Invite
    {
        return (new Invite())->setEntreprise($e);
    }

    public function testUnAuteurDejaPoseNEstJamaisReecrit(): void
    {
        $cabinet = new Entreprise();
        $auteur = self::invite($cabinet);
        $enAttente = self::invite($cabinet);
        $tache = (new Tache())->setEntreprise($cabinet)->setInvite($auteur);

        $this->abonne([$enAttente])->prePersist($this->evenement($tache));

        self::assertSame($auteur, $tache->getInvite());
    }

    public function testJamaisUnAuteurDUnAutreCabinet(): void
    {
        $cabinet = new Entreprise();
        $ailleurs = self::invite(new Entreprise());
        $tache = (new Tache())->setEntreprise($cabinet);

        $this->abonne([$ailleurs])->prePersist($this->evenement($tache));

        self::assertNull($tache->getInvite());
    }

    public function testDeuxInvitationsNEmpechentPasDEnregistrer(): void
    {
        $cabinet = new Entreprise();
        $tache = (new Tache())->setEntreprise($cabinet);

        $this->abonne([self::invite(new Entreprise()), self::invite(new Entreprise())])->prePersist($this->evenement($tache));

        self::assertNull($tache->getInvite());
    }

    public function testSansAuteurLInvitationDuMemeCabinetEstRetenue(): void
    {
        $cabinet = new Entreprise();
        $memeCabinet = self::invite($cabinet);
        $tache = (new Tache())->setEntreprise($cabinet);

        $this->abonne([self::invite(new Entreprise()), $memeCabinet])->prePersist($this->evenement($tache));

        self::assertSame($memeCabinet, $tache->getInvite());
    }
}
