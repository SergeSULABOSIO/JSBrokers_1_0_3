<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnMarketing;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\Search\TacheVisibiliteScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA FRONTIÈRE DES TÂCHES, PORTE PAR PORTE (décisions de Serge du 2026-10-21).
 *
 * Le cabinet : un propriétaire, un délégué, MOI, mon RESPONSABLE, mon ASSISTANT et un COLLÈGUE
 * sans lien. Chaque tâche porte un marqueur ; on vérifie, à chaque porte (liste, fiche ouverte
 * par lien, clôture, suppression, onglet et pastille d'une piste, tableau de bord, retours,
 * documents), que MOI vois exactement ce qui me concerne — et les administrateurs, tout.
 */
final class TacheVisibiliteFrontiereTest extends WebTestCase
{
    private const ENT = 'PHPUNIT Frontière des tâches';
    private const COMPTES = [
        'proprio' => 'phpunit-tv-proprio@test.local',
        'delegue' => 'phpunit-tv-delegue@test.local',
        'moi' => 'phpunit-tv-moi@test.local',
        'responsable' => 'phpunit-tv-responsable@test.local',
        'assistant' => 'phpunit-tv-assistant@test.local',
        'collegue' => 'phpunit-tv-collegue@test.local',
    ];

    private KernelBrowser $navigateur;

    protected function setUp(): void
    {
        $this->navigateur = static::createClient();
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

    private function nettoyer(): void
    {
        $conn = $this->em()->getConnection();
        foreach (self::COMPTES as $email) {
            $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => $email]);
        }
        $conn->executeStatement('UPDATE invite i JOIN entreprise e ON i.entreprise_id = e.id SET i.manager_id = NULL WHERE e.nom = :n', ['n' => self::ENT]);
        foreach (['document', 'demande_conge', 'classeur', 'feedback', 'tache', 'cotation', 'piste', 'client', 'roles_en_marketing', 'roles_en_administration'] as $table) {
            $jointure = \in_array($table, ['roles_en_marketing', 'roles_en_administration'], true)
                ? "DELETE t FROM {$table} t JOIN invite i ON t.invite_id = i.id JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n"
                : "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n";
            $conn->executeStatement($jointure, ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        foreach (self::COMPTES as $email) {
            $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => $email]);
        }
        $this->em()->clear();
    }

    /** @return array{e: Entreprise, i: array<string, Invite>, t: array<string, Tache>, piste: Piste, f: array<string, Feedback>, d: array<string, Document>} */
    private function semer(): array
    {
        $em = $this->em();
        $utilisateurs = [];
        foreach (self::COMPTES as $cle => $email) {
            $u = (new Utilisateur())->setEmail($email)->setNom($cle)->setVerified(true)->setPassword('x');
            $em->persist($u);
            $utilisateurs[$cle] = $u;
        }
        $e = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('A')->setTelephone('T')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($utilisateurs['proprio']);
        $em->persist($e);

        $i = [];
        foreach ($utilisateurs as $cle => $u) {
            $u->setConnectedTo($e);
            $invite = (new Invite())->setNom($cle)->setUtilisateur($u)->setEntreprise($e)->setProprietaire($cle === 'proprio');
            if ($cle === 'delegue') {
                $invite->setGestionnaireInvites(true);
            }
            if ($cle !== 'proprio') {
                $m = (new RolesEnMarketing())->setNom('Tâches');
                $m->setAccessTache([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION, Invite::ACCESS_SUPPRESSION]);
                $m->setAccessFeedback([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION]);
                $m->setAccessPiste([Invite::ACCESS_LECTURE]);
                $m->setEntreprise($e);
                $invite->addRolesEnMarketing($m);
                $em->persist($m);
                $a = (new RolesEnAdministration())->setNom('Documents');
                $a->setAccessDocument([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
                $a->setEntreprise($e);
                $invite->addRolesEnAdministration($a);
                $em->persist($a);
            }
            $em->persist($invite);
            $i[$cle] = $invite;
        }
        $i['responsable']->addAssistant($i['moi']);
        $i['moi']->addAssistant($i['assistant']);

        $client = (new Client())->setNom('TVF client')->setExonere(false);
        $client->setEntreprise($e);
        $em->persist($client);
        $piste = (new Piste())->setNom('TVF piste')->setClient($client)->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('r')->setExercice(2026);
        $piste->setEntreprise($e);
        $em->persist($piste);

        $t = [];
        foreach ([
            'assigneeAMoi' => ['moi', 'collegue'],
            'creeeParMoi' => ['collegue', 'moi'],
            'duResponsable' => ['responsable', 'collegue'],
            'creeeParResponsable' => ['collegue', 'responsable'],
            'deLAssistant' => ['assistant', 'collegue'],
            'creeeParAssistant' => ['collegue', 'assistant'],
            'sansExecutant' => [null, 'collegue'],
            'duCollegue' => ['collegue', 'collegue'],
            'automatiqueAutre' => ['collegue', null],
        ] as $cle => [$executant, $auteur]) {
            $tache = (new Tache())->setDescription('TVF-' . $cle)->setClosed(false)->setToBeEndedAt(new \DateTimeImmutable('+3 days'))
                ->setExecutor($executant ? $i[$executant] : null)->setPiste($piste);
            $tache->setEntreprise($e);
            $tache->setInvite($auteur ? $i[$auteur] : null);
            $em->persist($tache);
            $t[$cle] = $tache;
        }

        $f = [];
        foreach (['visible' => 'assigneeAMoi', 'invisible' => 'duCollegue'] as $cle => $tache) {
            $feedback = (new Feedback())->setDescription('TVF-retour-' . $cle)->setType(Feedback::TYPE_CALL)->setTache($t[$tache]);
            $feedback->setEntreprise($e);
            $em->persist($feedback);
            $f[$cle] = $feedback;
        }

        $d = [];
        foreach (['seulSurInvisible' => [$t['duCollegue'], null], 'invisiblePlusClient' => [$t['duCollegue'], $client]] as $cle => [$tache, $autre]) {
            $document = (new Document())->setNom('TVF-doc-' . $cle)->setTache($tache);
            if ($autre) {
                $document->setClient($autre);
            }
            $document->setEntreprise($e);
            $em->persist($document);
            $d[$cle] = $document;
        }
        $em->flush();
        // Les requêtes relisent tout de la base : rien d'un état en mémoire (la collection
        // `taches` de la piste n'a pas été tenue à jour par setPiste()).
        $em->clear();

        return ['e' => $e, 'i' => $i, 't' => $t, 'piste' => $piste, 'f' => $f, 'd' => $d];
    }

    private function connecter(string $cle): void
    {
        $this->navigateur->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::COMPTES[$cle]]));
    }

    private function liste(string $racine, Invite $invite, Entreprise $e): string
    {
        $this->navigateur->request('POST', sprintf('/admin/%s/api/dynamic-query/%d/%d', $racine, $invite->getId(), $e->getId()), [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [], 'parentContext' => null, 'page' => 1]));
        self::assertResponseIsSuccessful();

        return (string) (json_decode((string) $this->navigateur->getResponse()->getContent(), true)['html'] ?? '');
    }

    /** @return list<string> les marqueurs de tâches présents */
    private static function taches(string $html): array
    {
        preg_match_all('/TVF-([a-zA-Z]+)\b/', $html, $m);
        $vus = array_values(array_unique(array_filter($m[1], static fn (string $c): bool => !str_starts_with($c, 'retour') && !str_starts_with($c, 'doc'))));
        sort($vus);

        return $vus;
    }

    private const VISIBLES_POUR_MOI = ['assigneeAMoi', 'creeeParAssistant', 'creeeParMoi', 'creeeParResponsable', 'deLAssistant', 'duResponsable', 'sansExecutant'];

    public function testLaListeNeMontreQueCeQuiMeConcerne(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        self::assertSame(self::VISIBLES_POUR_MOI, self::taches($this->liste('tache', $s['i']['moi'], $s['e'])), 'recherche');

        // Premier affichage : « Mon portefeuille » est posé par défaut (et MOI n'en gère aucun) ;
        // la frontière, elle, vaut AUSSI ici — aucune tâche hors périmètre dans la page.
        $this->navigateur->request('GET', sprintf('/admin/tache/index/%d/%d', $s['i']['moi']->getId(), $s['e']->getId()));
        self::assertResponseIsSuccessful();
        $page = (string) $this->navigateur->getResponse()->getContent();
        self::assertStringNotContainsString('TVF-duCollegue', $page);
        self::assertStringNotContainsString('TVF-automatiqueAutre', $page);
    }

    public function testLesAdministrateursVoientTout(): void
    {
        $s = $this->semer();
        foreach (['proprio', 'delegue'] as $admin) {
            $this->connecter($admin);
            self::assertCount(9, self::taches($this->liste('tache', $s['i'][$admin], $s['e'])), $admin);
        }
    }

    public function testUneFicheHorsPerimetreOuverteParLienEstIntrouvable(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        foreach (['duCollegue', 'automatiqueAutre'] as $cle) {
            $id = $s['t'][$cle]->getId();
            $this->navigateur->request('GET', sprintf('/admin/tache/api/get-form/%d?consultation=1&idEntreprise=%d&idInvite=%d', $id, $s['e']->getId(), $s['i']['moi']->getId()));
            self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), "fiche {$cle}");
            $this->navigateur->request('POST', sprintf('/admin/tache/api/close/%d', $id));
            self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), "clôture {$cle}");
            $this->navigateur->request('DELETE', sprintf('/admin/tache/api/delete/%d', $id));
            self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), "suppression {$cle}");
            // Ses onglets non plus : une liste vide en 200 confirmerait que la tâche existe.
            $this->navigateur->request('GET', sprintf('/admin/tache/api/%d/feedbacks/generic', $id));
            self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), "retours {$cle}");
        }
        $this->navigateur->request('GET', sprintf('/admin/tache/api/get-form/%d?consultation=1&idEntreprise=%d&idInvite=%d', $s['t']['assigneeAMoi']->getId(), $s['e']->getId(), $s['i']['moi']->getId()));
        self::assertResponseIsSuccessful('Sa propre tâche s’ouvre normalement.');
    }

    public function testLOngletEtLaPastilleDUnePisteNeComptentQueLeVisible(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $this->navigateur->request('GET', sprintf('/admin/piste/api/%d/taches/generic', $s['piste']->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(self::VISIBLES_POUR_MOI, self::taches((string) $this->navigateur->getResponse()->getContent()), 'onglet Tâches de la piste');

        $this->navigateur->request('GET', sprintf('/admin/piste/api/get-form/%d?consultation=1&idEntreprise=%d&idInvite=%d', $s['piste']->getId(), $s['e']->getId(), $s['i']['moi']->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match('/-onglet-taches"(?:(?!<\/button>).)*?jsb-onglet-compte">(\d+)/s', (string) $this->navigateur->getResponse()->getContent(), $m), 'pastille absente');
        self::assertSame(\count(self::VISIBLES_POUR_MOI), (int) $m[1], 'la pastille ne compte que les tâches visibles');
    }

    public function testLeTableauDeBordNeMontreQueCeQuiMeConcerne(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $this->navigateur->request('GET', sprintf('/admin/entreprise_dashbord/block/tasks/%d', $s['e']->getId()));
        self::assertResponseIsSuccessful();
        $html = (string) $this->navigateur->getResponse()->getContent();
        self::assertStringNotContainsString('TVF-duCollegue', $html);
        self::assertStringNotContainsString('TVF-automatiqueAutre', $html);
        self::assertStringNotContainsString('TVF-retour-invisible', $html, 'les derniers retours aussi');
    }

    public function testRetoursEtDocumentsHeritentDeLaTache(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $retours = $this->liste('feedback', $s['i']['moi'], $s['e']);
        self::assertStringContainsString('TVF-retour-visible', $retours);
        self::assertStringNotContainsString('TVF-retour-invisible', $retours);

        $documents = $this->liste('document', $s['i']['moi'], $s['e']);
        self::assertStringNotContainsString('TVF-doc-seulSurInvisible', $documents, 'seul rattachement : la tâche invisible');
        self::assertStringContainsString('TVF-doc-invisiblePlusClient', $documents, 'rattaché aussi à un client');
    }

    public function testUnLienRetireAgitALaRequeteSuivante(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        self::assertContains('duResponsable', self::taches($this->liste('tache', $s['i']['moi'], $s['e'])));

        $moi = $this->em()->find(Invite::class, $s['i']['moi']->getId());
        $moi->setManager(null);
        $this->em()->flush();
        self::assertNotContains('duResponsable', self::taches($this->liste('tache', $s['i']['moi'], $s['e'])), 'aucun cache');
    }

    public function testUneTacheCreeeParKetALeDemandeurPourAuteur(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $moi = $em->find(Invite::class, $s['i']['moi']->getId());
        $operation = MutationOperation::fromArray(['op' => 'create', 'entite' => 'Tache', 'champs' => [
            'description' => 'TVF-par-Ket', 'toBeEndedAt' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d'), 'closed' => false,
        ]]);
        static::getContainer()->get(WorkspaceMutationService::class)->executer($operation, new AiScope($em->find(Entreprise::class, $s['e']->getId()), $moi), null, metrer: false);
        $em->flush();
        $em->clear();

        $tache = $em->getRepository(Tache::class)->findOneBy(['description' => 'TVF-par-Ket']);
        self::assertNotNull($tache);
        self::assertSame($s['i']['moi']->getId(), $tache->getInvite()?->getId(), 'l’auteur est le demandeur');
    }

    public function testKetNeMontrePasLesTachesHorsPerimetreSousUnePiste(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $s['e']->getId()), $em->find(Invite::class, $s['i']['moi']->getId()));
        $resultat = static::getContainer()->get(\App\Ai\Tool\LireFicheTool::class)->execute(['entite' => 'Piste', 'id' => $s['piste']->getId()], $scope);
        $texte = (string) json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('TVF-assigneeAMoi', $texte, 'préalable : Ket lit bien les tâches de la piste');
        self::assertStringNotContainsString('TVF-duCollegue', $texte);
        self::assertStringNotContainsString('TVF-automatiqueAutre', $texte);
    }

    public function testLaFicheDUnCollegueNeCompteQueSesTachesVisibles(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $moi = $em->getRepository(Utilisateur::class)->findOneBy(['email' => self::COMPTES['moi']]);
        static::getContainer()->get('security.token_storage')->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($moi, 'main', $moi->getRoles()),
        );
        $strategie = static::getContainer()->get(\App\Services\Canvas\Indicator\InviteIndicatorStrategy::class);
        $collegue = $em->find(Invite::class, $s['i']['collegue']->getId());
        // Tâches non closes assignées au collègue : duCollegue et automatiqueAutre (invisibles
        // pour moi) ; creeeParMoi, creeeParResponsable, creeeParAssistant (visibles) → 3.
        self::assertSame(3, $strategie->calculate($collegue)['tachesEnCours']);
    }

    /** Le lecteur des services hors requête HTTP : comme une session web de MOI. */
    private function agirCommeMoi(): void
    {
        $moi = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::COMPTES['moi']]);
        static::getContainer()->get('security.token_storage')->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($moi, 'main', $moi->getRoles()),
        );
    }

    /** B1 — l'onglet d'une fiche ouverte en DIALOGUE (le chemin ordinaire de l'écran). */
    public function testLOngletEnDialogueNeMontreQueLeVisible(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $this->navigateur->request('GET', sprintf('/admin/piste/api/%d/taches/dialog', $s['piste']->getId()));
        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->navigateur->getResponse()->getContent(), true);
        self::assertSame(self::VISIBLES_POUR_MOI, self::taches((string) $reponse['html']));
        self::assertSame(\count(self::VISIBLES_POUR_MOI), $reponse['itemCount'], 'le compte rendu au navigateur aussi');
    }

    /** B2 — la section « Tâches » du relevé de compte du client. */
    public function testLeReleveDeCompteNeMontreQueLesTachesVisibles(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $this->agirCommeMoi();
        $client = $em->getRepository(Client::class)->findOneBy(['nom' => 'TVF client']);
        $contexte = static::getContainer()->get(\App\Service\Soa\SoaContextBuilder::class)
            ->build($client, $em->find(Entreprise::class, $s['e']->getId()), $em->find(Invite::class, $s['i']['moi']->getId()));
        $vues = array_map(static fn (Tache $t): string => (string) $t->getDescription(), $contexte['taches']);
        self::assertContains('TVF-assigneeAMoi', $vues);
        self::assertNotContains('TVF-duCollegue', $vues);
        self::assertNotContains('TVF-automatiqueAutre', $vues);
    }

    /** B3 — les statistiques de Ket, regroupées par description. */
    public function testLesStatistiquesDeKetNeLisentQueLeVisible(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $s['e']->getId()), $em->find(Invite::class, $s['i']['moi']->getId()));
        $resultat = static::getContainer()->get(\App\Ai\Tool\StatistiquesTool::class)
            ->execute(['entite' => 'Tache', 'operation' => 'compte', 'groupePar' => 'description'], $scope);
        $texte = (string) json_encode($resultat->data, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('TVF-assigneeAMoi', $texte, 'préalable : les statistiques répondent');
        self::assertStringNotContainsString('TVF-duCollegue', $texte);
        self::assertStringNotContainsString('TVF-automatiqueAutre', $texte);
    }

    /** B5 — Ket n'atteint pas une tâche invisible par la collection d'une piste visible. */
    public function testKetNAtteintPasUneTacheInvisibleParLaCollectionDeLaPiste(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $this->agirCommeMoi();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $piste = $em->find(Piste::class, $s['piste']->getId());
        $collection = new \App\Service\Workspace\CollectionEditable(
            'taches', 'Tache', Tache::class, \App\Form\TacheType::class, 'piste', true, true, 'getTaches', 'addTach', 'removeTach', 'setPiste',
        );
        $resoudre = new \ReflectionMethod($service, 'resoudreEnfantDansCollection');
        self::assertNull($resoudre->invoke($service, $piste, $collection, $s['t']['duCollegue']->getId()));
        self::assertNotNull($resoudre->invoke($service, $piste, $collection, $s['t']['assigneeAMoi']->getId()), 'préalable');
    }

    /** B6 — attacher ou lister les pièces d'une tâche invisible : introuvable. */
    public function testLesPiecesDUneTacheInvisibleSontIntrouvables(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        foreach (['/admin/document/api/attacher/tache/%d', '/admin/document/api/de/tache/%d'] as $route) {
            $this->navigateur->request('GET', sprintf($route, $s['t']['duCollegue']->getId()));
            self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), $route);
        }
        $this->navigateur->request('POST', sprintf('/admin/document/api/attacher/tache/%d', $s['t']['duCollegue']->getId()));
        self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), 'dépôt');
    }

    /** B7 + C2 — les documents d'une piste, pour Ket : rien qui ne passe que par une tâche invisible, ni son libellé. */
    public function testLaDescenteDesDocumentsSArreteALaFrontiere(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $this->agirCommeMoi();
        $descente = static::getContainer()->get(\App\Service\Document\DescenteDesDocuments::class);
        $moi = $em->find(Invite::class, $s['i']['moi']->getId());

        $noms = array_map(static fn (array $t): string => (string) $t['document']->getNom(), $descente->depuis($em->find(Piste::class, $s['piste']->getId()), $moi)['documents']);
        self::assertNotContains('TVF-doc-seulSurInvisible', $noms);
        self::assertFalse($descente->racineVisible($em->find(Tache::class, $s['t']['duCollegue']->getId()), $moi), 'une tâche invisible n’est pas un dossier');

        // C2 : la pièce visible par son client n'a pas pour origine la tâche invisible.
        $piece = $em->find(Document::class, $s['d']['invisiblePlusClient']->getId());
        $origine = static::getContainer()->get(\App\Service\Document\DocumentFichier::class)->parentDe($piece);
        self::assertInstanceOf(Client::class, $origine);
    }

    /** C1 — on ne rattache pas un retour à une tâche invisible, ni ne l'y déplace. */
    public function testOnNeRattachePasUnRetourAUneTacheInvisible(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $this->navigateur->request('POST', '/admin/feedback/api/submit', [
            'description' => 'TVF-intrus', 'type' => Feedback::TYPE_CALL, 'tache' => $s['t']['duCollegue']->getId(),
        ]);
        self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), 'création');

        $this->navigateur->request('POST', '/admin/feedback/api/submit', [
            'id' => $s['f']['visible']->getId(), 'description' => 'TVF-retour-visible', 'type' => Feedback::TYPE_CALL,
            'tache' => $s['t']['duCollegue']->getId(),
        ]);
        self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), 'déplacement');
        $this->em()->clear();
        self::assertSame($s['t']['assigneeAMoi']->getId(), $this->em()->find(Feedback::class, $s['f']['visible']->getId())->getTache()?->getId());
        self::assertNull($this->em()->getRepository(Feedback::class)->findOneBy(['description' => 'TVF-intrus']));

        $this->navigateur->request('POST', '/admin/feedback/api/submit', [
            'description' => 'TVF-legitime', 'type' => Feedback::TYPE_CALL, 'tache' => $s['t']['assigneeAMoi']->getId(),
        ]);
        self::assertNotSame(404, $this->navigateur->getResponse()->getStatusCode(), 'sa propre tâche reste accessible');
    }

    /** M1 — un critère de recherche ne traverse pas la frontière (« les pistes dont une tâche dit… »). */
    public function testUnCritereSurLesTachesNeLitQueLeVisible(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $chercher = function (string $mot) use ($s): string {
            $this->navigateur->request('POST', sprintf('/admin/piste/api/dynamic-query/%d/%d', $s['i']['moi']->getId(), $s['e']->getId()), [], [], ['CONTENT_TYPE' => 'application/json'],
                json_encode(['criteria' => ['taches' => ['operator' => 'LIKE', 'value' => $mot, 'targetField' => 'description']], 'parentContext' => null, 'page' => 1]));
            self::assertResponseIsSuccessful();

            return (string) (json_decode((string) $this->navigateur->getResponse()->getContent(), true)['html'] ?? '');
        };
        self::assertStringContainsString('TVF piste', $chercher('TVF-assigneeAMoi'), 'préalable : le critère fonctionne');
        self::assertStringNotContainsString('TVF piste', $chercher('TVF-duCollegue'));
    }

    private function agirComme(string $cle): void
    {
        $u = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::COMPTES[$cle]]);
        static::getContainer()->get('security.token_storage')->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($u, 'main', $u->getRoles()),
        );
    }

    private const REFUS_PISTE = 'Cette piste contient des éléments que vous ne pouvez pas gérer. Seul un administrateur peut la supprimer.';

    /** M2 — en direct : la piste porte des tâches invisibles. Refus générique, aperçu sans l'invisible. */
    public function testUnNonAdministrateurNeSupprimePasUnePisteQuiPorteUneTacheInvisible(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $this->agirComme('moi');
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $piste = $em->find(Piste::class, $s['piste']->getId());

        $plan = $service->planifierSuppression($piste);
        self::assertSame([self::REFUS_PISTE], $plan->refus, 'ni nombre, ni type, ni niveau');
        $taches = array_values(array_filter($plan->portee(), static fn (array $p): bool => $p['entite'] === 'Tache'));
        self::assertSame(\count(self::VISIBLES_POUR_MOI), $taches[0]['count'] ?? null, "l'aperçu ne compte que les tâches visibles");
        self::assertNotContains($s['t']['duCollegue']->getId(), $plan->idsDe(Tache::class));

        try {
            $service->commitDelete($piste);
            self::fail('La suppression aurait dû être refusée.');
        } catch (\App\Service\Workspace\MutationException $e) {
            self::assertSame(self::REFUS_PISTE, $e->getMessage());
        }
        $em->clear();
        self::assertNotNull($em->find(Tache::class, $s['t']['duCollegue']->getId()), 'rien n’a été détruit');
        self::assertNotNull($em->find(Piste::class, $s['piste']->getId()));
    }

    /**
     * M2 — par RICOCHET : une piste SANS tâche à elle, mais dont la cotation porte une tâche
     * invisible. (Supprimer un client ne ricoche pas : ses pistes sont DÉTACHÉES, pas
     * détruites — elles et leurs tâches restent.)
     */
    public function testLeRicochetPisteCotationTacheInvisibleEstRefuse(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $e = $em->find(Entreprise::class, $s['e']->getId());
        $collegue = $em->find(Invite::class, $s['i']['collegue']->getId());
        $piste = (new Piste())->setNom('TVF piste ricochet')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('r')->setExercice(2026);
        $piste->setEntreprise($e);
        $em->persist($piste);
        $cotation = (new \App\Entity\Cotation())->setNom('TVF cotation')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($e);
        $em->persist($cotation);
        $cachee = (new Tache())->setDescription('TVF-sousLaCotation')->setClosed(false)
            ->setToBeEndedAt(new \DateTimeImmutable('+3 days'))->setExecutor($collegue)->setCotation($cotation);
        $cachee->setEntreprise($e);
        $cachee->setInvite($collegue);
        $em->persist($cachee);
        $em->flush();
        $em->clear();

        $this->agirComme('moi');
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $plan = $service->planifierSuppression($em->find(Piste::class, $piste->getId()));
        self::assertSame([self::REFUS_PISTE], $plan->refus);
        self::assertSame([], $plan->idsDe(Tache::class), 'aucune tâche invisible dans l’aperçu');
        foreach ($plan->refus as $phrase) {
            self::assertDoesNotMatchRegularExpression('/\d|tâche|cotation/i', $phrase, 'ni nombre, ni type, ni niveau');
        }
    }

    /** M2 — un DÉTACHEMENT d'une tâche invisible est refusé aussi (« ni détruire, ni modifier »). */
    public function testUnDetachementDUneTacheInvisibleEstRefuse(): void
    {
        $s = $this->semer();
        $this->agirComme('moi');
        // Supprimer le collègue détacherait l'exécutant de ses tâches — que MOI ne vois pas.
        $plan = static::getContainer()->get(WorkspaceMutationService::class)
            ->planifierSuppression($this->em()->find(Invite::class, $s['i']['collegue']->getId()));
        self::assertContains('Ce collaborateur contient des éléments que vous ne pouvez pas gérer. Seul un administrateur peut le supprimer.', $plan->refus);
    }

    /** M2 — Ket (son worker : invité imposé, aucune session) est soumis à la même règle. */
    public function testKetEstSoumisALaMemeRegleDeSuppression(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $identite = static::getContainer()->get(\App\Ai\Traitement\IdentiteDuTraitement::class);
        $identite->endosser($em->find(Invite::class, $s['i']['moi']->getId()), $em->find(Entreprise::class, $s['e']->getId()));
        try {
            $this->expectException(\App\Service\Workspace\MutationException::class);
            static::getContainer()->get(WorkspaceMutationService::class)->commitDelete($em->find(Piste::class, $s['piste']->getId()));
        } finally {
            $identite->relacher();
        }
    }

    /**
     * C3 — Ket dans son worker, pour un invité SANS compte utilisateur : aucun jeton ne peut
     * être posé, c'est l'imposition seule qui dit qui lit (et non « personne, donc tout »).
     */
    public function testKetLitAuNomDeLInviteImposeMemeSansJeton(): void
    {
        $s = $this->semer();
        $em = $this->em();
        static::getContainer()->get('security.token_storage')->setToken(null);
        $invite = $em->find(Invite::class, $s['i']['moi']->getId());
        $invite->setUtilisateur(null); // en mémoire seulement : rien n'est écrit
        $entreprise = $em->find(Entreprise::class, $s['e']->getId());
        $identite = static::getContainer()->get(\App\Ai\Traitement\IdentiteDuTraitement::class);
        $courant = static::getContainer()->get(\App\Service\Workspace\InviteCourant::class);

        $identite->endosser($invite, $entreprise);
        try {
            self::assertSame($invite, $courant->pour($entreprise));
            self::assertSame(\count(self::VISIBLES_POUR_MOI), $this->tachesVisiblesDuLecteur($entreprise));
        } finally {
            $identite->relacher();
        }
        self::assertNull($courant->pour($entreprise), 'relâché : plus personne');
        $em->clear();
    }

    /** M2 — l'administrateur garde la cascade complète. */
    public function testLAdministrateurSupprimeLaPisteEtToutesSesTaches(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $this->agirComme('proprio');
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $piste = $em->find(Piste::class, $s['piste']->getId());
        self::assertSame([], $service->planifierSuppression($piste)->refus);
        $service->commitDelete($piste);
        $em->clear();
        self::assertNull($em->find(Tache::class, $s['t']['duCollegue']->getId()), 'la tâche invisible aux autres part aussi');
        self::assertNull($em->find(Piste::class, $s['piste']->getId()));
    }

    /** Le nombre de tâches du cabinet que le lecteur COURANT voit, par la règle unique. */
    private function tachesVisiblesDuLecteur(Entreprise $e): int
    {
        $em = $this->em();
        $qb = $em->createQueryBuilder()->select('COUNT(t.id)')->from(Tache::class, 't')
            ->where('t.entreprise = :e')->setParameter('e', $e);
        $condition = static::getContainer()->get(TacheVisibiliteScope::class)->conditionPourLeLecteur($qb, Tache::class, 't', $e, '_t');
        if ($condition !== null) {
            $qb->andWhere($condition);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** C3 — sans utilisateur et sans contexte système déclaré : AUCUNE tâche, pas même le commun. */
    public function testSansUtilisateurAucuneTache(): void
    {
        $s = $this->semer();
        static::getContainer()->get('security.token_storage')->setToken(null);
        self::assertSame(0, $this->tachesVisiblesDuLecteur($this->em()->find(Entreprise::class, $s['e']->getId())));
    }

    /** C3 — le propriétaire SANS fiche d'invitation reste administrateur : il voit tout. */
    public function testLeProprietaireSansInvitationVoitToutesLesTaches(): void
    {
        $s = $this->semer();
        $this->em()->getConnection()->executeStatement('DELETE FROM invite WHERE id = :i', ['i' => $s['i']['proprio']->getId()]);
        $this->em()->clear();
        $this->agirComme('proprio');
        self::assertSame(9, $this->tachesVisiblesDuLecteur($this->em()->find(Entreprise::class, $s['e']->getId())));
    }

    /** C3 — l'export de l'Échange : complet en contexte système déclaré, aucune tâche sans lui. */
    public function testLExportNeSortLesTachesQuEnContexteSysteme(): void
    {
        $s = $this->semer();
        $em = $this->em();
        static::getContainer()->get('security.token_storage')->setToken(null);
        $exportateur = static::getContainer()->get(\App\Echange\Service\ExportateurJsbx::class);
        $proprio = $em->find(Invite::class, $s['i']['proprio']->getId());
        $entreprise = $em->find(Entreprise::class, $s['e']->getId());
        $ressources = array_filter($exportateur->perimetre($proprio), static fn ($r): bool => $r->fqcn === Tache::class);
        self::assertNotSame([], $ressources, 'préalable : les tâches font partie de l’échange');

        [, , $sansContexte] = $exportateur->produire($entreprise, $proprio, null, $ressources);
        self::assertSame(0, $sansContexte, 'sans contexte système : aucune tâche');

        $complet = static::getContainer()->get(\App\Service\Workspace\InviteCourant::class)->enContexteSysteme(
            'Test : export complet',
            static fn (): int => $exportateur->produire($entreprise, $proprio, null, $ressources)[2],
        );
        self::assertSame(9, $complet, 'en contexte système : toutes');
    }

    /** C3 — une requête HTTP ne peut pas ouvrir le contexte système. */
    public function testUneRequeteHttpNOuvrePasLeContexteSysteme(): void
    {
        $pile = static::getContainer()->get('request_stack');
        $pile->push(new \Symfony\Component\HttpFoundation\Request());
        try {
            $this->expectException(\LogicException::class);
            static::getContainer()->get(\App\Service\Workspace\InviteCourant::class)->enContexteSysteme('Tentative', static fn () => true);
        } finally {
            $pile->pop();
        }
    }

    /** B1 — la fiche ouverte par son identifiant (clic sur un lien) : introuvable hors du cercle. */
    public function testLaFicheParSonIdentifiantObeitALaFrontiere(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        foreach (['Tache' => $s['t']['duCollegue']->getId(), 'Feedback' => $s['f']['invisible']->getId(), 'Document' => $s['d']['seulSurInvisible']->getId()] as $type => $id) {
            $this->navigateur->request('GET', sprintf('/espacedetravail/api/get-entity-details/%s/%d', $type, $id));
            self::assertSame(404, $this->navigateur->getResponse()->getStatusCode(), $type);
        }
        $this->navigateur->request('GET', sprintf('/espacedetravail/api/get-entity-details/Tache/%d', $s['t']['assigneeAMoi']->getId()));
        self::assertResponseIsSuccessful('sa propre tâche s’ouvre');
    }

    /** B2 — la fiche d'une piste visible ne nomme pas les tâches d'autrui qu'elle porte. */
    public function testLaFicheDUnePisteNeNommeQueSesTachesVisibles(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        $this->navigateur->request('GET', sprintf('/espacedetravail/api/get-entity-details/Piste/%d', $s['piste']->getId()));
        self::assertResponseIsSuccessful();
        $corps = (string) $this->navigateur->getResponse()->getContent();
        self::assertStringContainsString('TVF-assigneeAMoi', $corps, 'préalable : la fiche publie ses tâches');
        self::assertStringNotContainsString('TVF-duCollegue', $corps);
        self::assertStringNotContainsString('TVF-automatiqueAutre', $corps);
    }

    /** B3 — la liste complète d'un type ne sort pas les tâches, retours et pièces d'autrui. */
    public function testLaListeCompleteDUnTypeObeitALaFrontiere(): void
    {
        $s = $this->semer();
        $this->connecter('moi');
        foreach (['Tache' => 'TVF-duCollegue', 'Feedback' => 'TVF-retour-invisible', 'Document' => 'TVF-doc-seulSurInvisible'] as $type => $marqueur) {
            $this->navigateur->request('GET', sprintf('/espacedetravail/api/get-entities/%s/%d', $type, $s['e']->getId()));
            self::assertResponseIsSuccessful($type);
            self::assertStringNotContainsString($marqueur, (string) $this->navigateur->getResponse()->getContent(), $type);
        }
    }

    /** C1 — l'aperçu d'une suppression refusée ne garde ni détachement, ni provenance vers l'invisible. */
    public function testLAperçuRefuseNeLaissePasDeTraceDeLInvisible(): void
    {
        $s = $this->semer();
        $this->agirComme('moi');
        $plan = static::getContainer()->get(WorkspaceMutationService::class)
            ->planifierSuppression($this->em()->find(Invite::class, $s['i']['collegue']->getId()));
        self::assertTrue($plan->estBloque());
        $invisibles = [$s['t']['duCollegue']->getId(), $s['t']['automatiqueAutre']->getId()];
        foreach ($plan->aDetacher as $detachement) {
            if ($detachement['classe'] === Tache::class) {
                self::assertSame([], array_intersect($invisibles, $detachement['ids']), 'détachements');
            }
        }
        foreach ($plan->provenance as $parents) {
            foreach ($parents as $parent) {
                foreach ($invisibles as $id) {
                    self::assertNotSame('Tache#' . $id, $parent, 'provenance');
                }
            }
        }
    }

    /** C2 — demander à « conserver » un retour invisible n'apprend rien : seul le refus générique. */
    public function testUneExclusionInvisibleNApprendRien(): void
    {
        $s = $this->semer();
        $this->agirComme('moi');
        $moteur = static::getContainer()->get(\App\Service\Workspace\SuppressionEnCascade::class);
        $plan = $moteur->planifier(
            $this->em()->find(Piste::class, $s['piste']->getId()),
            \App\Service\Workspace\ExclusionsDeSuppression::depuis(['Feedback' => [$s['f']['invisible']->getId()], 'Tache' => [$s['t']['duCollegue']->getId()]]),
        );
        self::assertSame([self::REFUS_PISTE], $plan->refus);
    }

    /** C3 — un classeur ne compte que les pièces que le lecteur voit. */
    public function testUnClasseurNeCompteQueLesPiecesVisibles(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $classeur = (new \App\Entity\Classeur())->setNom('TVF classeur')->setDescription('TVF');
        $classeur->setEntreprise($em->find(Entreprise::class, $s['e']->getId()));
        $em->persist($classeur);
        foreach (['seulSurInvisible', 'invisiblePlusClient'] as $cle) {
            $em->find(Document::class, $s['d'][$cle]->getId())->setClasseur($classeur);
        }
        $em->flush();
        $em->clear();
        $this->agirComme('moi');
        $indicateurs = static::getContainer()->get(\App\Services\Canvas\Indicator\ClasseurIndicatorStrategy::class)
            ->calculate($em->find(\App\Entity\Classeur::class, $classeur->getId()));
        self::assertSame(1, $indicateurs['nombreDocuments'], 'la pièce visible par son client seulement');
    }

    /** Réassignation — écran : confier hors de son cercle est refusé ; l'auteur et l'administrateur le peuvent. */
    public function testLaReassignationHorsDuCercleEstRefuseeALEcran(): void
    {
        $s = $this->semer();
        $soumettre = function (string $tache, string $vers) use ($s): \Symfony\Component\HttpFoundation\Response {
            $this->navigateur->request('POST', '/admin/tache/api/submit', ['id' => $s['t'][$tache]->getId(), 'executor' => $s['i'][$vers]->getId()]);

            return $this->navigateur->getResponse();
        };

        $this->connecter('moi');
        $refus = $soumettre('assigneeAMoi', 'collegue');
        self::assertSame(422, $refus->getStatusCode());
        self::assertSame(TacheVisibiliteScope::REFUS_REASSIGNATION, json_decode((string) $refus->getContent(), true)['message'] ?? null);
        $this->em()->clear();
        self::assertSame($s['i']['moi']->getId(), $this->em()->find(Tache::class, $s['t']['assigneeAMoi']->getId())->getExecutor()?->getId(), 'rien n’a changé');

        // L'AUTEUR reste dans le cercle : il peut confier à qui il veut.
        self::assertSame(200, $soumettre('creeeParMoi', 'proprio')->getStatusCode(), 'l’auteur');

        $this->connecter('proprio');
        self::assertSame(200, $soumettre('assigneeAMoi', 'collegue')->getStatusCode(), 'l’administrateur');
    }

    /** Réassignation — Ket : la même règle. */
    public function testLaReassignationHorsDuCercleEstRefuseePourKet(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $this->agirComme('moi');
        $scope = new AiScope($em->find(Entreprise::class, $s['e']->getId()), $em->find(Invite::class, $s['i']['moi']->getId()));
        try {
            static::getContainer()->get(WorkspaceMutationService::class)->executer(
                new MutationOperation('edit', 'Tache', $s['t']['assigneeAMoi']->getId(), ['executor' => $s['i']['collegue']->getId()]),
                $scope,
                null,
            );
            self::fail('La réassignation aurait dû être refusée.');
        } catch (\App\Service\Workspace\MutationException $e) {
            self::assertSame(TacheVisibiliteScope::REFUS_REASSIGNATION, $e->getMessage());
        }
    }

    /** Parité SQL de la règle des pièces : tâche invisible + congé d'autrui → invisible ; + une piste → visible. */
    public function testLesPiecesAPlusieursRattachementsDansLaListe(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $e = $em->find(Entreprise::class, $s['e']->getId());
        $conge = (new \App\Entity\DemandeConge())->setAgent($em->find(Invite::class, $s['i']['collegue']->getId()))
            ->setDateDebut(new \DateTimeImmutable('+10 days'))->setDateFin(new \DateTimeImmutable('+12 days'));
        $conge->setEntreprise($e);
        $em->persist($conge);
        foreach (['congeEtTacheInvisibles' => null, 'congeTachePlusPiste' => $s['piste']] as $nom => $piste) {
            $document = (new Document())->setNom('TVF-doc-' . $nom)->setTache($em->find(Tache::class, $s['t']['duCollegue']->getId()))->setDemandeConge($conge);
            if ($piste !== null) {
                $document->setPiste($em->find(Piste::class, $piste->getId()));
            }
            $document->setEntreprise($e);
            $em->persist($document);
        }
        $em->flush();
        $em->clear();

        $this->connecter('moi');
        $liste = $this->liste('document', $s['i']['moi'], $s['e']);
        self::assertStringNotContainsString('TVF-doc-congeEtTacheInvisibles', $liste);
        self::assertStringContainsString('TVF-doc-congeTachePlusPiste', $liste);
    }

    /** La liste des rattachements « autres » d'un document suit le modèle : un nouveau ne s'oublie pas. */
    public function testLesRattachementsDuDocumentSontTousConnus(): void
    {
        $meta = $this->em()->getClassMetadata(Document::class);
        $aUn = array_values(array_filter($meta->getAssociationNames(), static fn (string $a): bool => $meta->isSingleValuedAssociation($a)));
        $attendus = array_values(array_diff($aUn, ['entreprise', 'invite', 'classeur', 'tache', 'feedback']));
        sort($attendus);
        $declares = TacheVisibiliteScope::AUTRES_RATTACHEMENTS_DU_DOCUMENT;
        sort($declares);
        self::assertSame($attendus, $declares);
    }
}
