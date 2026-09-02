<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ParametresConge;
use App\Entity\Utilisateur;
use App\Service\Conge\PeriodeParDefaut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA PÉRIODE PROPOSÉE DOIT POUVOIR ÊTRE ACCEPTÉE TELLE QUELLE.
 *
 * Le formulaire s'ouvrait sur « aujourd'hui à aujourd'hui » : une date que le contrôle de
 * préavis refuse, et une durée d'un jour que presque personne ne demande. Chaque saisie
 * commençait par corriger les deux champs que l'écran venait de remplir — un défaut qu'il
 * faut effacer avant de s'en servir n'est pas un défaut, c'est un obstacle poli.
 *
 * Ce que ces tests verrouillent : la proposition RESPECTE le préavis du cabinet, et elle
 * le compte de la MÊME façon que le contrôle qui la relira — en jours ouvrables. Une date
 * proposée qui échouerait au contrôle serait pire que pas de proposition du tout.
 */
class CongePeriodeParDefautTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-periode@test.local';
    private const ENT = 'PHPUnit Congés Période SARL';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach (['periode_blocage', 'parametres_conge', 'roles_en_administration', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    private function cabinet(int $preavis): Invite
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Période')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $invite = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($invite);

        $parametres = new ParametresConge();
        $parametres->setDelaiPreavisJours($preavis);
        $parametres->setEntreprise($ent);
        $em->persist($parametres);

        $em->flush();

        return $invite;
    }

    private function service(): PeriodeParDefaut
    {
        return static::getContainer()->get(PeriodeParDefaut::class);
    }

    /**
     * LA DATE PROPOSÉE PASSE LE CONTRÔLE QUI LA RELIT.
     *
     * C'est la seule propriété qui compte vraiment : le préavis se compte en jours
     * OUVRABLES, et un délai de cinq jours posé un jeudi ne tombe pas le mardi suivant.
     * Refaire le calcul autrement ici produirait des propositions refusées un jour sur
     * deux, selon le jour de la semaine où l'on ouvre le formulaire.
     */
    public function testLeDebutProposeRespecteLePreavisDuCabinet(): void
    {
        $invite = $this->cabinet(5);

        $debut = $this->service()->debut($invite);
        $aujourdhui = new \DateTimeImmutable('today');

        self::assertGreaterThan($aujourdhui, $debut, "Une absence ne peut pas commencer aujourd'hui.");

        // On recompte comme le fait CTRL-03 : les jours ouvrables entre demain et la veille.
        $ouvrables = static::getContainer()->get(\App\Service\Conge\CalculateurJoursOuvrables::class)
            ->calculer($invite, $aujourdhui->modify('+1 day'), $debut->modify('-1 day'));

        self::assertGreaterThanOrEqual(
            5.0,
            $ouvrables,
            'La date proposée doit satisfaire le préavis, sinon le contrôle la refuse aussitôt.',
        );
    }

    /** Sans préavis réglé, on propose demain : commencer aujourd'hui reste refusé. */
    public function testSansPreavisOnProposeDemain(): void
    {
        $invite = $this->cabinet(0);

        self::assertSame(
            (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d'),
            $this->service()->debut($invite)->format('Y-m-d'),
        );
    }

    /**
     * « Dix jours » se lit comme la LONGUEUR de l'absence, non comme un décalage : du 7 au
     * 16, et non du 7 au 17.
     */
    public function testLaFinProposeeCouvreLaDureeUsuelleBornesIncluses(): void
    {
        $debut = new \DateTimeImmutable('2026-09-07');
        $fin = $this->service()->fin($debut);

        self::assertSame('2026-09-16', $fin->format('Y-m-d'));
        self::assertSame(
            PeriodeParDefaut::DUREE_JOURS,
            (int) $debut->diff($fin)->days + 1,
            'La période proposée doit durer exactement la durée usuelle, bornes comprises.',
        );
    }
}
