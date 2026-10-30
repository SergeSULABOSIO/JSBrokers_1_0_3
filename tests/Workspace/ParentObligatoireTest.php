<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RolesEnFinance;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\ParentObligatoire;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\Canvas\FormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UN ENFANT NE NAÎT PAS ORPHELIN (Serge, 2026-10-30).
 *
 * Une tranche, un avenant, un revenu, un rôle… ne se créent que depuis leur parent : sa fiche,
 * ou l'onglet contextuel de sa rubrique. Leur liste principale n'offre pas « Ajouter », et le
 * serveur refuse — écran comme Ket — une création qui ne porte pas son parent. Une règle, un
 * registre : ParentObligatoire.
 */
class ParentObligatoireTest extends WebTestCase
{
    private const EMAIL = 'phpunit-parent-obligatoire@test.local';
    private const CABINET = 'PHPUnit Parent Obligatoire SARL';

    /**
     * Les enfants dont le formulaire ne laisse pas choisir le parent, mais qui peuvent naître
     * sans lui — chacun avec son motif. Un enfant nouveau doit être déclaré dans le registre,
     * ou ajouté ici avec le sien.
     */
    private const EXEMPTES = [
        'document' => "Rattachable à une trentaine de fiches : la rubrique Documents garde son « Ajouter » (hors du périmètre décidé le 2026-10-30).",
        'tache' => "Une tâche peut être libre (sans piste, proposition ni sinistre).",
        'paiement' => "Rattachable à une note, une offre d'indemnisation ou un compte bancaire (hors du périmètre décidé le 2026-10-30).",
        'contact' => "Son formulaire le rattache à un client ; la notification de sinistre n'en est qu'un second parent.",
        'risque' => "Les risques ciblés d'une condition de partage sont CHOISIS (ManyToMany), jamais créés depuis elle.",
        'conditionpartage' => "Rubrique fermée à la création (`creation_interdite`) : elle naît de la fiche d'un partenaire, d'un invité ou d'une piste.",
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = ?', [self::EMAIL]);
        $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]);
        if ($id !== false) {
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = ?', [self::EMAIL]);
        $this->em()->clear();
    }

    /** @return array{owner: int, entreprise: int, invite: int, cotation: int} */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::EMAIL)->setNom('Parent obligatoire')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000077')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $invite = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $em->persist($invite);
        $client = (new Client())->setNom('Client parent obligatoire')->setExonere(false);
        $client->setEntreprise($cabinet);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste ' . uniqid())->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')
            ->setExercice(2026)->setClient($client);
        $piste->setEntreprise($cabinet)->setInvite($invite);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Offre ' . uniqid())->setDuree(12);
        $cotation->setPiste($piste)->setEntreprise($cabinet);
        $em->persist($cotation);
        $em->flush();
        $this->client->loginUser($owner);
        $s = ['owner' => (int) $owner->getId(), 'entreprise' => (int) $cabinet->getId(), 'invite' => (int) $invite->getId(), 'cotation' => (int) $cotation->getId()];
        $em->clear();

        return $s;
    }

    /**
     * Les collections des canevas de formulaire, TELS QUE LE NOYAU LES CONSTRUIT (une lecture du
     * texte manquerait celles déclarées par des variables, comme le relevé de la tranche) :
     * [enfant, champ du parent, titre de l'onglet, lecture seule].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private function collections(): array
    {
        $canevas = static::getContainer()->get(FormCanvasProvider::class);
        $collections = [];
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->getReflectionClass()->isAbstract()) {
                continue;
            }
            try {
                // `new`, pas `$meta->newInstance()` : celui-ci saute le constructeur, les
                // collections ne sont pas initialisées et le canevas lèverait.
                $classe = $meta->getName();
                $layout = $canevas->getCanvas(new $classe(), null)['form_layout'] ?? [];
            } catch (\Throwable) {
                continue;
            }
            foreach ($layout as $rangee) {
                foreach ($rangee['colonnes'] ?? [] as $colonne) {
                    foreach ($colonne['champs'] ?? [] as $champ) {
                        $o = is_array($champ) && ($champ['widget'] ?? null) === 'collection' ? $champ['options'] : null;
                        if ($o === null || !preg_match('#^/admin/([^/]+)/api/submit$#', (string) ($o['itemSubmitUrl'] ?? ''), $m)) {
                            continue;
                        }
                        $collections[] = [$m[1], (string) $o['parentFieldName'], (string) ($rangee['onglet_titre'] ?? ''), (bool) ($o['lectureSeule'] ?? false)];
                    }
                }
            }
        }
        self::assertNotEmpty($collections, 'Aucune collection lue : le test ne prouverait rien.');

        return $collections;
    }

    /** Le formulaire de l'enfant laisse-t-il choisir ce parent ? */
    private static function formulaireChoisitLeParent(string $routeEnfant, string $champ): bool
    {
        foreach (glob(__DIR__ . '/../../src/Form/*Type.php') as $fichier) {
            if (strtolower(basename($fichier, 'Type.php')) === $routeEnfant) {
                return (bool) preg_match("/->add\(\s*'" . preg_quote($champ, '/') . "'/", (string) file_get_contents($fichier));
            }
        }

        return false;
    }

    private static function classeDeLaRoute(string $route): ?string
    {
        foreach (array_keys(ParentObligatoire::PARENTS) as $classe) {
            if (strtolower(substr($classe, (int) strrpos($classe, '\\') + 1)) === $route) {
                return $classe;
            }
        }

        return null;
    }

    /**
     * Chaque entrée du registre est une collection réelle d'un parent, et son refus nomme l'onglet
     * où créer — sauf si aucun onglet n'en crée (lecture seule : le paiement de prime se signale
     * par une action de la tranche).
     */
    public function testLeRegistreSuitLesCollectionsDesCanevas(): void
    {
        $collections = $this->collections();
        foreach (ParentObligatoire::PARENTS as $classe => $regle) {
            $route = strtolower(substr($classe, (int) strrpos($classe, '\\') + 1));
            $trouvees = array_filter($collections, static fn (array $c): bool => $c[0] === $route && $c[1] === $regle['champ']);
            self::assertNotEmpty($trouvees, "$classe : aucun canevas ne le déclare en collection de « {$regle['champ']} ».");
            $creatrices = array_filter($trouvees, static fn (array $c): bool => !$c[3]);
            if ($creatrices === []) {
                continue;
            }
            $onglets = array_map(static fn (array $c): string => '« ' . $c[2] . ' »', $creatrices);
            self::assertTrue(
                (bool) array_filter($onglets, static fn (string $o): bool => str_contains($regle['refus'], $o)),
                "$classe : le refus doit nommer l'onglet où créer (" . implode(', ', $onglets) . ').',
            );
        }
    }

    /** Un enfant dont le formulaire ne laisse pas choisir le parent est déclaré dans le registre, ou exempté avec son motif. */
    public function testUnEnfantSansChoixDuParentEstDeclareOuExempte(): void
    {
        $oublis = [];
        foreach ($this->collections() as [$route, $champ]) {
            if (self::formulaireChoisitLeParent($route, $champ) || isset(self::EXEMPTES[$route])) {
                continue;
            }
            $classe = self::classeDeLaRoute($route);
            if ($classe === null) {
                // Une rubrique fermée à la création dit déjà où l'on crée.
                $oublis[] = "$route (← $champ)";
            }
        }

        self::assertSame([], array_values(array_unique($oublis)),
            'Ces enfants naîtraient orphelins depuis leur liste : déclarez-les dans ParentObligatoire, ou exemptez-les avec leur motif.');
    }

    /** Le canevas porte la règle : la barre d'outils, le menu contextuel et l'état vide la lisent. */
    public function testLeCanevasDitQueLaCreationPasseParLeParent(): void
    {
        $s = $this->semer();
        $canevas = static::getContainer()->get(FormCanvasProvider::class);

        self::assertSame('cotation', $canevas->getCanvas(new Tranche(), $s['entreprise'])['parametres']['creation_depuis_le_parent'] ?? null);
        // Le formulaire d'un rôle CHOISIT le collaborateur (PARENT_CHOISI) : la liste garde « Ajouter ».
        self::assertArrayNotHasKey('creation_depuis_le_parent', $canevas->getCanvas(new RolesEnFinance(), $s['entreprise'])['parametres'] ?? []);
        self::assertArrayNotHasKey('creation_depuis_le_parent', $canevas->getCanvas(new Client(), $s['entreprise'])['parametres'] ?? []);
    }

    private function posterUneTranche(array $plus = []): void
    {
        $this->client->request('POST', '/admin/tranche/api/submit', [
            'nom'         => 'Tranche ' . uniqid(),
            'pourcentage' => '100',
            'payableAt'   => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
            'echeanceAt'  => (new \DateTimeImmutable('+1 year'))->format('Y-m-d\TH:i'),
        ] + $plus);
    }

    private function nombreDeTranches(int $entreprise): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tranche WHERE entreprise_id = ?', [$entreprise]);
    }

    /** L'écran : une tranche sans proposition est refusée, et son refus dit où la créer. */
    public function testLEcranRefuseUneTrancheSansProposition(): void
    {
        $s = $this->semer();

        $this->posterUneTranche();
        self::assertResponseStatusCodeSame(422);
        self::assertSame(ParentObligatoire::PARENTS[Tranche::class]['refus'], json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? null);
        self::assertSame(0, $this->nombreDeTranches($s['entreprise']), 'Rien n’est écrit.');

        $this->posterUneTranche(['cotation' => (string) $s['cotation']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->nombreDeTranches($s['entreprise']), 'Depuis sa proposition, elle naît.');
    }

    /** La saisie DIFFÉRÉE (parent pas encore écrit) se valide sans parent : elle le recevra au rejeu. */
    public function testLaSaisieDiffereeSeValideSansParent(): void
    {
        $s = $this->semer();

        $this->posterUneTranche(['dry_run' => '1']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->nombreDeTranches($s['entreprise']));
    }

    /** Ket : la même règle, dès l'analyse du plan et à l'écriture. */
    public function testKetRefuseUneTrancheAPlat(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $s['entreprise']), $em->find(Invite::class, $s['invite']));
        $champs = [
            'nom'         => 'Tranche de Ket',
            'pourcentage' => 100,
            'payableAt'   => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
            'echeanceAt'  => (new \DateTimeImmutable('+1 year'))->format('Y-m-d\TH:i'),
        ];

        // Un CHAMP MANQUANT, pas un blocage : Ket demande « quelle proposition ? ».
        $refus = ParentObligatoire::PARENTS[Tranche::class]['refus'];
        $analyse = $service->analyserOperation(new MutationOperation('create', 'Tranche', null, $champs), $scope);
        self::assertSame('invalide', $analyse['statut']);
        self::assertSame(['cotation' => [$refus]], $analyse['manquants']);

        // Nommée mais introuvable dans le cabinet : refusée dès l'analyse, pas après la validation du plan.
        $introuvable = $service->analyserOperation(new MutationOperation('create', 'Tranche', null, $champs + ['cotation' => 999999999]), $scope);
        self::assertFalse($introuvable['ok']);
        self::assertSame(['cotation' => [$refus]], $introuvable['manquants']);

        try {
            $service->executer(new MutationOperation('create', 'Tranche', null, $champs), $scope, $em->find(Utilisateur::class, $s['owner']));
            self::fail('Ket ne crée pas une tranche orpheline.');
        } catch (MutationException $e) {
            self::assertSame(ParentObligatoire::PARENTS[Tranche::class]['refus'], $e->getMessage());
        }
        self::assertSame(0, $this->nombreDeTranches($s['entreprise']));

        $avecParent = new MutationOperation('create', 'Tranche', null, $champs + ['cotation' => $s['cotation']]);
        self::assertTrue($service->analyserOperation($avecParent, $scope)['ok']);
    }
}
