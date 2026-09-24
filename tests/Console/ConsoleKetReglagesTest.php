<?php

namespace App\Tests\Console;

use App\Ai\Reglage\ReglagesDeKet;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\KetReglageJournal;
use App\Entity\Utilisateur;
use App\Enum\Departement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'ÉCRAN « RÉGLAGES DE KET » — inventaire, en lecture seule.
 *
 * Trois choses à protéger :
 *  - IL EST OUVERT AU-DELÀ DU SUPER-ADMIN, et c'est délibéré. Le support et le
 *    commercial ont besoin de savoir ce que Ket sait faire ; la page ne montre aucune
 *    donnée de cabinet, seulement le catalogue du produit. Finance et RH n'y ont
 *    rien à faire, et le filtre départemental le dit.
 *  - IL NE MONTRE QUE CE QUI EXISTE. Tous les outils du conteneur, avec leur poids
 *    réel : un écran de réglage qui décrit autre chose que la réalité est pire que
 *    pas d'écran du tout.
 *  - IL N'ÉCRIT RIEN. Les interrupteurs arrivent au lot suivant ; d'ici là, aucune
 *    méthode d'écriture ne doit exister sur cette route.
 */
final class ConsoleKetReglagesTest extends WebTestCase
{
    private const CLIENT  = 'phpunit-ketreg-client@test.local';
    private const SUPPORT = 'phpunit-ketreg-support@test.local';
    private const COMMERCE = 'phpunit-ketreg-commerce@test.local';
    private const FINANCE = 'phpunit-ketreg-finance@test.local';
    private const RH      = 'phpunit-ketreg-rh@test.local';
    private const SUPER   = 'phpunit-ketreg-super@test.local';
    private const PASSWORD = 'Test1234!';
    private const URL = '/console/ket/reglages';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $comptes = [
            self::CLIENT   => [[], null],
            self::SUPPORT  => [['ROLE_ADMIN'], Departement::RELATION_CLIENT],
            self::COMMERCE => [['ROLE_ADMIN'], Departement::COMMERCIAL],
            self::FINANCE  => [['ROLE_ADMIN'], Departement::FINANCE],
            self::RH       => [['ROLE_ADMIN'], Departement::RH],
            self::SUPER    => [['ROLE_SUPER_ADMIN'], Departement::DIRECTION],
        ];

        foreach ($comptes as $email => [$roles, $departement]) {
            $u = (new Utilisateur())
                ->setEmail($email)
                ->setNom('PHPUnit ' . $email)
                ->setVerified(true);
            $u->setRoles($roles);
            $u->setDepartement($departement);
            $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
            $em->persist($u);
        }
        $em->flush();
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
        // ETAT GLOBAL SANS ROLLBACK : le journal d'abord (cle etrangere vers
        // l'utilisateur), puis les comptes, puis le singleton. Sans cette purge, on
        // fait echouer des tests d'autres fichiers - meme regle que
        // ConsoleKetFournisseursTest.
        $conn->executeStatement('DELETE FROM ket_reglage_journal');
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::CLIENT, self::SUPPORT, self::COMMERCE, self::FINANCE, self::RH, self::SUPER]],
            ['e' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM plateforme_parametres');
        static::getContainer()->get(ReglagesDeKet::class)->refresh();
    }

    /** Poste une bascule d'outil avec un jeton valide. */
    private function basculer(string $nom, bool $actif, string $motif): void
    {
        $this->client->request('POST', self::URL . '/outil/' . $nom, [
            'actif' => $actif ? '1' : '0',
            'motif' => $motif,
            '_token' => $this->jeton(),
        ]);
    }

    private function reglages(): ReglagesDeKet
    {
        return static::getContainer()->get(ReglagesDeKet::class);
    }

    /**
     * LE JETON SE LIT DANS LA PAGE, PAS DANS LE CONTENEUR.
     *
     * Le gestionnaire de jetons a besoin d'une session, et il n'y en a aucune tant
     * qu'aucune requête n'a eu lieu. Le lire dans le formulaire réellement rendu
     * évite ce détour — et fait, au passage, un test de plus : si la page cessait
     * d'émettre son jeton, ces tests tomberaient, ce qui est exactement ce qu'on veut.
     *
     * Rend une valeur factice quand la page n'en porte aucun : c'est le cas d'un
     * agent sans droit d'écriture, pour qui la garde de rôle refuse AVANT même que
     * le jeton soit examiné.
     */
    private function jeton(): string
    {
        $crawler = $this->client->request('GET', self::URL);
        $champs = $crawler->filter('input[name="_token"]');

        return $champs->count() > 0 ? (string) $champs->first()->attr('value') : 'aucun-jeton';
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testUnCourtierClientNAtteintPasLEcran(): void
    {
        $this->client->loginUser($this->user(self::CLIENT));
        $this->client->request('GET', self::URL);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLeSupportEtLeCommercialConsultentLInventaire(): void
    {
        foreach ([self::SUPPORT, self::COMMERCE, self::SUPER] as $email) {
            $this->client->loginUser($this->user($email));
            $this->client->request('GET', self::URL);

            $this->assertResponseIsSuccessful(sprintf('%s doit pouvoir consulter l’inventaire.', $email));
        }
    }

    public function testFinanceEtRhSontHorsPerimetre(): void
    {
        foreach ([self::FINANCE, self::RH] as $email) {
            $this->client->loginUser($this->user($email));
            $this->client->request('GET', self::URL);

            $this->assertResponseStatusCodeSame(403, sprintf('%s n’a rien à faire sur cet écran.', $email));
        }
    }

    /**
     * L'INVENTAIRE DÉCRIT LA RÉALITÉ. On vérifie sur trois outils de nature
     * différente — un de consultation, un d'écriture, un verrouillé — plutôt que sur
     * un seul, pour qu'une régression du filtrage se voie.
     */
    public function testLInventaireMontreLesOutilsAvecLeurPoids(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $crawler = $this->client->request('GET', self::URL);
        $html = (string) $this->client->getResponse()->getContent();

        foreach (['vigie_echeances', 'preparer_operations', 'rechercher_entites'] as $outil) {
            $this->assertStringContainsString($outil, $html, sprintf('L’outil %s manque à l’inventaire.', $outil));
        }

        // LE NOMBRE SE DÉRIVE, IL NE SE FIGE PAS. Écrire « 53 » ici — c'était le
        // chiffre annoncé, et il était faux — obligerait à corriger ce test à chaque
        // outil ajouté, et le transformerait en simple compteur. Comparé au
        // conteneur, il dit ce qui compte vraiment : l'inventaire ne cache rien.
        $attendus = \count(static::getContainer()->get(TrousseCatalogue::class)->tous());

        $this->assertSame(
            $attendus,
            $crawler->filter('details.kr-row')->count(),
            'L’inventaire doit montrer TOUS les outils du conteneur, désactivés compris.'
        );
        $this->assertStringContainsString('jetons', $html, 'Le poids en jetons doit être affiché.');
    }

    /**
     * LE STATUT N'EST JAMAIS PORTÉ PAR LA SEULE COULEUR (WCAG 1.4.1) : un outil
     * verrouillé porte le mot, pas seulement une pastille d'une autre teinte.
     */
    public function testUnOutilVerrouillePorteSonLibelle(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', self::URL);

        $this->assertStringContainsString('Verrouillé', (string) $this->client->getResponse()->getContent());
    }

    public function testLEncartDAccesAnnonceLaConditionEtLesChiffres(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', self::URL);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('solde de jetons payant', $html);
        $this->assertStringContainsString('ne se modifie pas depuis la console', $html);
    }

    public function testLeFiltreParClasseReduitLaListe(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $tout = $this->client->request('GET', self::URL)->filter('details.kr-row')->count();
        $verrouilles = $this->client->request('GET', self::URL . '?classe=indispensable')
            ->filter('details.kr-row')->count();

        $this->assertGreaterThan(0, $verrouilles);
        $this->assertLessThan($tout, $verrouilles, 'Le filtre de classe doit réduire la liste.');
    }

    /**
     * COUPER UN OUTIL LE RETIRE VRAIMENT, ET LAISSE UNE TRACE.
     *
     * L’effet moteur est vérifié ailleurs (OutilDesactiveTest) ; ici c’est le geste
     * de console qu’on protège : il écrit l’état ET la ligne de journal, ensemble.
     */
    public function testLeSuperAdminCoupeUnOutilEtLaisseUneTrace(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->basculer('conges', false, 'Module congés non déployé chez nos clients.');

        $this->assertResponseRedirects();
        $this->em()->clear();

        self::assertContains('conges', $this->reglages()->outilsCoupes(), 'L’outil devait être coupé.');

        $journal = $this->em()->getRepository(KetReglageJournal::class)->findAll();
        self::assertCount(1, $journal, 'Tout changement doit laisser exactement une ligne.');
        self::assertSame('outil:conges', $journal[0]->getElement());
        self::assertSame('actif', $journal[0]->getAncienneValeur());
        self::assertSame('coupé', $journal[0]->getNouvelleValeur());
        self::assertStringContainsString('Module congés', $journal[0]->getMotif());
        self::assertNotSame('', $journal[0]->getAuteurNom(), 'L’auteur doit être nommé.');
    }

    /**
     * SANS MOTIF, RIEN NE S’ÉCRIT — et pas seulement parce que le champ est `required`
     * dans le formulaire. L’attribut HTML ne protège que le navigateur.
     */
    public function testUnChangementSansMotifEstRefuse(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->basculer('conges', false, 'x');

        $this->em()->clear();
        self::assertNotContains('conges', $this->reglages()->outilsCoupes(), 'Un changement sans motif ne doit rien écrire.');
        self::assertCount(0, $this->em()->getRepository(KetReglageJournal::class)->findAll());
    }

    /**
     * LE TEST LE PLUS IMPORTANT DU FICHIER. Un outil indispensable ne se coupe pas,
     * même par un super-administrateur, même par appel DIRECT à la route — sans passer
     * par l’écran, qui n’affiche aucun interrupteur pour lui.
     */
    public function testUnOutilIndispensableNeSeCoupePasMemeParAppelDirect(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->basculer('rechercher_entites', false, 'Tentative de coupure d’un indispensable.');

        $this->em()->clear();
        self::assertNotContains(
            'rechercher_entites',
            $this->reglages()->outilsCoupes(),
            'Un outil indispensable a été coupé : la garde n’est pas sur le chemin d’écriture.'
        );
        self::assertCount(
            0,
            $this->em()->getRepository(KetReglageJournal::class)->findAll(),
            'Un refus ne doit pas laisser de ligne de journal.'
        );
    }

    /** Un agent sans le droit ne règle rien, même en postant directement. */
    public function testUnAgentSansLeDroitNeReglePas(): void
    {
        $this->client->loginUser($this->user(self::SUPPORT));
        $this->basculer('conges', false, 'Tentative depuis un compte sans droit.');

        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame([], $this->reglages()->outilsCoupes());
    }

    public function testUnSeuilHorsBornesEstRefuse(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('POST', self::URL . '/parametre/vigie.horizon_jours', [
            'valeur' => 5000,
            'motif' => 'Horizon volontairement absurde.',
            '_token' => $this->jeton(),
        ]);

        $this->em()->clear();
        self::assertSame(
            30,
            $this->reglages()->parametre('vigie.horizon_jours'),
            'Une valeur hors bornes ne doit pas être enregistrée.'
        );
    }

    public function testUnSeuilDansLesBornesSEnregistre(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('POST', self::URL . '/parametre/vigie.horizon_jours', [
            'valeur' => 45,
            'motif' => 'Nos courtiers anticipent plus tôt que prévu.',
            '_token' => $this->jeton(),
        ]);

        $this->em()->clear();
        self::assertSame(45, $this->reglages()->parametre('vigie.horizon_jours'));
    }

    /**
     * « RÉTABLIR » REND L’ÉTAT INITIAL, et garde trace de ce qui a été annulé — sans
     * quoi on efface en un clic un travail de réglage dont il ne resterait rien.
     */
    public function testRetablirRestaureLEtatInitialEtConserveLaTrace(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->basculer('conges', false, 'Coupure temporaire pour mesure.');

        $this->client->request('POST', self::URL . '/reinitialiser', [
            'motif' => 'Fin de la mesure, on remet tout d’aplomb.',
            '_token' => $this->jeton(),
        ]);

        $this->em()->clear();
        self::assertTrue($this->reglages()->estVierge(), 'La plateforme doit être revenue aux valeurs du code.');
        self::assertSame(30, $this->reglages()->parametre('vigie.horizon_jours'));

        $journal = $this->em()->getRepository(KetReglageJournal::class)->findAll();
        self::assertCount(2, $journal, 'La coupure ET sa remise à zéro doivent figurer à l’historique.');
        self::assertSame(KetReglageJournal::TYPE_REINITIALISATION, $journal[1]->getType());
        self::assertStringContainsString('conges', (string) $journal[1]->getAncienneValeur());
    }

    /**
     * LES TROIS FAMILLES DE RÈGLES SONT AFFICHÉES, ET AUCUNE N'EST RÉGLABLE.
     *
     * Leur seul intérêt est d'être citables : on vérifie donc que les identifiants
     * apparaissent, et qu'aucun interrupteur ne s'est glissé à côté d'eux.
     */
    public function testLesTroisFamillesDeReglesSontAffichees(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', self::URL);
        $html = (string) $this->client->getResponse()->getContent();

        foreach (['R1', 'R14', 'K1', 'K14', 'B1', 'B13'] as $id) {
            $this->assertStringContainsString(
                '>' . $id . '<',
                $html,
                sprintf('La règle %s doit être citable depuis l’écran.', $id)
            );
        }

        $this->assertStringContainsString('personne ne peut modifier depuis la console', $html);
    }

    /**
     * AUCUNE ÉCRITURE TANT QUE LES INTERRUPTEURS N'EXISTENT PAS. Une route qui
     * accepterait POST alors que rien ne l'écoute est une porte qu'on oublie
     * d'examiner le jour où elle sert.
     */
    public function testLEcranNAccepteAucuneEcriture(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $methode) {
            $this->client->request($methode, self::URL);
            $this->assertResponseStatusCodeSame(405, sprintf('%s ne doit pas être accepté.', $methode));
        }
    }
}
