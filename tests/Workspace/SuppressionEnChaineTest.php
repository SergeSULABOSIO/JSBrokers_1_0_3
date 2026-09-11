<?php

namespace App\Tests\Workspace;

use App\Entity\Article;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Service\Workspace\WorkspaceAccessResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SUPPRIMER UNE CHAÎNE ENTIÈRE, SANS EMPORTER CE QUI NE LUI APPARTIENT PAS.
 *
 * Supprimer une opportunité échouait : la base refusait de couper le lien d'une facture,
 * et l'écran renvoyait l'utilisateur à un travail manuel impossible à mener à bien. Ces
 * tests fixent le contrat du moteur qui l'a remplacé :
 *
 *  - la chaîne part EN ENTIER, jusqu'à la facture et son règlement (profondeur 4) ;
 *  - les objets TRANSVERSAUX (client, risque) ne bougent pas ;
 *  - la POLICE qu'un renouvellement fait évoluer SURVIT — c'est le défaut qui existait
 *    sur les 52 routes ordinaires, et que seul un contrôleur savait éviter ;
 *  - supprimer un avenant n'emporte ni sa proposition ni son affaire ;
 *  - une facture à cheval sur deux affaires est CONSERVÉE, amputée, et on le dit ;
 *  - un versement déjà effectué survit, détaché : l'argent est sorti ;
 *  - un invité sans aucun rôle ne peut pas effacer une ligne de facture.
 */
class SuppressionEnChaineTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-sec-owner@test.local';
    private const INVITE_EMAIL = 'phpunit-sec-invite@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit SuppressionEnChaine SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function nettoyer(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;
        $emails = [self::OWNER_EMAIL, self::INVITE_EMAIL];

        foreach ($emails as $email) {
            $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => $email]);
        }
        // Les deux sens du lien croisé Avenant ↔ Piste avant toute suppression.
        $conn->executeStatement('UPDATE avenant a JOIN entreprise e ON a.entreprise_id = e.id SET a.piste_de_renouvellement_id = NULL WHERE e.nom = :nom', ['nom' => $nom]);
        $conn->executeStatement('UPDATE piste p JOIN entreprise e ON p.entreprise_id = e.id SET p.avenant_de_base_id = NULL WHERE e.nom = :nom', ['nom' => $nom]);

        $tables = [
            'reversement_retro_agent', 'paiement', 'article', 'note', 'tranche',
            'revenu_pour_courtier', 'avenant', 'cotation', 'piste', 'risque', 'client', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                sprintf('DELETE t FROM `%s` t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom', $table),
                ['nom' => $nom],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => $nom]);
        foreach ($emails as $email) {
            $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => $email]);
        }
    }

    // ───────────────────────────── Jeux de données ────────────────────────────

    /** @return array{entreprise: Entreprise, invite: Invite, client: Client, risque: Risque} */
    private function cabinet(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $utilisateur = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit SEC Owner');
        $utilisateur->setVerified(true);
        $utilisateur->setPassword($hasher->hashPassword($utilisateur, self::PASSWORD));
        $em->persist($utilisateur);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue du Test')
            ->setTelephone('+243000000000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($utilisateur);
        $utilisateur->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur');
        $invite->setUtilisateur($utilisateur);
        $invite->setEntreprise($entreprise);
        $invite->setProprietaire(true);
        $em->persist($invite);

        $client = (new Client())->setNom('PHPUNIT-SEC-CLIENT')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $risque = (new Risque())->setNomComplet('Risque SEC')->setCode('SEC-RQ')
            ->setDescription('Risque de test')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $risque->setInvite($invite);
        $em->persist($risque);

        $em->flush();

        return ['entreprise' => $entreprise, 'invite' => $invite, 'client' => $client, 'risque' => $risque];
    }

    /** Un collaborateur SANS AUCUN RÔLE, connecté au même cabinet. */
    private function inviteSansRole(array $cabinet): Invite
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $utilisateur = (new Utilisateur())->setEmail(self::INVITE_EMAIL)->setNom('PHPUnit SEC Invité');
        $utilisateur->setVerified(true);
        $utilisateur->setPassword($hasher->hashPassword($utilisateur, self::PASSWORD));
        $utilisateur->setConnectedTo($cabinet['entreprise']);
        $em->persist($utilisateur);

        $invite = (new Invite())->setNom('Collaborateur sans droits');
        $invite->setUtilisateur($utilisateur);
        $invite->setEntreprise($cabinet['entreprise']);
        $invite->setProprietaire(false);
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function piste(array $cabinet, string $nom, int $type = Piste::AVENANT_SOUSCRIPTION): Piste
    {
        $piste = (new Piste())->setNom($nom)->setClient($cabinet['client'])->setRisque($cabinet['risque'])
            ->setTypeAvenant($type)->setDescriptionDuRisque('Description ' . $nom)->setExercice(2026);
        $piste->setEntreprise($cabinet['entreprise']);
        $piste->setInvite($cabinet['invite']);
        $this->em()->persist($piste);

        return $piste;
    }

    private function cotation(array $cabinet, Piste $piste, string $nom): Cotation
    {
        $cotation = (new Cotation())->setNom($nom)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($cabinet['entreprise']);
        $cotation->setInvite($cabinet['invite']);
        $this->em()->persist($cotation);

        return $cotation;
    }

    private function avenant(array $cabinet, Cotation $cotation, string $reference): Avenant
    {
        $avenant = (new Avenant())->setReferencePolice($reference)->setDescription('Police ' . $reference)
            ->setStartingAt(new DateTimeImmutable('2026-01-01'))
            ->setEndingAt(new DateTimeImmutable('2026-12-31'))
            ->setCotation($cotation);
        $avenant->setEntreprise($cabinet['entreprise']);
        $avenant->setInvite($cabinet['invite']);
        $this->em()->persist($avenant);

        return $avenant;
    }

    private function tranche(array $cabinet, Cotation $cotation, string $nom): Tranche
    {
        $tranche = (new Tranche())->setNom($nom);
        $tranche->setPayableAt(new DateTimeImmutable('2026-03-01'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($cabinet['entreprise']);
        $tranche->setInvite($cabinet['invite']);
        $this->em()->persist($tranche);

        return $tranche;
    }

    private function note(array $cabinet, string $reference): Note
    {
        $note = (new Note())->setNom('Note ' . $reference)->setReference($reference)
            ->setType(0)->setAddressedTo(0)->setValidated(true)->setSignature('PHPUnit');
        $note->setEntreprise($cabinet['entreprise']);
        $note->setInvite($cabinet['invite']);
        $this->em()->persist($note);

        return $note;
    }

    private function article(array $cabinet, Note $note, Tranche $tranche, ?RevenuPourCourtier $revenu = null): Article
    {
        $article = (new Article())->setQuantite(1.0);
        $article->setNote($note);
        $article->setTranche($tranche);
        if ($revenu !== null) {
            $article->setRevenuFacture($revenu);
        }
        $article->setEntreprise($cabinet['entreprise']);
        $article->setInvite($cabinet['invite']);
        $this->em()->persist($article);

        return $article;
    }

    /**
     * L'opportunité complète, telle que la reprise de données la produit : proposition,
     * police, échéance, commission, facture et son règlement.
     *
     * @return array{piste: Piste, cotation: Cotation, avenant: Avenant, tranche: Tranche, revenu: RevenuPourCourtier, note: Note, article: Article, paiement: Paiement}
     */
    private function dossierComplet(array $cabinet, string $suffixe): array
    {
        $em = $this->em();
        $piste = $this->piste($cabinet, 'Affaire ' . $suffixe);
        $cotation = $this->cotation($cabinet, $piste, 'Proposition ' . $suffixe);
        $avenant = $this->avenant($cabinet, $cotation, 'POL-SEC-' . $suffixe);
        $tranche = $this->tranche($cabinet, $cotation, '1re échéance ' . $suffixe);

        $revenu = (new RevenuPourCourtier())->setNom('Commission ' . $suffixe);
        $revenu->setCotation($cotation);
        $revenu->setEntreprise($cabinet['entreprise']);
        $revenu->setInvite($cabinet['invite']);
        $em->persist($revenu);

        $note = $this->note($cabinet, 'ND-SEC-' . $suffixe);
        $article = $this->article($cabinet, $note, $tranche, $revenu);

        $paiement = (new Paiement())->setMontant(1000.0)->setPaidAt(new DateTimeImmutable('2026-04-01'));
        $paiement->setNote($note);
        $paiement->setEntreprise($cabinet['entreprise']);
        $paiement->setInvite($cabinet['invite']);
        $em->persist($paiement);

        $em->flush();

        return compact('piste', 'cotation', 'avenant', 'tranche', 'revenu', 'note', 'article', 'paiement');
    }

    private function supprimer(string $rubrique, int $id): array
    {
        $this->client->request('DELETE', sprintf('/admin/%s/api/delete/%d', $rubrique, $id));
        $reponse = $this->client->getResponse();

        return [
            'statut' => $reponse->getStatusCode(),
            'corps'  => json_decode((string) $reponse->getContent(), true) ?? [],
        ];
    }

    private function existe(string $classe, ?int $id): bool
    {
        if ($id === null) {
            return false;
        }
        $this->em()->clear();

        return $this->em()->find($classe, $id) !== null;
    }

    // ──────────────────────────────── Les tests ───────────────────────────────

    /**
     * LA POLICE SURVIT À LA SUPPRESSION DE SON RENOUVELLEMENT.
     *
     * `Piste::avenantDeBase` est en `cascade: ['remove']`, donc effacer l'opportunité de
     * renouvellement programmait la destruction de la POLICE qu'elle fait évoluer — avec
     * ses propositions, ses échéanciers et ses paiements. Un seul contrôleur savait
     * l'éviter ; les 52 routes ordinaires, non. Le test précédent ne vérifiait que
     * l'ANNONCE, jamais l'effet : c'est exactement par là que le défaut est passé.
     */
    public function testLaPoliceSurvitALaSuppressionDeSonRenouvellement(): void
    {
        $cabinet = $this->cabinet();
        $base = $this->dossierComplet($cabinet, 'BASE');
        $derivee = $this->piste($cabinet, 'Renouvellement', Piste::AVENANT_RENOUVELLEMENT);
        $derivee->setAvenantDeBase($base['avenant']);
        $base['avenant']->setPisteDeRenouvellement($derivee);
        $this->em()->flush();

        $ids = [
            'derivee'  => $derivee->getId(),
            'avenant'  => $base['avenant']->getId(),
            'cotation' => $base['cotation']->getId(),
            'piste'    => $base['piste']->getId(),
            'tranche'  => $base['tranche']->getId(),
            'note'     => $base['note']->getId(),
        ];

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $reponse = $this->supprimer('piste', $ids['derivee']);

        $this->assertSame(200, $reponse['statut'], 'La suppression du renouvellement doit aboutir.');
        $this->assertFalse($this->existe(Piste::class, $ids['derivee']), "L'opportunité de renouvellement est bien partie.");
        $this->assertTrue($this->existe(Avenant::class, $ids['avenant']), 'LA POLICE DE BASE SURVIT.');
        $this->assertTrue($this->existe(Cotation::class, $ids['cotation']), 'Et la proposition qui la porte.');
        $this->assertTrue($this->existe(Piste::class, $ids['piste']), "Et l'affaire d'origine.");
        $this->assertTrue($this->existe(Tranche::class, $ids['tranche']), 'Et son échéancier.');
        $this->assertTrue($this->existe(Note::class, $ids['note']), 'Et sa facture.');
    }

    /**
     * UN INVITÉ SANS AUCUN RÔLE NE SUPPRIME PAS UNE FACTURE.
     *
     * `Article` avait une route `api.delete` mais ne figurait dans aucune carte de
     * gouvernance : `can()` retombait sur son repli permissif, prévu pour les nœuds
     * atteints à travers le formulaire d'un parent DÉJÀ contrôlé. Une route dédiée ne
     * passe par aucun parent.
     */
    public function testUnInviteSansRoleNePeutPasSupprimerUneLigneDeFacture(): void
    {
        $cabinet = $this->cabinet();
        $dossier = $this->dossierComplet($cabinet, 'DROITS');
        $this->inviteSansRole($cabinet);
        $idArticle = $dossier['article']->getId();

        $this->client->loginUser($this->user(self::INVITE_EMAIL));
        $reponse = $this->supprimer('article', $idArticle);

        $this->assertSame(403, $reponse['statut'], 'Sans droit sur les factures, la suppression est refusée.');
        $this->assertTrue($this->existe(Article::class, $idArticle), 'Et la ligne de facture est toujours là.');
    }

    /**
     * AUCUNE ROUTE DE SUPPRESSION SANS DROIT — et c'est le ROUTEUR qui le dit.
     *
     * Quatre entités avaient une route de suppression et ne figuraient dans aucune carte
     * de gouvernance. Corriger les quatre ne suffit pas : rien n'empêcherait la
     * cinquantième-troisième route de rouvrir le trou, et personne ne s'en apercevrait
     * avant qu'un invité n'efface une facture. On énumère donc les routes réellement
     * publiées, et on exige que chacune soit gouvernée.
     */
    public function testToutesLesRoutesDeSuppressionSontGouvernees(): void
    {
        $routeur = static::getContainer()->get('router');
        $acces = static::getContainer()->get(WorkspaceAccessResolver::class);

        $orphelines = [];
        foreach ($routeur->getRouteCollection() as $nom => $route) {
            if (!str_starts_with($nom, 'admin.') || !str_contains($nom, '.api.delete')) {
                continue;
            }
            $controleur = (string) ($route->getDefaults()['_controller'] ?? '');
            $classe = explode('::', $controleur)[0];
            if (!class_exists($classe)) {
                continue;
            }
            $reflexion = new \ReflectionClass($classe);
            // Seules les routes qui passent par le socle partagé sont concernées : c'est
            // lui qui interroge la carte d'accès.
            if (!in_array(ControllerUtilsTrait::class, $reflexion->getTraitNames(), true)) {
                continue;
            }

            $entite = str_replace('Controller', '', $reflexion->getShortName());
            if (!$acces->estGouvernee($entite)) {
                $orphelines[] = sprintf('%s (route %s)', $entite, $nom);
            }
        }

        $this->assertSame([], $orphelines, sprintf(
            "Ces routes de suppression ne sont gouvernées par aucun droit : %s. Rattachez "
            . "l'entité à son parent dans WorkspaceAccessResolver::GOUVERNANCE_PARENT, ou "
            . "donnez-lui sa rubrique dans MAP.",
            implode(', ', $orphelines),
        ));
    }

    /**
     * LE DOSSIER PART EN ENTIER, JUSQU'À SA FACTURE — et pas plus loin.
     *
     * C'est la demande d'origine : « quand on supprime une piste, toute sa chaîne de
     * liaisons DOIT partir, SANS toucher aux paramètres et aux autres objets
     * transversaux ».
     */
    public function testLeDossierPartEnEntierSansToucherAuxTransversaux(): void
    {
        $cabinet = $this->cabinet();
        $dossier = $this->dossierComplet($cabinet, 'CHAINE');
        $ids = array_map(static fn ($o) => $o->getId(), $dossier);
        $idClient = $cabinet['client']->getId();
        $idRisque = $cabinet['risque']->getId();

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $reponse = $this->supprimer('piste', $ids['piste']);

        $this->assertSame(200, $reponse['statut'], (string) ($reponse['corps']['message'] ?? ''));
        foreach (
            [
                'piste' => Piste::class, 'cotation' => Cotation::class, 'avenant' => Avenant::class,
                'tranche' => Tranche::class, 'revenu' => RevenuPourCourtier::class,
                'article' => Article::class, 'note' => Note::class, 'paiement' => Paiement::class,
            ] as $cle => $classe
        ) {
            $this->assertFalse($this->existe($classe, $ids[$cle]), sprintf('« %s » devait partir avec le dossier.', $cle));
        }

        $this->assertTrue($this->existe(Client::class, $idClient), 'Le client est transversal : il reste.');
        $this->assertTrue($this->existe(Risque::class, $idRisque), 'Le risque aussi.');
        $this->assertStringContainsString('lié', (string) ($reponse['corps']['message'] ?? ''), 'Le compte rendu dit ce qui est parti avec.');
    }

    /**
     * LA PORTÉE EST ANNONCÉE AVANT D'ÊTRE EXÉCUTÉE — et par le MÊME calcul.
     *
     * La boîte de confirmation disait « 1 élément ». Elle dit désormais ce qui part avec,
     * parce qu'on ne peut pas demander de valider ce qu'on cache. Et c'est le plan
     * réellement exécuté qui l'annonce : deux calculs auraient fini par diverger.
     */
    public function testLaPorteeEstAnnonceeAvantDeConfirmer(): void
    {
        $cabinet = $this->cabinet();
        $dossier = $this->dossierComplet($cabinet, 'APERCU');
        $idPiste = $dossier['piste']->getId();

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', '/admin/suppression/apercu/piste/' . $idPiste);

        $this->assertResponseIsSuccessful();
        $apercu = json_decode((string) $this->client->getResponse()->getContent(), true);
        $parNature = array_column($apercu['portee'] ?? [], 'count', 'entite');

        $this->assertSame([], $apercu['refus'] ?? ['non vide'], 'Rien ne bloque ce dossier.');
        $this->assertGreaterThan(1, (int) ($apercu['total'] ?? 0), 'La portée dépasse la seule opportunité.');
        $this->assertSame(1, $parNature['Note'] ?? 0, 'La facture est annoncée AVANT la confirmation.');
        $this->assertSame(1, $parNature['Paiement'] ?? 0, 'Son règlement aussi.');
        $this->assertArrayNotHasKey('Client', $parNature, 'Le client est transversal : il n\'est pas annoncé.');

        // ET L'APERÇU N'EST PAS UNE PORTE DÉROBÉE : il exige le droit de suppression.
        $this->inviteSansRole($cabinet);
        $this->client->loginUser($this->user(self::INVITE_EMAIL));
        $this->client->request('GET', '/admin/suppression/apercu/piste/' . $idPiste);
        $this->assertResponseStatusCodeSame(403, 'Sans droit, on n\'apprend rien de ce dossier.');
    }

    /** SUPPRIMER UN AVENANT N'EMPORTE NI SA PROPOSITION NI SON AFFAIRE. */
    public function testSupprimerUnAvenantNEmportePasSonDossier(): void
    {
        $cabinet = $this->cabinet();
        $dossier = $this->dossierComplet($cabinet, 'AVENANT');
        $ids = array_map(static fn ($o) => $o->getId(), $dossier);

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $reponse = $this->supprimer('avenant', $ids['avenant']);

        $this->assertSame(200, $reponse['statut'], (string) ($reponse['corps']['message'] ?? ''));
        $this->assertFalse($this->existe(Avenant::class, $ids['avenant']), "L'avenant est parti.");
        $this->assertTrue($this->existe(Cotation::class, $ids['cotation']), 'SA PROPOSITION SURVIT.');
        $this->assertTrue($this->existe(Piste::class, $ids['piste']), 'SON AFFAIRE AUSSI.');
        $this->assertTrue($this->existe(Tranche::class, $ids['tranche']), 'Ainsi que son échéancier.');
        $this->assertTrue($this->existe(Note::class, $ids['note']), 'Et sa facture.');
    }

    /**
     * LA FACTURE À CHEVAL EST CONSERVÉE, AMPUTÉE, ET ON LE DIT.
     *
     * Une note groupe des lignes qui peuvent relever de plusieurs affaires. Détruire une
     * pièce comptable qui concerne des dossiers qu'on n'a pas demandé de supprimer serait
     * la faute exacte que ce moteur existe pour éviter. On ne la détruit donc qu'au
     * départ du DERNIER dossier qu'elle couvre.
     */
    public function testLaFactureAChevalSurDeuxAffairesEstConserveePuisDetruite(): void
    {
        $cabinet = $this->cabinet();
        $em = $this->em();

        $pisteA = $this->piste($cabinet, 'Affaire A');
        $cotationA = $this->cotation($cabinet, $pisteA, 'Proposition A');
        $trancheA = $this->tranche($cabinet, $cotationA, 'Échéance A');

        $pisteB = $this->piste($cabinet, 'Affaire B');
        $cotationB = $this->cotation($cabinet, $pisteB, 'Proposition B');
        $trancheB = $this->tranche($cabinet, $cotationB, 'Échéance B');

        $note = $this->note($cabinet, 'ND-SEC-GROUPEE');
        $ligneA = $this->article($cabinet, $note, $trancheA);
        $ligneB = $this->article($cabinet, $note, $trancheB);
        $em->flush();

        $ids = [
            'pisteA' => $pisteA->getId(), 'pisteB' => $pisteB->getId(),
            'note' => $note->getId(), 'ligneA' => $ligneA->getId(), 'ligneB' => $ligneB->getId(),
        ];

        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Départ de la PREMIÈRE affaire : la note survit, amputée d'une ligne.
        $premier = $this->supprimer('piste', $ids['pisteA']);
        $this->assertSame(200, $premier['statut'], (string) ($premier['corps']['message'] ?? ''));
        $this->assertFalse($this->existe(Article::class, $ids['ligneA']), 'Sa ligne part.');
        $this->assertTrue($this->existe(Note::class, $ids['note']), 'MAIS LA FACTURE RESTE.');
        $this->assertTrue($this->existe(Article::class, $ids['ligneB']), "Et la ligne de l'autre affaire aussi.");
        $this->assertStringContainsString('ND-SEC-GROUPEE', (string) ($premier['corps']['message'] ?? ''), 'Le rapport NOMME la facture conservée.');
        $this->assertStringContainsString('conservée', (string) ($premier['corps']['message'] ?? ''), 'Et dit qu\'elle est conservée.');

        // Départ de la SECONDE : plus aucune ligne ne survit, la facture part.
        $second = $this->supprimer('piste', $ids['pisteB']);
        $this->assertSame(200, $second['statut'], (string) ($second['corps']['message'] ?? ''));
        $this->assertFalse($this->existe(Article::class, $ids['ligneB']), 'La dernière ligne part.');
        $this->assertFalse($this->existe(Note::class, $ids['note']), 'ET LA FACTURE AVEC.');
    }

    /**
     * UN VERSEMENT DÉJÀ EFFECTUÉ SURVIT, DÉTACHÉ.
     *
     * Une rétrocommission versée est un décaissement RÉEL : l'argent est sorti, la pièce
     * est en comptabilité. Supprimer l'affaire qu'elle solde ne la rend pas fictive. Ce
     * test garantit aussi qu'on n'a pas « réglé » le blocage en ajoutant une cascade.
     */
    public function testLeVersementDejaEffectueSurvitDetache(): void
    {
        $cabinet = $this->cabinet();
        $dossier = $this->dossierComplet($cabinet, 'RETRO');

        $versement = (new ReversementRetroAgent())->setMontant(250.0)
            ->setPaidAt(new DateTimeImmutable('2026-05-01'))->setReference('RETRO-SEC-1');
        $versement->setAvenant($dossier['avenant']);
        $versement->setTranche($dossier['tranche']);
        $versement->setEntreprise($cabinet['entreprise']);
        $versement->setInvite($cabinet['invite']);
        $this->em()->persist($versement);
        $this->em()->flush();

        $idVersement = $versement->getId();
        $idPiste = $dossier['piste']->getId();

        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $reponse = $this->supprimer('piste', $idPiste);

        $this->assertSame(200, $reponse['statut'], (string) ($reponse['corps']['message'] ?? ''));
        $this->assertFalse($this->existe(Piste::class, $idPiste), "L'affaire est partie.");

        $this->em()->clear();
        $survivant = $this->em()->find(ReversementRetroAgent::class, $idVersement);
        $this->assertNotNull($survivant, 'LE VERSEMENT SURVIT : l\'argent est réellement sorti.');
        $this->assertNull($survivant->getAvenant(), 'Son lien vers la police est coupé, pas pendant.');
        $this->assertNull($survivant->getTranche(), "Et celui vers l'échéance aussi.");
        $this->assertSame(250.0, $survivant->getMontant(), 'Le montant versé est intact.');
    }
}
