<?php

namespace App\Tests\Onboarding;

use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Monnaie;
use App\Entity\ParametresConge;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use App\Service\Conge\DroitCongeParDefaut;
use App\Service\Onboarding\OnboardingCatalogue;
use App\Service\Onboarding\OnboardingCompletude;
use App\Services\ServiceInitialisationEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * OnboardingCompletude : ce qui compte, ce qui ne compte pas, et pourquoi.
 *
 * Le cabinet de test est semé par le VRAI service d'initialisation — c'est la seule
 * façon de vérifier que le score de départ reflète l'état réel d'un cabinet neuf, et
 * non l'idée qu'on s'en fait.
 */
class OnboardingCompletudeTest extends KernelTestCase
{
    private const ENT = 'PHPUnit-Onboarding-Completude';
    private const OWNER = 'phpunit-onboarding-completude@test.local';

    private EntityManagerInterface $em;
    private OnboardingCompletude $completude;
    private Entreprise $entreprise;
    private Invite $invite;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->completude = static::getContainer()->get(OnboardingCompletude::class);
        $this->cleanUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /** Un cabinet neuf, semé exactement comme le fait le provisionnement réel. */
    private function seed(): void
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit');
        $owner->setPassword('x');
        $owner->setVerified(true);
        $this->em->persist($owner);

        $this->entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')
            // 180 = RDC : le semis pose alors une monnaie locale avec un taux provisoire.
            ->setPays(180)
            ->setUtilisateur($owner);
        $this->em->persist($this->entreprise);
        $this->em->flush();

        $this->invite = (new Invite())->setNom('Administrateur')->setUtilisateur($owner)
            ->setEntreprise($this->entreprise)->setProprietaire(true);
        $this->em->persist($this->invite);

        static::getContainer()->get(ServiceInitialisationEntreprise::class)
            ->initialiser($this->entreprise, $this->invite);

        $this->em->flush();
        $this->em->clear();
        $this->entreprise = $this->em->getRepository(Entreprise::class)->find($this->entreprise->getId());
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :o', ['o' => self::OWNER]);

        foreach ([
            'assureur', 'portefeuille', 'compte_bancaire', 'condition_partage', 'partenaire',
            'classeur', 'charge_courtier', 'fournisseur', 'modele_piece_sinistre', 'jour_ferie',
            'parametres_conge', 'regime_travail', 'mouvement_conge', 'type_absence',
            'roles_en_production', 'roles_en_administration', 'roles_en_finance',
            'roles_en_marketing', 'roles_en_sinistre',
            // L'ORDRE COMPTE : autorite_fiscale porte une FK vers taxe, et type_revenu
            // une FK vers chargement. L'enfant part avant le parent, sinon la contrainte
            // refuse la suppression et tout le test tombe sur un nettoyage.
            'type_revenu', 'chargement', 'autorite_fiscale', 'taxe', 'risque', 'groupe', 'monnaie',
        ] as $table) {
            $conn->executeStatement(
                sprintf('DELETE t FROM %s t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', $table),
                ['n' => self::ENT],
            );
        }

        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :o', ['o' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array<string, mixed> */
    private function etape(string $cle): array
    {
        // Une instance neuve à chaque lecture : le service mémoïse par entreprise, et
        // c'est bien ce qu'on veut en production — pas dans un test qui écrit entre deux.
        $completude = new OnboardingCompletude(
            $this->em,
            static::getContainer()->get(OnboardingCatalogue::class),
            static::getContainer()->get(\App\Services\CanvasBuilder::class),
        );

        foreach ($completude->pour($this->entreprise)['etapes'] as $etape) {
            if ($etape['cle'] === $cle) {
                return $etape;
            }
        }

        $this->fail(sprintf('Étape « %s » absente du bilan.', $cle));
    }

    private function score(): int
    {
        return (new OnboardingCompletude(
            $this->em,
            static::getContainer()->get(OnboardingCatalogue::class),
            static::getContainer()->get(\App\Services\CanvasBuilder::class),
        ))->pour($this->entreprise)['score'];
    }

    public function testUnCabinetNeufNEstPasConfigure(): void
    {
        $bilan = $this->completude->pour($this->entreprise);

        $this->assertFalse($bilan['complet'], 'Un cabinet fraîchement semé a tout à configurer.');
        $this->assertLessThan(100, $bilan['score']);
        $this->assertNotEmpty($bilan['restantes']);
        // Le semis ne pose ni assureur, ni portefeuille, ni compte bancaire.
        $this->assertFalse($this->etape('assureurs')['fait']);
        $this->assertFalse($this->etape('portefeuilles')['fait']);
        $this->assertFalse($this->etape('comptes_bancaires')['fait']);
    }

    public function testUnAssureurFaitBasculerLEtapeEtMonterLeScore(): void
    {
        $avant = $this->score();

        $this->creerAssureur('SUNU Assurances');

        $etape = $this->etape('assureurs');
        $this->assertTrue($etape['fait']);
        $this->assertSame(1, $etape['nombre']);
        $this->assertGreaterThan($avant, $this->score());
    }

    /** L'aperçu libelle ses lignes comme le ferait la rubrique — même attribut. */
    public function testLApercuPorteLeNomDeLObjet(): void
    {
        $this->creerAssureur('SUNU Assurances');

        $apercu = $this->etape('assureurs')['apercu'];
        $this->assertCount(1, $apercu);
        $this->assertSame('SUNU Assurances', $apercu[0]['libelle']);
        $this->assertArrayHasKey('id', $apercu[0]);
    }

    public function testUnSecondAssureurNeChangePasLeScoreMaisSAjouteALApercu(): void
    {
        $this->creerAssureur('SUNU Assurances');
        $apres = $this->score();

        $this->creerAssureur('Rawsur SA');

        $this->assertSame($apres, $this->score(), "Une étape déjà faite ne se refait pas.");
        $this->assertCount(2, $this->etape('assureurs')['apercu']);
    }

    public function testAuDelaDeCinqLApercuSeBorneEtCompteLeReste(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $nom) {
            $this->creerAssureur('Assureur ' . $nom);
        }

        $etape = $this->etape('assureurs');
        $this->assertSame(7, $etape['nombre']);
        $this->assertCount(5, $etape['apercu'], 'La carte reste une consultation simple.');
        $this->assertSame(2, $etape['reste']);
    }

    // ------------------------------------------------------------ Cas spéciaux

    /**
     * Le semis pose la monnaie locale à 1,00 — un placeholder. Tant qu'il n'a pas bougé,
     * toute conversion est fausse en silence : l'étape ne peut pas être « faite ».
     */
    public function testLeTauxDeChangePlaceholderNEstPasUnTauxReglé(): void
    {
        $this->assertFalse($this->etape('taux_change')['fait']);

        $locale = $this->em->getRepository(Monnaie::class)
            ->findOneBy(['entreprise' => $this->entreprise, 'locale' => true]);
        $this->assertNotNull($locale, 'Le semis doit poser une monnaie locale pour un cabinet non-USD.');

        $locale->setTauxusd('2850.00');
        $this->em->flush();

        $this->assertTrue($this->etape('taux_change')['fait']);
    }

    /**
     * Le propriétaire existe dès la création : compter les invités à partir de un
     * validerait l'étape sans que personne ait été invité. Et un rôle posé d'office
     * n'est pas un droit décidé par le cabinet.
     */
    public function testInviterNeSuffitPasIlFautHabiliter(): void
    {
        $this->assertFalse($this->etape('collaborateurs')['fait'], 'Le propriétaire seul ne fait pas une équipe.');

        $collegue = $this->ajouterCollegue();
        $this->assertFalse($this->etape('collaborateurs')['fait'], 'Un invité sans droit décidé reste devant un espace vide.');

        // Le rôle posé d'office par DroitCongeParDefaut ne compte pas.
        $offert = (new \App\Entity\RolesEnAdministration())
            ->setNom(DroitCongeParDefaut::NOM_ROLE)
            ->setAccessConge([Invite::ACCESS_LECTURE]);
        $offert->setEntreprise($this->entreprise);
        $collegue->addRolesEnAdministration($offert);
        $this->em->persist($offert);
        $this->em->flush();

        $this->assertFalse($this->etape('collaborateurs')['fait'], "Le droit de poser ses congés n'est pas un périmètre.");

        $role = (new RolesEnProduction())->setNom('Gestionnaire')->setAccessClient([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($this->entreprise);
        $collegue->addRolesEnProduction($role);
        $this->em->persist($role);
        $this->em->flush();

        $this->assertTrue($this->etape('collaborateurs')['fait']);
    }

    /**
     * Le dépôt rend une instance TRANSITOIRE quand rien n'est enregistré : s'y fier
     * validerait l'étape dès le premier jour.
     */
    public function testLesParametresDeCongeSeComptentEnBase(): void
    {
        $this->assertFalse($this->etape('parametres_conges')['fait']);

        $parametres = new ParametresConge();
        $parametres->setEntreprise($this->entreprise);
        $this->em->persist($parametres);
        $this->em->flush();

        $this->assertTrue($this->etape('parametres_conges')['fait']);
    }

    // ------------------------------------------------------------ Rappels

    public function testLesEtapesCiteesSontLesPlusLourdes(): void
    {
        $citees = (new OnboardingCompletude(
            $this->em,
            static::getContainer()->get(OnboardingCatalogue::class),
            static::getContainer()->get(\App\Services\CanvasBuilder::class),
        ))->etapesACiter($this->entreprise, 3);

        $this->assertCount(3, $citees);
        foreach ($citees as $etape) {
            $this->assertSame(OnboardingCatalogue::POIDS_BLOQUANT, $etape['poids']);
        }
    }

    public function testResteDuBloquantSurUnCabinetNeuf(): void
    {
        $this->assertTrue($this->completude->resteDuBloquant($this->entreprise));
    }

    /** Le mode allégé rend le même score, sans construire les aperçus. */
    public function testScoreSeulNeConstruitPasLesApercus(): void
    {
        $this->creerAssureur('SUNU Assurances');

        $service = new OnboardingCompletude(
            $this->em,
            static::getContainer()->get(OnboardingCatalogue::class),
            static::getContainer()->get(\App\Services\CanvasBuilder::class),
        );

        $allege = $service->scoreSeul($this->entreprise);
        $this->assertSame($this->score(), $allege['score']);

        foreach ($allege['etapes'] as $etape) {
            $this->assertSame([], $etape['apercu']);
        }
    }

    private function creerAssureur(string $nom): void
    {
        $assureur = (new Assureur())->setNom($nom);
        $assureur->setEntreprise($this->entreprise);
        $this->em->persist($assureur);
        $this->em->flush();
    }

    private function ajouterCollegue(): Invite
    {
        $collegue = (new Invite())->setNom('Collègue')->setEntreprise($this->entreprise)->setProprietaire(false);
        $this->em->persist($collegue);
        $this->em->flush();

        return $collegue;
    }
}
