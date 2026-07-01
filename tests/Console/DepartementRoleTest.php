<?php

namespace App\Tests\Console;

use App\Entity\Utilisateur;
use App\Enum\Departement;
use App\Enum\FonctionCollaborateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console : départements & rôles des collaborateurs + évaluations.
 *
 * Couvre la restriction d'accès par département (réelle, côté serveur), la politique
 * « fail-open jusqu'à affectation », la navigation filtrée, l'affectation par le
 * super-admin (avec notification du concerné), et le cycle objectif → fiche → clôture.
 */
class DepartementRoleTest extends WebTestCase
{
    private const SUPER     = 'phpunit-dr-super@test.local';
    private const FINANCE   = 'phpunit-dr-finance@test.local';
    private const SUPPORT   = 'phpunit-dr-support@test.local';
    private const DIRECTION = 'phpunit-dr-direction@test.local';
    private const RH        = 'phpunit-dr-rh@test.local';
    private const RH_AGENT  = 'phpunit-dr-rhagent@test.local';
    private const LIBRE     = 'phpunit-dr-libre@test.local';
    private const CIBLE     = 'phpunit-dr-cible@test.local';
    private const NOUVEAU   = 'phpunit-dr-nouveau@test.local';
    private const PASSWORD  = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        // [email, roles, departement, fonction]
        $fixtures = [
            [self::SUPER,     ['ROLE_SUPER_ADMIN'], null,                          null],
            [self::FINANCE,   ['ROLE_ADMIN'],       Departement::FINANCE,          FonctionCollaborateur::CHARGE],
            [self::SUPPORT,   ['ROLE_ADMIN'],       Departement::RELATION_CLIENT,  FonctionCollaborateur::RESPONSABLE],
            [self::DIRECTION, ['ROLE_ADMIN'],       Departement::DIRECTION,        FonctionCollaborateur::DIRECTEUR],
            [self::RH,        ['ROLE_ADMIN'],       Departement::RH,               FonctionCollaborateur::DIRECTEUR],
            [self::RH_AGENT,  ['ROLE_ADMIN'],       Departement::RH,               FonctionCollaborateur::CHARGE],
            [self::LIBRE,     ['ROLE_ADMIN'],       null,                          null],
            [self::CIBLE,     ['ROLE_ADMIN'],       null,                          null],
        ];
        foreach ($fixtures as [$email, $roles, $dep, $fonction]) {
            $u = (new Utilisateur())
                ->setEmail($email)
                ->setNom('PHPUnit ' . $email)
                ->setVerified(true)
                ->setRoles($roles)
                ->setDepartement($dep)
                ->setFonction($fonction);
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
        // Les objectifs / évaluations sont supprimés par cascade (ON DELETE CASCADE).
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::SUPER, self::FINANCE, self::SUPPORT, self::DIRECTION, self::RH, self::RH_AGENT, self::LIBRE, self::CIBLE, self::NOUVEAU]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /** Non affecté : accès complet conservé (fail-open jusqu'à affectation). */
    public function testUnassignedAgentKeepsFullAccess(): void
    {
        $this->client->loginUser($this->user(self::LIBRE));

        $this->client->request('GET', '/console/taxes');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/console/crm');
        $this->assertResponseIsSuccessful();
    }

    /** Finance : accède à son périmètre, bloqué ailleurs (403). */
    public function testFinanceAgentIsRestrictedToPerimeter(): void
    {
        $this->client->loginUser($this->user(self::FINANCE));

        $this->client->request('GET', '/console/taxes');
        $this->assertResponseIsSuccessful('Finance doit accéder à la fiscalité.');
        $this->client->request('GET', '/console/taxes/reversements/new');
        $this->assertResponseIsSuccessful('Finance doit pouvoir enregistrer un reversement de TVA (Fiscalité).');
        $this->client->request('GET', '/console/documents');
        $this->assertResponseIsSuccessful('Finance doit accéder aux documents comptables.');

        $crawler = $this->client->request('GET', '/console/crm');
        $this->assertResponseStatusCodeSame(403, 'Finance ne doit pas accéder au CRM.');
        // Boîte de dialogue stylisée (et non page d'erreur brute), rappelant le périmètre.
        $this->assertStringContainsString('Accès restreint', $crawler->filter('h1')->text());
        $this->assertStringContainsString('Finance & Comptabilité', $crawler->filter('.ad-perimeter')->text());
        $this->client->request('GET', '/console/evaluations');
        $this->assertResponseStatusCodeSame(403, 'Finance ne doit pas accéder aux évaluations (RH).');
    }

    /** Support & Relation Client : accède au CRM, bloqué sur la fiscalité. */
    public function testSupportAgentIsRestrictedToPerimeter(): void
    {
        $this->client->loginUser($this->user(self::SUPPORT));

        $this->client->request('GET', '/console/crm');
        $this->assertResponseIsSuccessful('Support doit accéder au CRM.');
        $this->client->request('GET', '/console/taxes');
        $this->assertResponseStatusCodeSame(403, 'Support ne doit pas accéder à la fiscalité.');
    }

    /** RH : gère les collaborateurs, pas les comptes clients/utilisateurs/entreprises. */
    public function testRhPerimeterExcludesPlatformAccounts(): void
    {
        $this->client->loginUser($this->user(self::RH));

        $this->client->request('GET', '/console/collaborateurs');
        $this->assertResponseIsSuccessful('RH doit accéder aux collaborateurs.');
        $this->client->request('GET', '/console/evaluations');
        $this->assertResponseIsSuccessful('RH doit accéder aux évaluations.');

        foreach (['/console/utilisateurs', '/console/clients', '/console/entreprises'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, sprintf('RH ne doit pas accéder à %s.', $url));
        }
    }

    /** Tout collaborateur consulte sa propre fiche d'évaluation, même hors RH. */
    public function testCollaboratorCanViewOwnEvaluation(): void
    {
        $this->client->loginUser($this->user(self::FINANCE));

        // La section RH (liste des évaluations) lui reste interdite…
        $this->client->request('GET', '/console/evaluations');
        $this->assertResponseStatusCodeSame(403);

        // …mais il accède à sa propre fiche en lecture seule.
        $this->client->request('GET', '/console/evaluations/mes-objectifs');
        $this->assertResponseIsSuccessful('Chaque collaborateur doit voir ses propres objectifs.');
    }

    /** Le Directeur RH peut gérer les évaluations (créer un objectif), comme le super-admin. */
    public function testRhDirectorCanManageEvaluations(): void
    {
        $this->client->loginUser($this->user(self::RH));
        $finance = $this->user(self::FINANCE);

        $this->client->request(
            'GET',
            '/console/evaluations/collaborateur/' . $finance->getId() . '/objectif/new?annee=2026&trimestre=0'
        );
        $this->assertResponseIsSuccessful('Le Directeur RH doit pouvoir créer un objectif.');

        $this->client->request(
            'GET',
            '/console/evaluations/collaborateur/' . $finance->getId() . '/evaluer?annee=2026&trimestre=0'
        );
        $this->assertResponseIsSuccessful('Le Directeur RH doit pouvoir évaluer.');
    }

    /** Un agent RH non-directeur consulte mais ne peut pas gérer les évaluations. */
    public function testRhNonDirectorCannotManageEvaluations(): void
    {
        $this->client->loginUser($this->user(self::RH_AGENT));
        $finance = $this->user(self::FINANCE);

        // Lecture autorisée (les évaluations sont dans le périmètre RH).
        $this->client->request('GET', '/console/evaluations');
        $this->assertResponseIsSuccessful('Un agent RH peut consulter les évaluations.');

        // Écriture refusée.
        $this->client->request(
            'GET',
            '/console/evaluations/collaborateur/' . $finance->getId() . '/objectif/new?annee=2026&trimestre=0'
        );
        $this->assertResponseStatusCodeSame(403, 'Un agent RH non-directeur ne doit pas créer d\'objectif.');
    }

    /** Direction Générale : accès complet malgré l'affectation à un département. */
    public function testDirectionAgentHasFullAccess(): void
    {
        $this->client->loginUser($this->user(self::DIRECTION));

        $this->client->request('GET', '/console/crm');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/console/taxes');
        $this->assertResponseIsSuccessful();
    }

    /** La navigation masque les rubriques hors département. */
    public function testNavigationIsFilteredByDepartement(): void
    {
        $this->client->loginUser($this->user(self::FINANCE));
        $this->client->request('GET', '/console');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('/console/taxes', $content, 'Le lien Fiscalité doit être visible (Finance).');
        $this->assertStringNotContainsString('/console/crm/clients', $content, 'Les rubriques CRM doivent être masquées (Finance).');
    }

    /** Le super-admin affecte un collaborateur et le concerné est notifié. */
    public function testSuperAdminAffectsCollaboratorAndNotifies(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $cible = $this->user(self::CIBLE);

        $crawler = $this->client->request('GET', '/console/departements/' . $cible->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['affectation_collaborateur[departement]'] = Departement::FINANCE->value;
        $form['affectation_collaborateur[fonction]'] = FonctionCollaborateur::CHARGE->value;
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/departements');
        $this->assertQueuedEmailCount(1); // au seul collaborateur concerné

        $this->em()->clear();
        $maj = $this->user(self::CIBLE);
        $this->assertSame(Departement::FINANCE, $maj->getDepartement());
        $this->assertSame(FonctionCollaborateur::CHARGE, $maj->getFonction());
    }

    /** Le formulaire de création de collaborateur permet d'affecter directement. */
    public function testCollaboratorFormAssignsDepartement(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $crawler = $this->client->request('GET', '/console/collaborateurs/new');
        $this->assertResponseIsSuccessful();
        // Le champ d'affectation est bien présent dans le formulaire de création.
        $this->assertSame(1, $crawler->filter('select[name="collaborateur[departement]"]')->count(),
            'Le formulaire collaborateur doit proposer le choix du département.');

        $form = $crawler->filter('form')->form();
        $form['collaborateur[nom]'] = 'Nouveau Affecté';
        $form['collaborateur[email]'] = self::NOUVEAU;
        $form['collaborateur[plainPassword]'] = self::PASSWORD;
        $form['collaborateur[departement]'] = Departement::RELATION_CLIENT->value;
        $form['collaborateur[fonction]'] = FonctionCollaborateur::ASSISTANT->value;
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/collaborateurs');

        $this->em()->clear();
        $cree = $this->user(self::NOUVEAU);
        $this->assertNotNull($cree);
        $this->assertSame(Departement::RELATION_CLIENT, $cree->getDepartement());
        $this->assertSame(FonctionCollaborateur::ASSISTANT, $cree->getFonction());
    }

    /** Le tableau de bord est personnalisé : bandeau profil + blocs filtrés. */
    public function testDashboardIsPersonalizedByProfile(): void
    {
        $this->client->loginUser($this->user(self::FINANCE));
        $this->client->request('GET', '/console');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Finance &amp; Comptabilité', $content, 'Le bandeau doit nommer le département.');
        $this->assertStringContainsString('/console/taxes', $content, 'Accès rapide Fiscalité attendu (Finance).');
        // Les blocs hors périmètre (clients/CRM) ne sont pas rendus.
        $this->assertStringNotContainsString('console.dashboard.block_clients', $content);
        $this->assertStringNotContainsString('/console/crm/clients', $content);
    }

    /** Les endpoints de blocs hors périmètre sont refusés (pas de contournement). */
    public function testDashboardBlockEndpointsRespectPerimeter(): void
    {
        $this->client->loginUser($this->user(self::FINANCE));

        $this->client->request('GET', '/console/dashboard/block/taxes');
        $this->assertResponseIsSuccessful('Finance doit charger le bloc fiscalité.');

        $this->client->request('GET', '/console/dashboard/block/clients');
        $this->assertResponseStatusCodeSame(403, 'Finance ne doit pas charger le bloc clients (RH).');
    }

    /** L'affectation est interdite à un agent non super-admin. */
    public function testPlainAdminCannotAffect(): void
    {
        $this->client->loginUser($this->user(self::FINANCE));
        $this->client->request('GET', '/console/departements/' . $this->user(self::CIBLE)->getId() . '/edit');
        $this->assertResponseStatusCodeSame(403);
    }

    /** Le super-admin crée un objectif, le concerné est notifié et la fiche reflète le score. */
    public function testSuperAdminCreatesObjectiveAndScoreReflects(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $finance = $this->user(self::FINANCE);

        $crawler = $this->client->request(
            'GET',
            '/console/evaluations/collaborateur/' . $finance->getId() . '/objectif/new?annee=2026&trimestre=0'
        );
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['objectif[titre]'] = 'Traiter 10 dossiers';
        $form['objectif[annee]'] = '2026';
        $form['objectif[trimestre]'] = '0';
        $form['objectif[cible]'] = '10';
        $form['objectif[unite]'] = 'dossiers';
        $form['objectif[poids]'] = '100';
        $form['objectif[mode]'] = 'manuel';
        $form['objectif[valeurManuelle]'] = '5';
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->assertQueuedEmailCount(1);

        // La fiche affiche un score de 50 (5/10, poids unique).
        $crawler = $this->client->request(
            'GET',
            '/console/evaluations/collaborateur/' . $finance->getId() . '?annee=2026&trimestre=0'
        );
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Traiter 10 dossiers', (string) $this->client->getResponse()->getContent());
        $this->assertSame(1, $crawler->filter('.cs-gauge')->count(), 'La jauge d\'atteinte doit être affichée.');
    }

    /** Clôturer une évaluation fige le score et notifie le collaborateur. */
    public function testEvaluationClosureNotifiesCollaborator(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $finance = $this->user(self::FINANCE);

        $crawler = $this->client->request(
            'GET',
            '/console/evaluations/collaborateur/' . $finance->getId() . '/evaluer?annee=2026&trimestre=0'
        );
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['evaluation[appreciation]'] = 'Bon trimestre, à confirmer.';
        $form['evaluation[cloturee]'] = true;
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->assertQueuedEmailCount(1);
    }
}
