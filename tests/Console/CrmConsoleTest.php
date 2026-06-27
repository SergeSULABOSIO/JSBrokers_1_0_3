<?php

namespace App\Tests\Console;

use App\Crm\CrmHealthScoreService;
use App\Crm\CrmPipelineService;
use App\Crm\CrmSyncService;
use App\Entity\Crm\CrmProfil;
use App\Entity\Entreprise;
use App\Entity\TokenConsumption;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Module CRM (Console) : contrôle d'accès, rendu des pages, synchronisation
 * automatique du profil client (pipeline + score de santé) et actions
 * commerciales. Couvre aussi la logique pure des services (dérivation d'étape,
 * seuils de couleur du score). Aucune régression : tables crm_* additives.
 */
class CrmConsoleTest extends WebTestCase
{
    private const ADMIN  = 'phpunit-crm-admin@test.local';
    private const SUPER  = 'phpunit-crm-super@test.local';
    private const CLIENT = 'phpunit-crm-client@test.local';
    private const PLAIN  = 'phpunit-crm-plain@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $admin = (new Utilisateur())->setEmail(self::ADMIN)->setNom('Agent CRM')->setVerified(true)->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $em->persist($admin);

        $super = (new Utilisateur())->setEmail(self::SUPER)->setNom('Super CRM')->setVerified(true)->setRoles(['ROLE_SUPER_ADMIN']);
        $super->setPassword($hasher->hashPassword($super, self::PASSWORD));
        $em->persist($super);

        $plain = (new Utilisateur())->setEmail(self::PLAIN)->setNom('Utilisateur Lambda')->setVerified(true);
        $plain->setPassword($hasher->hashPassword($plain, self::PASSWORD));
        $em->persist($plain);

        // Client payant : 1 entreprise, 1 achat, consommation récente, connecté récemment.
        $cli = (new Utilisateur())->setEmail(self::CLIENT)->setNom('Client Test')->setVerified(true);
        $cli->setPassword($hasher->hashPassword($cli, self::PASSWORD));
        $cli->setPaidTokens(5000);
        $cli->registerLogin(new \DateTimeImmutable());
        $em->persist($cli);

        $ent = (new Entreprise())
            ->setNom('Courtage Test')->setLicence('LIC-1')->setAdresse('1 rue Test')
            ->setTelephone('0000')->setRccm('RCCM-1')->setIdnat('IDN-1')->setNumimpot('IMP-1')
            ->setUtilisateur($cli);
        $em->persist($ent);

        $achat = (new TokenPurchase())->setUtilisateur($cli)->setPack('intermediaire')
            ->setTokens(10000)->setMontantUsd(9.0)->setReference('REF-CRM-1');
        $em->persist($achat);

        $conso = (new TokenConsumption())
            ->setEntreprise($ent)->setProprietaire($cli)->setActeur($cli)
            ->setEntiteNom('Cotation')->setSens(TokenConsumption::SENS_ENTREE)
            ->setNombre(1)->setPoidsUnitaire(50)->setPoidsTotal(50);
        $em->persist($conso);

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
        $emails = "(SELECT id FROM utilisateur WHERE email IN ('" . self::ADMIN . "','" . self::SUPER . "','" . self::CLIENT . "','" . self::PLAIN . "'))";
        // Les invitations référencent utilisateur et entreprise (sans ON DELETE) :
        // on les retire en premier pour ne pas violer les contraintes.
        $conn->executeStatement(
            "DELETE FROM invite WHERE utilisateur_id IN $emails
             OR entreprise_id IN (SELECT e.id FROM (SELECT id FROM entreprise WHERE utilisateur_id IN $emails) e)"
        );
        // Le champ connectedTo (espace de travail actif) référence l'entreprise sans
        // ON DELETE : on le neutralise pour les comptes de test avant de retirer leurs
        // entreprises (sinon la suppression de l'entreprise viole la contrainte).
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::SUPER, self::CLIENT, self::PLAIN]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        // L'entreprise référence l'utilisateur sans ON DELETE : on la retire d'abord
        // (la consommation liée est supprimée en cascade). Le reste part avec l'utilisateur.
        $conn->executeStatement("DELETE FROM entreprise WHERE utilisateur_id IN $emails");
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::SUPER, self::CLIENT, self::PLAIN]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        // Réinitialise le singleton (dont l'horodatage du heartbeat) pour des
        // tests déterministes ; getSingleton() le recrée au besoin.
        $conn->executeStatement('DELETE FROM plateforme_parametres');
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testRegularUserForbidden(): void
    {
        $this->client->loginUser($this->user(self::PLAIN));
        $this->client->request('GET', '/console/crm');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminReachesCrmPages(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        foreach ([
            '/console/crm',
            '/console/crm/clients',
            '/console/crm/pipeline',
            '/console/crm/entreprises',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('La page %s doit répondre 200 pour un agent.', $url));
        }
    }

    public function testDashboardShowsOpenTicketsBlockWithSupportShortcut(): void
    {
        $client = $this->user(self::CLIENT);
        $ticket = (new \App\Entity\Crm\CrmTicket())
            ->setClient($client)
            ->setSujet('Aperçu tableau de bord')
            ->setPriorite(\App\Entity\Crm\CrmTicket::PRIORITE_NORMALE);
        $this->em()->persist($ticket);
        $this->em()->flush();

        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm');
        $this->assertResponseIsSuccessful();

        // Le ticket ouvert apparaît dans l'aperçu, avec un raccourci direct vers
        // l'onglet Support de la fiche client.
        $this->assertSame(
            1,
            $crawler->filter('a[href$="/console/crm/clients/' . $client->getId() . '#tab-support"]')->count(),
            'Le bloc Tickets doit proposer un raccourci vers l\'onglet Support du client.',
        );
        $this->assertStringContainsString('Aperçu tableau de bord', $crawler->html());
    }

    public function testClientFicheCreatesProfilAndDerivesStage(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $client = $this->user(self::CLIENT);

        $this->client->request('GET', '/console/crm/clients/' . $client->getId());
        $this->assertResponseIsSuccessful();

        // Le profil a été créé et synchronisé automatiquement à l'affichage.
        $this->em()->clear();
        $profil = $this->em()->getRepository(CrmProfil::class)->find($this->user(self::CLIENT));
        $this->assertNotNull($profil, 'Le profil CRM doit être créé automatiquement.');
        // 1 achat + activité récente → « Client actif ».
        $this->assertSame(CrmPipelineService::STAGE_ACTIF, $profil->getEtapePipeline());
        $this->assertGreaterThan(0, $profil->getScoreSante());
        $this->assertContains($profil->getScoreCouleur(), ['vert', 'jaune', 'orange', 'rouge']);
    }

    public function testQuickActionButtonsAreInsideTabsController(): void
    {
        // Régression : les actions rapides doivent être DANS la portée du
        // contrôleur Stimulus crm-tabs, sinon le clic ne bascule pas l'onglet.
        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/clients/' . $this->user(self::CLIENT)->getId());
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            1,
            $crawler->filter('[data-controller="crm-tabs"] button[data-crm-tabs-go-param="activites"]')->count(),
            'Le bouton « Logger un échange » doit être dans la portée du contrôleur crm-tabs.',
        );
        $this->assertSame(
            1,
            $crawler->filter('[data-controller="crm-tabs"] button[data-crm-tabs-go-param="taches"]')->count(),
            'Le bouton « Créer une tâche » doit être dans la portée du contrôleur crm-tabs.',
        );
    }

    public function testInlineFicheFieldsUseFieldPattern(): void
    {
        // Les mini-formulaires inline de la fiche restent inline, mais leurs champs
        // suivent le pattern champ-à-icône (.cs-field + .cs-field-icon) de la console.
        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/clients/' . $this->user(self::CLIENT)->getId());
        $this->assertResponseIsSuccessful();

        $this->assertGreaterThanOrEqual(
            1,
            $crawler->filter('form[action$="/interaction"] .cs-field .cs-field-icon svg')->count(),
            'Les champs du formulaire d\'interaction doivent utiliser .cs-field avec pastille d\'icône.',
        );
        $this->assertGreaterThanOrEqual(
            1,
            $crawler->filter('form[action$="/tache"] .cs-field .cs-field-icon svg')->count(),
            'Les champs du formulaire de tâche doivent utiliser .cs-field avec pastille d\'icône.',
        );
    }

    public function testClientProspectFilter(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        // CLIENT a un achat → « client » ; PLAIN n'en a aucun → « prospect ».
        $this->client->request('GET', '/console/crm/clients?type=client');
        $contenu = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::CLIENT, $contenu);
        $this->assertStringNotContainsString(self::PLAIN, $contenu);

        $this->client->request('GET', '/console/crm/clients?type=prospect');
        $contenu = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::PLAIN, $contenu);
        $this->assertStringNotContainsString(self::CLIENT, $contenu);
    }

    public function testPurchasingAgentIsIncludedAsClient(): void
    {
        // Exclusion des agents levée : un agent ayant acheté des tokens est aussi
        // client et doit apparaître dans le CRM (cas du compte admin-acheteur).
        $super = $this->user(self::SUPER);
        $achat = (new TokenPurchase())->setUtilisateur($super)->setPack('intermediaire')
            ->setTokens(10000)->setMontantUsd(9.0)->setReference('REF-CRM-SUPER');
        $this->em()->persist($achat);
        $this->em()->flush();

        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/crm/clients?type=client');
        $contenu = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::SUPER, $contenu, 'Un agent ayant acheté doit figurer parmi les clients du CRM.');
    }

    public function testForceStageViaPost(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $client = $this->user(self::CLIENT);

        $crawler = $this->client->request('GET', '/console/crm/clients/' . $client->getId());
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/etape"]')->form();
        $form['etape'] = CrmPipelineService::STAGE_QUALIFICATION;
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $profil = $this->em()->getRepository(CrmProfil::class)->find($this->user(self::CLIENT));
        $this->assertSame(CrmPipelineService::STAGE_QUALIFICATION, $profil->getEtapePipeline());
        $this->assertTrue($profil->isEtapeManuelleForcee(), 'Une étape relationnelle forcée doit être marquée comme telle.');
    }

    public function testCrmSecondaryPagesAccessible(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        foreach ([
            '/console/crm/customer-success',
            '/console/crm/tickets',
            '/console/crm/campagnes',
            '/console/crm/taches',
            '/console/crm/notifications',
            '/console/crm/cfo',
            '/console/crm/ceo',
            '/console/crm/rapports',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('La page %s doit répondre 200 pour un agent.', $url));
        }
    }

    public function testParametresCrmGating(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/crm/parametres');
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', '/console/crm/parametres');
        $this->assertResponseIsSuccessful();
    }

    public function testTicketCreationFromConsole(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $client = $this->user(self::CLIENT);

        $crawler = $this->client->request('GET', '/console/crm/tickets/new');
        $this->assertResponseIsSuccessful();
        // Formulaire Symfony (pattern Coupon) : champs préfixés crm_ticket[...].
        $form = $crawler->filter('form')->form();
        $form['crm_ticket[client]'] = (string) $client->getId();
        $form['crm_ticket[sujet]'] = 'Problème de connexion';
        $form['crm_ticket[priorite]'] = 'haute';
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $tickets = static::getContainer()->get(\App\Repository\Crm\CrmTicketRepository::class)->findForClient($this->user(self::CLIENT));
        $this->assertCount(1, $tickets);
        $this->assertSame('Problème de connexion', $tickets[0]->getSujet());
        $this->assertNotNull($tickets[0]->getSlaDueAt(), 'Le SLA doit être calculé à la création.');
    }

    public function testCrmEditFormsFollowConsolePattern(): void
    {
        // Tous les formulaires d'édition CRM doivent utiliser le shell partagé
        // (console/form.html.twig) comme la rubrique Coupon : carte .cs-form-card
        // + sections .cs-fieldset/.cs-legend + barre d'actions sticky.
        $this->client->loginUser($this->user(self::SUPER));

        foreach (['/console/crm/tickets/new', '/console/crm/campagnes/new', '/console/crm/parametres'] as $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('%s doit répondre 200.', $url));
            $this->assertGreaterThanOrEqual(
                1,
                $crawler->filter('.cs-form-card .cs-fieldset .cs-legend')->count(),
                sprintf('%s doit suivre le pattern Console (fieldsets à légende).', $url),
            );
            $this->assertSame(
                1,
                $crawler->filter('.cs-form-actions button[type="submit"]')->count(),
                sprintf('%s doit avoir la barre d\'actions sticky du shell.', $url),
            );
        }
    }

    public function testReportExportReturnsXlsx(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/crm/rapports/pipeline/export');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testCampaignSendEmailsTargetSegment(): void
    {
        // Synchronise le profil du client pour qu'il soit ciblable.
        $sync = static::getContainer()->get(CrmSyncService::class);
        $sync->refresh($this->user(self::CLIENT));

        $campagne = (new \App\Entity\Crm\CrmCampagne())
            ->setNom('Test onboarding')
            ->setType(\App\Entity\Crm\CrmCampagne::TYPE_ONBOARDING)
            ->setObjet('Bienvenue')
            ->setMessage("Merci de votre confiance.\nÀ très vite.")
            ->setSegmentRegles(['stages' => [], 'couleurs' => []]); // tous les clients
        $this->em()->persist($campagne);
        $this->em()->flush();

        $envois = static::getContainer()->get(\App\Crm\CrmCampagneService::class)->send($campagne);
        $this->assertGreaterThanOrEqual(1, $envois);
        $this->assertSame(\App\Entity\Crm\CrmCampagne::STATUT_ENVOYEE, $campagne->getStatut());
        $this->assertQueuedEmailCount($envois);
    }

    public function testAutomationEngineCreatesInactivityTask(): void
    {
        // Client inactif depuis 20 jours.
        $client = $this->user(self::CLIENT);
        $client->setLastLoginAt((new \DateTimeImmutable())->modify('-20 days'));
        $this->em()->flush();

        static::getContainer()->get(CrmSyncService::class)->refresh($client);
        $counts = static::getContainer()->get(\App\Crm\CrmAutomationEngine::class)->runScheduled();

        $this->assertGreaterThanOrEqual(1, $counts['inactivite']);

        $taches = static::getContainer()->get(\App\Repository\Crm\CrmTacheRepository::class)->findForClient($this->user(self::CLIENT));
        $this->assertNotEmpty($taches, 'Une tâche de relance doit être créée pour un client inactif.');
    }

    public function testHeartbeatRunsMaintenanceAfterConsoleVisit(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        // L'accès à une page CRM déclenche, après réponse (kernel.terminate), la
        // routine quotidienne si elle est due (ici : jamais exécutée → due).
        $this->client->request('GET', '/console/crm');
        $this->assertResponseIsSuccessful();

        $lastRun = $this->em()->getConnection()->fetchOne('SELECT crm_last_auto_run_at FROM plateforme_parametres');
        $this->assertNotEmpty($lastRun, 'Le heartbeat doit horodater l\'exécution après la visite.');

        // La routine a synchronisé les profils et capturé un snapshot du client.
        $snapshots = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM crm_health_snapshot s JOIN utilisateur u ON u.id = s.utilisateur_id WHERE u.email = :e',
            ['e' => self::CLIENT],
        );
        $this->assertGreaterThanOrEqual(1, $snapshots, 'Un snapshot de santé doit avoir été capturé.');
    }

    public function testHeartbeatIsThrottled(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/crm');
        $first = $this->em()->getConnection()->fetchOne('SELECT crm_last_auto_run_at FROM plateforme_parametres');

        // Deuxième visite immédiate : la fenêtre de 24 h n'est pas écoulée → pas de
        // nouvelle exécution (l'horodatage reste identique).
        $this->client->request('GET', '/console/crm');
        $second = $this->em()->getConnection()->fetchOne('SELECT crm_last_auto_run_at FROM plateforme_parametres');

        $this->assertSame($first, $second, 'La routine ne doit pas se réexécuter dans la fenêtre de throttle.');
    }

    public function testTacheDoneFromFicheRedirectsToClientTasksTab(): void
    {
        $client = $this->user(self::CLIENT);

        // Tâche ouverte rattachée au client.
        $tache = (new \App\Entity\Crm\CrmTache())
            ->setTitre('Relancer le client')
            ->setClient($client)
            ->setDueAt(new \DateTimeImmutable());
        $this->em()->persist($tache);
        $this->em()->flush();
        $id = $tache->getId();

        $this->client->loginUser($this->user(self::ADMIN));

        // On soumet le formulaire réellement rendu dans l'onglet Tâches de la fiche
        // (porte _retour=fiche + jeton CSRF) → retour sur la fiche, onglet Tâches.
        $crawler = $this->client->request('GET', '/console/crm/clients/' . $client->getId());
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/taches/' . $id . '/done"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects(
            '/console/crm/clients/' . $client->getId() . '#tab-taches',
        );

        $this->em()->clear();
        $this->assertSame(
            \App\Entity\Crm\CrmTache::STATUT_FAITE,
            $this->em()->getRepository(\App\Entity\Crm\CrmTache::class)->find($id)->getStatut(),
        );
    }

    public function testTacheDoneFromGlobalListRedirectsToIndex(): void
    {
        $tache = (new \App\Entity\Crm\CrmTache())
            ->setTitre('Tâche transverse')
            ->setClient($this->user(self::CLIENT))
            ->setDueAt(new \DateTimeImmutable());
        $this->em()->persist($tache);
        $this->em()->flush();
        $id = $tache->getId();

        $this->client->loginUser($this->user(self::ADMIN));

        // Formulaire de la liste globale (sans _retour) : redirection historique conservée.
        $crawler = $this->client->request('GET', '/console/crm/taches');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/taches/' . $id . '/done"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/crm/taches');
    }

    public function testTicketStatutFromFicheRedirectsToClientSupportTab(): void
    {
        $client = $this->user(self::CLIENT);

        $ticket = (new \App\Entity\Crm\CrmTicket())
            ->setClient($client)
            ->setSujet('Problème de connexion')
            ->setPriorite(\App\Entity\Crm\CrmTicket::PRIORITE_NORMALE);
        $this->em()->persist($ticket);
        $this->em()->flush();
        $id = $ticket->getId();

        $this->client->loginUser($this->user(self::ADMIN));

        // On soumet le select de statut réellement rendu dans l'onglet Support de la
        // fiche (porte _retour=fiche + jeton CSRF) → retour sur la fiche, onglet Support.
        $crawler = $this->client->request('GET', '/console/crm/clients/' . $client->getId());
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/tickets/' . $id . '/statut"]')->form();
        $form['statut'] = \App\Entity\Crm\CrmTicket::STATUT_RESOLU;
        $this->client->submit($form);
        $this->assertResponseRedirects(
            '/console/crm/clients/' . $client->getId() . '#tab-support',
        );

        $this->em()->clear();
        $this->assertSame(
            \App\Entity\Crm\CrmTicket::STATUT_RESOLU,
            $this->em()->getRepository(\App\Entity\Crm\CrmTicket::class)->find($id)->getStatut(),
        );
    }

    public function testTicketStatutFromGlobalListRedirectsToIndex(): void
    {
        $ticket = (new \App\Entity\Crm\CrmTicket())
            ->setClient($this->user(self::CLIENT))
            ->setSujet('Question facturation')
            ->setPriorite(\App\Entity\Crm\CrmTicket::PRIORITE_NORMALE);
        $this->em()->persist($ticket);
        $this->em()->flush();
        $id = $ticket->getId();

        $this->client->loginUser($this->user(self::ADMIN));

        // Select de la liste globale (sans _retour) : redirection historique conservée.
        $crawler = $this->client->request('GET', '/console/crm/tickets');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/tickets/' . $id . '/statut"]')->form();
        $form['statut'] = \App\Entity\Crm\CrmTicket::STATUT_EN_COURS;
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/crm/tickets');
    }

    public function testClientListFlagsAccountsThatAreAlsoGuests(): void
    {
        $client = $this->user(self::CLIENT);
        $ent = $this->em()->getRepository(Entreprise::class)->findOneBy(['utilisateur' => $client]);
        $plain = $this->user(self::PLAIN);

        // PLAIN est aussi invité comme collaborateur dans l'entreprise du client.
        $invite = (new \App\Entity\Invite())->setUtilisateur($plain)->setEntreprise($ent)->setNom('Collaborateur');
        $this->em()->persist($invite);
        $this->em()->flush();

        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/clients');
        $this->assertResponseIsSuccessful();

        // La ligne de PLAIN porte le badge « Invité » ; pas celle du client.
        $plainRow = $crawler->filter('tr')->reduce(
            fn ($node) => str_contains($node->text(), $plain->getEmail()),
        );
        $this->assertSame(1, $plainRow->count(), 'La ligne du compte invité doit exister.');
        $this->assertStringContainsString('Invité', $plainRow->text(), 'Le compte aussi invité doit être signalé.');

        $clientRow = $crawler->filter('tr')->reduce(
            fn ($node) => str_contains($node->text(), $client->getEmail()),
        );
        $this->assertStringNotContainsString('Invité', $clientRow->text(), 'Le client non invité ne doit pas porter le badge.');
    }

    public function testCampagneFormShowsSegmentSizeBadges(): void
    {
        // Profil synchronisé → le client compte dans un segment (étape + couleur).
        static::getContainer()->get(CrmSyncService::class)->refresh($this->user(self::CLIENT));

        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/campagnes/new');
        $this->assertResponseIsSuccessful();

        // Un badge de taille par segment : 9 étapes + 4 couleurs de santé.
        $badges = $crawler->filter('.crm-seg__badge');
        $this->assertGreaterThanOrEqual(13, $badges->count(), 'Chaque segment doit afficher un badge de taille.');

        // Au moins un client/prospect compté dans un segment (le client synchronisé).
        $total = 0;
        foreach ($badges as $b) {
            $total += (int) trim($b->textContent);
        }
        $this->assertGreaterThanOrEqual(1, $total, 'Au moins un client/prospect doit être compté dans un segment.');
    }

    public function testCampagneEditPrefillsSegmentAndUpdates(): void
    {
        $campagne = (new \App\Entity\Crm\CrmCampagne())
            ->setNom('Avant')
            ->setType(\App\Entity\Crm\CrmCampagne::TYPE_ONBOARDING)
            ->setObjet('Objet initial')
            ->setMessage('Message initial')
            ->setSegmentRegles(['stages' => ['prospect'], 'couleurs' => []]);
        $this->em()->persist($campagne);
        $this->em()->flush();
        $id = $campagne->getId();

        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/campagnes/' . $id . '/edit');
        $this->assertResponseIsSuccessful();

        // Pré-remplissage : la case « Prospect » est cochée.
        $this->assertNotNull(
            $crawler->filter('input[type=checkbox][value="prospect"]')->attr('checked'),
            'L\'étape enregistrée doit être pré-cochée à l\'édition.',
        );

        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $form['crm_campagne[nom]'] = 'Après';
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/crm/campagnes');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(\App\Entity\Crm\CrmCampagne::class)->find($id);
        $this->assertSame('Après', $reloaded->getNom());
        $this->assertContains('prospect', $reloaded->getSegmentRegles()['stages'], 'Le segment doit être conservé.');
    }

    public function testCampagneRelancerResendsSentCampaign(): void
    {
        static::getContainer()->get(CrmSyncService::class)->refresh($this->user(self::CLIENT));

        $campagne = (new \App\Entity\Crm\CrmCampagne())
            ->setNom('Déjà envoyée')
            ->setType(\App\Entity\Crm\CrmCampagne::TYPE_RECHARGE)
            ->setObjet('Recharge')
            ->setMessage('Pensez à recharger.')
            ->setSegmentRegles(['stages' => [], 'couleurs' => []]) // tous les clients
            ->setStatut(\App\Entity\Crm\CrmCampagne::STATUT_ENVOYEE)
            ->setSentAt(new \DateTimeImmutable('-1 day'));
        $this->em()->persist($campagne);
        $this->em()->flush();
        $id = $campagne->getId();

        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/campagnes');
        $this->assertResponseIsSuccessful();

        // Le bouton « Relancer » est présent pour une campagne déjà envoyée.
        $form = $crawler->filter('form[action$="/campagnes/' . $id . '/relancer"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/crm/campagnes');

        // La relance a recalculé les cibles (segment = tous → ≥ 1 client synchronisé).
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(\App\Entity\Crm\CrmCampagne::class)->find($id);
        $this->assertSame(\App\Entity\Crm\CrmCampagne::STATUT_ENVOYEE, $reloaded->getStatut());
        $this->assertGreaterThanOrEqual(1, $reloaded->getNbCibles(), 'La relance doit recibler le segment.');
    }

    public function testPipelineDerivationLogic(): void
    {
        /** @var CrmPipelineService $pipe */
        $pipe = static::getContainer()->get(CrmPipelineService::class);
        $now = new \DateTimeImmutable();

        $base = [
            'nbEntreprises' => 0, 'nbInvites' => 0, 'loginCount' => 0, 'lastActivityAt' => null,
            'nbPurchases' => 0, 'totalConsumption' => 0, 'score' => 0, 'daysSinceCreation' => 1,
        ];

        $this->assertSame(CrmPipelineService::STAGE_PROSPECT, $pipe->deriveAuto($base));
        $this->assertSame(CrmPipelineService::STAGE_CONTACT, $pipe->deriveAuto(['loginCount' => 2] + $base));
        $this->assertSame(CrmPipelineService::STAGE_ESSAI, $pipe->deriveAuto(['totalConsumption' => 30, 'loginCount' => 1] + $base));
        $this->assertSame(
            CrmPipelineService::STAGE_ACTIF,
            $pipe->deriveAuto(['nbPurchases' => 1, 'lastActivityAt' => $now] + $base),
        );
        $this->assertSame(
            CrmPipelineService::STAGE_FIDELE,
            $pipe->deriveAuto(['nbPurchases' => 3, 'lastActivityAt' => $now, 'score' => 50] + $base),
        );
        // Inactivité prolongée d'un compte engagé → churn.
        $this->assertSame(
            CrmPipelineService::STAGE_CHURN,
            $pipe->deriveAuto(['nbPurchases' => 1, 'loginCount' => 5, 'lastActivityAt' => $now->modify('-60 days')] + $base),
        );
    }

    public function testHealthScoreColorThresholds(): void
    {
        /** @var CrmHealthScoreService $health */
        $health = static::getContainer()->get(CrmHealthScoreService::class);

        $this->assertSame('vert', $health->color(80));
        $this->assertSame('jaune', $health->color(60));
        $this->assertSame('orange', $health->color(30));
        $this->assertSame('rouge', $health->color(10));

        // Un client inactif sans rien consommé doit être en mauvaise santé.
        $faible = $health->compute([
            'lastActivityAt' => null, 'consumption30' => 0, 'paidTokens' => 0,
            'nbEntreprises' => 0, 'nbInvites' => 0, 'distinctEntites' => 0,
            'nbPurchases' => 0, 'lastPurchaseAt' => null, 'openTickets' => 0,
        ]);
        $this->assertLessThan(25, $faible['score']);
        $this->assertSame('rouge', $faible['couleur']);
    }

    public function testSupportTicketOpenedFromBrokerWorkspace(): void
    {
        // Support self-service : le courtier (ROLE_USER) ouvre une demande depuis son
        // espace de travail ; elle crée un CrmTicket (canal « portail ») qui alimente
        // directement la file support de la console — sans saisie d'un agent.
        $client = $this->user(self::CLIENT);
        $ent = $this->em()->getRepository(Entreprise::class)->findOneBy(['utilisateur' => $client]);

        // Espace de travail actif du courtier : c'est l'entreprise depuis laquelle
        // la demande sera émise (et retenue sur le ticket).
        $client->setConnectedTo($ent);
        $this->em()->flush();

        $this->client->loginUser($client);

        $crawler = $this->client->request('GET', '/admin/support/workspace/' . $ent->getId());
        $this->assertResponseIsSuccessful('Le composant Support doit être accessible au courtier.');

        $form = $crawler->filter('form')->form();
        $form['support_demande[sujet]'] = 'Je n\'arrive pas à générer un bordereau';
        $form['support_demande[priorite]'] = \App\Entity\Crm\CrmTicket::PRIORITE_HAUTE;
        $form['support_demande[description]'] = 'Le bouton de génération reste grisé.';
        $this->client->submit($form);
        $this->assertResponseIsSuccessful('La soumission renvoie une réponse JSON (200).');

        // Réponse JSON pilotant le toast + le rafraîchissement du composant.
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($payload['success'] ?? false, 'La soumission doit réussir.');

        $tickets = static::getContainer()->get(\App\Repository\Crm\CrmTicketRepository::class)
            ->findForClient($this->user(self::CLIENT));
        $this->assertCount(1, $tickets, 'Un ticket doit être créé pour le courtier.');

        $ticket = $tickets[0];
        $this->assertSame(\App\Entity\Crm\CrmTicket::CANAL_PORTAIL, $ticket->getCanal(), 'Le canal doit être « portail ».');
        $this->assertSame(\App\Entity\Crm\CrmTicket::STATUT_OUVERT, $ticket->getStatut());
        $this->assertSame($client->getId(), $ticket->getClient()->getId(), 'Le client du ticket est le courtier connecté.');
        $this->assertNotNull($ticket->getSlaDueAt(), 'Le SLA doit être calculé à la création (priorité haute → 24 h).');
        $this->assertSame('Le bouton de génération reste grisé.', $ticket->getDescription(), 'Le message saisi doit être conservé.');
        $this->assertSame($ent->getId(), $ticket->getEntreprise()?->getId(), 'Le ticket doit retenir l\'entreprise émettrice (espace de travail actif).');

        // L'accusé de réception (toast) mentionne la référence ; la liste rafraîchie
        // contient le nouveau ticket avec son message.
        $this->assertStringContainsString($ticket->getReference(), (string) $payload['message']);
        $this->assertStringContainsString($ticket->getReference(), (string) $payload['html']);
        $this->assertStringContainsString('Le bouton de génération reste grisé.', (string) $payload['html'], 'La liste doit afficher le message du courtier.');

        // Côté console : l'agent voit le message ET l'entreprise émettrice (lien vers sa fiche).
        $this->client->loginUser($this->user(self::ADMIN));
        $crawler = $this->client->request('GET', '/console/crm/tickets');
        $this->assertResponseIsSuccessful();
        $consoleHtml = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Le bouton de génération reste grisé.', $consoleHtml, 'Le message du client doit être visible côté console.');
        $this->assertStringContainsString($ent->getNom(), $consoleHtml, 'L\'entreprise émettrice doit être visible côté console.');
        $this->assertSame(
            1,
            $crawler->filter('a[href$="/console/crm/entreprises/' . $ent->getId() . '"]')->count(),
            'L\'entreprise émettrice doit être un lien vers sa fiche.',
        );
    }
}
