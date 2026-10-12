<?php

namespace App\Tests\Security;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Cotation;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Operation;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * UN IDENTIFIANT VENU DE LA REQUÊTE NE DÉSIGNE RIEN HORS DU CABINET OUVERT.
 *
 * `CloisonnementParIdentifiantTest` a fermé les trois verbes génériques — détail,
 * formulaire, suppression — sur l'entité que la route nomme. Il restait tout ce qu'une
 * requête désigne PAR AILLEURS : le parent d'une création, une association glissée dans
 * le corps d'un POST, le cabinet porté par le chemin, la cible d'un document. Cinq
 * formes d'un même défaut — un `find()` sans question sur le cabinet :
 *
 *   A. un parent ou un lien chargé depuis un champ de la requête, puis posé ;
 *   B. une entité `{id}` résolue par la route, puis lue ou écrite ;
 *   C. le cabinet (ou l'invité) pris dans le chemin `{idEntreprise}` / `{idInvite}` ;
 *   D. une requête sans filtre de cabinet ;
 *   E. un contrôle écrit à la main qui laissait passer en cas de doute.
 *
 * La plus grave était la A : `entreprise=<autre cabinet>` glissé dans le POST
 * d'`invite/api/submit` créait une invitation ACTIVE chez autrui. L'inscription étant
 * publique, n'importe quel propriétaire de cabinet pouvait s'ouvrir celui d'un autre.
 *
 * ── CHAQUE REFUS A SON PENDANT ──────────────────────────────────────────────
 * Un 404 a deux causes : « pas à vous » et « cassé pour tout le monde ». Chaque schéma
 * rejoue donc le MÊME geste dans le cabinet ouvert, et exige qu'il passe. Un correctif
 * qui fermerait aussi la porte aux siens ne passerait pas ces tests.
 */
class CloisonnementDesIdentifiantsTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait {
        cleanUp as private nettoyerLesDeuxCabinets;
    }

    /** Un routeur qui rétrécit viderait les tests d'inventaire de leur substance. */
    private const ROUTES_INDEX_AU_MOINS = 35;
    private const ROUTES_DYNAMIC_QUERY_AU_MOINS = 40;
    private const ROUTES_TABLEAU_DE_BORD_AU_MOINS = 20;

    private const EMAIL_INTRUS = 'phpunit-cloison-intrus@test.local';
    private const EMAIL_LEGITIME = 'phpunit-cloison-legitime@test.local';

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

    private function cleanUp(): void
    {
        $this->nettoyerLesDeuxCabinets();
        // Une invitation acceptée peut avoir créé un compte : on le retire aussi.
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (?, ?)',
            [self::EMAIL_INTRUS, self::EMAIL_LEGITIME],
        );
    }

    // ── OUTILS ──────────────────────────────────────────────────────────────

    /**
     * @return array{entA: int, entB: int, invA: int, invB: int, ownerB: int}
     */
    private function semer(): array
    {
        $seed = $this->semerLesDeuxCabinets();

        return [
            'entA' => $seed['a']['entreprise'],
            'entB' => $seed['b']['entreprise'],
            'invA' => (int) $this->premiereInviteDe($seed['a']['owner'], $seed['a']['entreprise']),
            'invB' => (int) $this->premiereInviteDe($seed['b']['owner'], $seed['b']['entreprise']),
            'ownerB' => $seed['b']['owner'],
        ];
    }

    /** Connecté en B : toutes les cibles « d'autrui » sont dans le cabinet A. */
    private function connecterB(array $s): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($s['ownerB']));
    }

    private function premier(string $classe, int $entrepriseId): int
    {
        $ids = $this->identifiantsDuCabinet($classe, $entrepriseId);
        self::assertNotEmpty($ids, sprintf('Aucun %s semé dans le cabinet #%d : ce test ne prouverait rien.', $classe, $entrepriseId));

        return $ids[0];
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function exception(): string
    {
        return urldecode((string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
    }

    /** Le geste visait le cabinet d'autrui : il doit être refusé par un 404, sans plantage. */
    private function exigerLeRefus(string $geste): void
    {
        $code = $this->code();
        self::assertSame(Response::HTTP_NOT_FOUND, $code, sprintf(
            "FRONTIÈRE FRANCHIE : %s a répondu HTTP %d au lieu de 404.%s",
            $geste,
            $code,
            $code >= 500
                ? ' (' . $this->exception() . ')'
                : ' ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 400),
        ));
    }

    /** Le même geste dans son propre cabinet : ni refusé, ni planté. */
    private function exigerLAcces(string $geste): void
    {
        $code = $this->code();
        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
            '%s plante dans son propre cabinet (HTTP %d) : %s',
            $geste,
            $code,
            $this->exception(),
        ));
        self::assertNotSame(Response::HTTP_NOT_FOUND, $code, sprintf(
            "GESTE LÉGITIME REFUSÉ : %s répond 404 dans le cabinet OUVERT. Le correctif a fermé la "
            . 'porte aux siens.',
            $geste,
        ));
    }

    private function entrepriseDe(string $classe, int $id): ?int
    {
        $this->em()->clear();
        $entite = $this->em()->find($classe, $id);

        return $entite?->getEntreprise()?->getId();
    }

    /**
     * Les chemins du routeur qui contiennent $motif, tels que déclarés.
     *
     * @return list<string>
     */
    private function cheminsDuRouteur(string $motif): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $chemins = [];
        foreach ($router->getRouteCollection() as $route) {
            if (str_contains($route->getPath(), $motif)) {
                $chemins[] = $route->getPath();
            }
        }
        sort($chemins);

        return array_values(array_unique($chemins));
    }

    // ── A : PARENT OU LIEN CHARGÉ DEPUIS LA REQUÊTE ─────────────────────────

    /** trait renderFormCanvas : `?parent_id=` d'un formulaire de création. */
    public function testLeParentDUnFormulaireDeCreationEstDuCabinet(): void
    {
        $s = $this->semer();
        $pisteA = $this->premier(Piste::class, $s['entA']);
        $pisteB = $this->premier(Piste::class, $s['entB']);
        $this->connecterB($s);

        $this->client->request('GET', '/admin/cotation/api/get-form?parent_field_name=piste&parent_id=' . $pisteA);
        $this->exigerLeRefus("Ouvrir une cotation sous la piste #$pisteA du cabinet A");

        $this->client->request('GET', '/admin/cotation/api/get-form?parent_field_name=piste&parent_id=' . $pisteB);
        $this->exigerLAcces("Ouvrir une cotation sous sa propre piste #$pisteB");
    }

    /**
     * LE CAS LE PLUS GRAVE : `entreprise=` glissé dans le POST de création d'un invité.
     * Sans le correctif, il créait une invitation ACTIVE dans le cabinet A.
     */
    public function testUneEntreprisePosteeNOuvrePasLeCabinetDAutrui(): void
    {
        $s = $this->semer();
        $invitesDeAAvant = \count($this->identifiantsDuCabinet(Invite::class, $s['entA']));
        $this->connecterB($s);

        $this->client->request('POST', '/admin/invite/api/submit', [
            'nom' => 'Intrus du cabinet voisin',
            'email' => self::EMAIL_INTRUS,
            'entreprise' => $s['entA'],
        ]);
        $this->exigerLeRefus("Créer un invité en postant entreprise=#{$s['entA']}");
        self::assertSame(
            $invitesDeAAvant,
            \count($this->identifiantsDuCabinet(Invite::class, $s['entA'])),
            "PRISE DE CONTRÔLE : une invitation a été créée dans le cabinet A par un POST du cabinet B.",
        );

        // Le même geste, sans champ forgé : l'invité naît dans le cabinet OUVERT.
        $this->client->request('POST', '/admin/invite/api/submit', [
            'nom' => 'Collaborateur legitime',
            'email' => self::EMAIL_LEGITIME,
        ]);
        $this->exigerLAcces('Inviter un collaborateur dans son propre cabinet');
        $this->em()->clear();
        $cree = $this->em()->getRepository(Invite::class)->findOneBy(['nom' => 'Collaborateur legitime']);
        self::assertNotNull($cree, "L'invitation légitime n'a pas été écrite.");
        self::assertSame($s['entB'], $cree->getEntreprise()?->getId());
    }

    /** trait handleFormSubmission, boucle d'ÉDITION : une fiche ne change pas de cabinet. */
    public function testUneFicheNeChangePasDeCabinetALEdition(): void
    {
        $s = $this->semer();
        $groupeB = $this->premier(Groupe::class, $s['entB']);
        $this->connecterB($s);

        $this->client->request('POST', '/admin/groupe/api/submit', [
            'id' => $groupeB,
            'nom' => 'Groupe deplace chez le voisin',
            'description' => 'Tentative de deplacement',
            'entreprise' => $s['entA'],
        ]);
        $this->exigerLeRefus("Éditer son Groupe #$groupeB en postant entreprise=#{$s['entA']}");
        self::assertSame($s['entB'], $this->entrepriseDe(Groupe::class, $groupeB), 'Le Groupe a quitté le cabinet B.');

        $this->client->request('POST', '/admin/groupe/api/submit', [
            'id' => $groupeB,
            'nom' => 'Groupe renomme chez soi',
            'description' => 'Edition legitime',
        ]);
        $this->exigerLAcces("Renommer son propre Groupe #$groupeB");
    }

    /** PisteController get-form : `?idAvenant=` d'une piste dérivée. */
    public function testLaPoliceDeBaseDUnePisteDeriveeEstDuCabinet(): void
    {
        $s = $this->semer();
        $avenantA = $this->premier(Avenant::class, $s['entA']);
        $avenantB = $this->premier(Avenant::class, $s['entB']);
        $this->connecterB($s);

        $this->client->request('GET', '/admin/piste/api/get-form?idAvenant=' . $avenantA);
        $this->exigerLeRefus("Dériver une piste de la police #$avenantA du cabinet A");

        $this->client->request('GET', '/admin/piste/api/get-form?idAvenant=' . $avenantB);
        $this->exigerLAcces("Dériver une piste de sa propre police #$avenantB");
    }

    /** NoteController get-form : `?parent_id=` d'un bordereau à facturer. */
    public function testLeBordereauAFacturerEstDuCabinet(): void
    {
        $s = $this->semer();
        $bordereauA = $this->premier(Bordereau::class, $s['entA']);
        $bordereauB = $this->premier(Bordereau::class, $s['entB']);
        $this->connecterB($s);

        $this->client->request('GET', '/admin/note/api/get-form?parent_field_name=bordereau&parent_id=' . $bordereauA);
        $this->exigerLeRefus("Facturer le bordereau #$bordereauA du cabinet A");

        $this->client->request('GET', '/admin/note/api/get-form?parent_field_name=bordereau&parent_id=' . $bordereauB);
        $this->exigerLAcces("Facturer son propre bordereau #$bordereauB");
    }

    // NoteController submit (`parent_id` d'un bordereau, posé en beforePersist) : corrigé,
    // mais NON ÉPROUVABLE ici. La création d'une note par le dialogue générique échoue en
    // 422 (« Référence : ce champ est obligatoire ») AVANT d'atteindre beforePersist : la
    // référence, champ désactivé, n'est posée qu'après le contrôle des champs requis. Un
    // test écrit aujourd'hui passerait au vert pour une mauvaise raison.

    /** RolesEn* get-form : `?idInvite=` — un jeu de rôles distribue des DROITS. */
    public function testUnJeuDeRolesNeSOuvrePasSurLInviteDAutrui(): void
    {
        $s = $this->semer();
        $this->connecterB($s);

        foreach (['rolesenadministration', 'rolesenfinance', 'rolesenmarketing', 'rolesenproduction', 'rolesensinistre'] as $module) {
            $this->client->request('GET', sprintf('/admin/%s/api/get-form?idInvite=%d', $module, $s['invA']));
            $this->exigerLeRefus("Ouvrir les rôles « $module » de l'invité #{$s['invA']} du cabinet A");

            $this->client->request('GET', sprintf('/admin/%s/api/get-form?idInvite=%d', $module, $s['invB']));
            $this->exigerLAcces("Ouvrir les rôles « $module » de son propre invité");
        }
    }

    // ── B : ENTITÉ `{id}` RÉSOLUE PAR LA ROUTE ──────────────────────────────

    /** Le jeton CSRF que le tableau de bord du cabinet B rend pour ses boutons. */
    private function jetonDuTableauDeBord(int $entB, string $bloc, string $meta): string
    {
        $crawler = $this->client->request('GET', sprintf('/admin/entreprise_dashbord/block/%s/%d', $bloc, $entB));
        $noeud = $crawler->filter('meta#' . $meta);
        self::assertCount(1, $noeud, "Le bloc « $bloc » ne rend plus son jeton « $meta » : le refus ne serait pas prouvé.");

        return (string) $noeud->attr('content');
    }

    public function testClotureDUnePisteEtDUneTacheDAutrui(): void
    {
        $s = $this->semer();
        $pisteA = $this->premier(Piste::class, $s['entA']);
        $pisteB = $this->premier(Piste::class, $s['entB']);
        $tacheA = $this->premier(Tache::class, $s['entA']);
        $tacheB = $this->premier(Tache::class, $s['entB']);
        $this->connecterB($s);

        // UN JETON VALIDE, sans quoi le 403 du CSRF passerait pour la protection.
        $jetonPiste = $this->jetonDuTableauDeBord($s['entB'], 'pistes', 'db-piste-csrf');
        $this->client->request('POST', '/admin/piste/api/close/' . $pisteA, [], [], ['HTTP_X_CSRF_TOKEN' => $jetonPiste]);
        $this->exigerLeRefus("Clôturer la piste #$pisteA du cabinet A");
        $this->em()->clear();
        self::assertFalse($this->em()->find(Piste::class, $pisteA)->isClosed(), 'La piste du cabinet A a été clôturée.');

        $this->client->request('POST', '/admin/piste/api/close/' . $pisteB, [], [], ['HTTP_X_CSRF_TOKEN' => $jetonPiste]);
        $this->exigerLAcces("Clôturer sa propre piste #$pisteB");

        $jetonTache = $this->jetonDuTableauDeBord($s['entB'], 'tasks', 'db-task-csrf');
        $this->client->request('POST', '/admin/tache/api/close/' . $tacheA, [], [], ['HTTP_X_CSRF_TOKEN' => $jetonTache]);
        $this->exigerLeRefus("Clôturer la tâche #$tacheA du cabinet A");
        $this->em()->clear();
        self::assertFalse((bool) $this->em()->find(Tache::class, $tacheA)->isClosed(), 'La tâche du cabinet A a été clôturée.');

        $this->client->request('POST', '/admin/tache/api/close/' . $tacheB, [], [], ['HTTP_X_CSRF_TOKEN' => $jetonTache]);
        $this->exigerLAcces("Clôturer sa propre tâche #$tacheB");
    }

    public function testLesNotesDAutruiNeSAffichentNiNeSeTelechargent(): void
    {
        $s = $this->semer();
        $noteA = $this->premier(Note::class, $s['entA']);
        $noteB = $this->premier(Note::class, $s['entB']);
        $this->connecterB($s);

        // Le semis nomme ses monnaies « USA » / « USB » : l'aperçu écrit le montant en
        // lettres, et la bibliothèque refuse un code qui n'existe pas. Le cabinet B reçoit
        // donc un vrai code, pour que le geste légitime soit jugé sur ce qu'il est.
        $this->em()->getConnection()->executeStatement(
            "UPDATE monnaie SET code = 'USD' WHERE entreprise_id = ?",
            [$s['entB']],
        );

        foreach (['/admin/note/api/get-preview-url/%d', '/admin/note/apercu/%d', '/admin/note/workspace-apercu/%d', '/admin/note/download-pdf/%d'] as $gabarit) {
            $this->client->request('GET', sprintf($gabarit, $noteA));
            $this->exigerLeRefus(sprintf('GET ' . $gabarit . ' (note du cabinet A)', $noteA));

            $this->client->request('GET', sprintf($gabarit, $noteB));
            $this->exigerLAcces(sprintf('GET ' . $gabarit . ' (sa propre note)', $noteB));
        }
    }

    public function testLesBordereauxDAutruiNeSOuvrentNiNeBougent(): void
    {
        $s = $this->semer();
        $bordereauA = $this->premier(Bordereau::class, $s['entA']);
        $bordereauB = $this->premier(Bordereau::class, $s['entB']);
        $this->em()->clear();
        $etapeAvant = $this->em()->find(Bordereau::class, $bordereauA)->getCurrentAnalysisStep();
        $this->connecterB($s);

        foreach ([
            '/admin/bordereau/api/get-analysis-url/%d',
            '/admin/bordereau/api/get-linked-note-preview-url/%d',
            '/admin/bordereau/api/get-linked-note-context/%d',
            '/admin/bordereau/analyse/%d',
            '/admin/bordereau/workspace-apercu/%d',
            '/admin/bordereau/api/export-analysis-pdf/%d',
        ] as $gabarit) {
            $this->client->request('GET', sprintf($gabarit, $bordereauA));
            $this->exigerLeRefus(sprintf('GET ' . $gabarit . ' (bordereau du cabinet A)', $bordereauA));
        }

        $json = ['CONTENT_TYPE' => 'application/json'];
        foreach ([
            ['/admin/bordereau/api/submit-analysis/%d?mode=init', '{}'],
            ['/admin/bordereau/api/save-analysis-state/%d', '{"selectedSheetName":"Feuille forgée"}'],
            ['/admin/bordereau/api/simulate-action/%d', '{"action_type":"new","row_index":0}'],
            ['/admin/bordereau/api/simulate-batch-action/%d', '{"items":[{"action_type":"new","row_index":0}]}'],
            ['/admin/bordereau/api/validate/%d', '{}'],
        ] as [$gabarit, $corps]) {
            $this->client->request('POST', sprintf($gabarit, $bordereauA), [], [], $json, $corps);
            $this->exigerLeRefus(sprintf('POST ' . $gabarit . ' (bordereau du cabinet A)', $bordereauA));
        }

        $this->em()->clear();
        $apres = $this->em()->find(Bordereau::class, $bordereauA);
        self::assertSame($etapeAvant, $apres->getCurrentAnalysisStep(), "Le bordereau du cabinet A a changé d'étape.");
        self::assertNotSame('Feuille forgée', $apres->getSelectedSheetName(), "L'état d'analyse du cabinet A a été écrasé.");

        $this->client->request('GET', sprintf('/admin/bordereau/api/get-analysis-url/%d', $bordereauB));
        $this->exigerLAcces('GET get-analysis-url (son propre bordereau)');
    }

    /** simulate-action : l'`avenant_id` du corps désigne une police ensuite MISE À JOUR. */
    public function testUnePoliceDAutruiNeSeMetPasAJourDepuisSonBordereau(): void
    {
        $s = $this->semer();
        $bordereauB = $this->premier(Bordereau::class, $s['entB']);
        $avenantA = $this->premier(Avenant::class, $s['entA']);
        $this->em()->clear();
        $descriptionAvant = $this->em()->find(Avenant::class, $avenantA)->getDescription();
        $this->connecterB($s);

        $this->client->request('POST', '/admin/bordereau/api/simulate-action/' . $bordereauB, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action_type' => 'discrepancy',
            'avenant_id' => $avenantA,
            'row_index' => 0,
            'excel_data' => ['description' => 'Ecrasee depuis le cabinet voisin'],
        ]));
        $this->exigerLeRefus("Mettre à jour la police #$avenantA du cabinet A depuis son propre bordereau");
        $this->em()->clear();
        self::assertSame($descriptionAvant, $this->em()->find(Avenant::class, $avenantA)->getDescription());
    }

    public function testRelancerLInvitationDAutrui(): void
    {
        $s = $this->semer();
        $this->connecterB($s);

        $this->client->request('POST', '/admin/invite/api/resend-invitation/' . $s['invA']);
        $this->exigerLeRefus("Relancer l'invitation #{$s['invA']} du cabinet A");

        $this->client->request('POST', '/admin/invite/api/resend-invitation/' . $s['invB']);
        $this->exigerLAcces('Relancer sa propre invitation');
    }

    /** Cotation `{id}/tranches` : la tranche par défaut s'écrivait AVANT tout contrôle. */
    public function testAucuneTrancheNEstCreeeSurLaCotationDAutrui(): void
    {
        $s = $this->semer();
        $em = $this->em();
        $pisteA = $em->find(Piste::class, $this->premier(Piste::class, $s['entA']));
        $nue = (new Cotation())->setNom('Cotation sans tranche A')->setDuree(12);
        $nue->setPiste($pisteA)->setEntreprise($pisteA->getEntreprise());
        $em->persist($nue);
        $em->flush();
        $idNue = $nue->getId();
        $em->clear();
        $this->connecterB($s);

        $this->client->request('GET', "/admin/cotation/api/$idNue/tranches/generic");
        $this->exigerLeRefus("Lister les tranches de la cotation #$idNue du cabinet A");
        $em->clear();
        self::assertCount(0, $em->find(Cotation::class, $idNue)->getTranches(), 'Une tranche a été écrite sur la cotation du cabinet A.');
    }

    // ── C : CABINET OU INVITÉ PRIS DANS LE CHEMIN ───────────────────────────

    /** Les 45 rubriques : premier chargement et rafraîchissement. */
    public function testLesRubriquesNeListentQueLeCabinetOuvert(): void
    {
        $s = $this->semer();
        $this->connecterB($s);

        $index = $this->cheminsDuRouteur('/index/{idInvite}/{idEntreprise}');
        $requetes = $this->cheminsDuRouteur('/api/dynamic-query/{idInvite}/{idEntreprise}');
        self::assertGreaterThanOrEqual(self::ROUTES_INDEX_AU_MOINS, \count($index));
        self::assertGreaterThanOrEqual(self::ROUTES_DYNAMIC_QUERY_AU_MOINS, \count($requetes));

        $url = fn (string $chemin, int $ent): string => strtr($chemin, ['{idInvite}' => $s['invB'], '{idEntreprise}' => $ent]);
        $corps = '{"criteria":{},"page":1}';

        $franchies = [];
        foreach ($index as $chemin) {
            $this->client->request('GET', $url($chemin, $s['entA']));
            if ($this->code() !== Response::HTTP_NOT_FOUND) {
                $franchies[] = sprintf('GET %s (HTTP %d)', $url($chemin, $s['entA']), $this->code());
            }
        }
        foreach ($requetes as $chemin) {
            $this->client->request('POST', $url($chemin, $s['entA']), [], [], ['CONTENT_TYPE' => 'application/json'], $corps);
            if ($this->code() !== Response::HTTP_NOT_FOUND) {
                $franchies[] = sprintf('POST %s (HTTP %d)', $url($chemin, $s['entA']), $this->code());
            }
        }
        self::assertSame([], $franchies, "Ces rubriques listent le cabinet A pour un invité du cabinet B :\n  " . implode("\n  ", $franchies));

        // Le même rafraîchissement dans SON cabinet passe partout.
        $refusees = [];
        foreach ($requetes as $chemin) {
            $this->client->request('POST', $url($chemin, $s['entB']), [], [], ['CONTENT_TYPE' => 'application/json'], $corps);
            if ($this->code() === Response::HTTP_NOT_FOUND || $this->code() >= 500) {
                $refusees[] = sprintf('POST %s (HTTP %d) %s', $url($chemin, $s['entB']), $this->code(), $this->exception());
            }
        }
        self::assertSame([], $refusees, "Ces rubriques refusent leur PROPRE cabinet :\n  " . implode("\n  ", $refusees));
    }

    /** L'invité de la route ne désigne plus rien : c'est celui de la session qui compte. */
    public function testLInviteDeLaRouteNeChangeRienALaListeDesTaches(): void
    {
        $s = $this->semer();
        $this->connecterB($s);
        $corps = '{"criteria":{},"page":1}';

        $this->client->request('POST', sprintf('/admin/tache/api/dynamic-query/%d/%d', $s['invB'], $s['entB']), [], [], ['CONTENT_TYPE' => 'application/json'], $corps);
        $this->exigerLAcces('Lister ses tâches');
        $siennes = json_decode((string) $this->client->getResponse()->getContent(), true)['html'] ?? null;

        $this->client->request('POST', sprintf('/admin/tache/api/dynamic-query/%d/%d', $s['invA'], $s['entB']), [], [], ['CONTENT_TYPE' => 'application/json'], $corps);
        $this->exigerLAcces("Lister ses tâches en nommant l'invité #{$s['invA']} du cabinet A");
        $forgees = json_decode((string) $this->client->getResponse()->getContent(), true)['html'] ?? null;

        self::assertNotNull($siennes);
        self::assertSame($siennes, $forgees, "L'invité porté par la route change encore la liste des tâches.");
    }

    public function testLeTableauDeBordNeMontreQueLeCabinetOuvert(): void
    {
        $s = $this->semer();
        $this->connecterB($s);

        // Chaque bloc et fragment dont le SEUL paramètre est le cabinet. `index` est à part :
        // il renvoie déjà au choix d'espace quand l'URL ne désigne pas le cabinet ouvert.
        $chemins = array_values(array_filter(
            $this->cheminsDuRouteur('/admin/entreprise_dashbord/'),
            static fn (string $c): bool => substr_count($c, '{') === 1 && str_contains($c, '{idEntreprise}')
                && $c !== '/admin/entreprise_dashbord/{idEntreprise}',
        ));
        self::assertGreaterThanOrEqual(self::ROUTES_TABLEAU_DE_BORD_AU_MOINS, \count($chemins));

        $franchies = [];
        $refusees = [];
        foreach ($chemins as $chemin) {
            $this->client->request('GET', str_replace('{idEntreprise}', (string) $s['entA'], $chemin));
            if ($this->code() !== Response::HTTP_NOT_FOUND) {
                $franchies[] = sprintf('%s (HTTP %d)', $chemin, $this->code());
            }
            $this->client->request('GET', str_replace('{idEntreprise}', (string) $s['entB'], $chemin));
            if ($this->code() === Response::HTTP_NOT_FOUND || $this->code() >= 500) {
                $refusees[] = sprintf('%s (HTTP %d) %s', $chemin, $this->code(), $this->exception());
            }
        }

        self::assertSame([], $franchies, "Ces blocs du tableau de bord montrent le cabinet A :\n  " . implode("\n  ", $franchies));
        self::assertSame([], $refusees, "Ces blocs refusent leur PROPRE cabinet :\n  " . implode("\n  ", $refusees));
    }

    /** resolveWorkspace() : Assistant, Documents comptables, Échange. */
    public function testLesEspacesAResolveWorkspaceNeServentQueLeCabinetOuvert(): void
    {
        $s = $this->semer();
        $this->connecterB($s);

        $chemins = [
            '/admin/assistant-ia/workspace/{idEntreprise}',
            '/admin/assistant-ia/workspace-parametres/{idEntreprise}',
            '/admin/assistant-ia/api/dialog-context/{idEntreprise}',
            '/admin/document-comptable/workspace/{idEntreprise}',
            '/admin/document-comptable/export/{idEntreprise}',
            '/admin/echange/workspace/{idEntreprise}',
            '/admin/echange/export/{idEntreprise}',
            '/admin/echange/gabarit/{idEntreprise}',
        ];
        foreach ($chemins as $chemin) {
            $this->client->request('GET', str_replace('{idEntreprise}', (string) $s['entA'], $chemin));
            $this->exigerLeRefus("GET $chemin (cabinet A)");
        }

        foreach (['/admin/document-comptable/workspace/{idEntreprise}', '/admin/echange/workspace/{idEntreprise}', '/admin/assistant-ia/workspace/{idEntreprise}'] as $chemin) {
            $this->client->request('GET', str_replace('{idEntreprise}', (string) $s['entB'], $chemin));
            $this->exigerLAcces("GET $chemin (son cabinet)");
        }
    }

    // ── D : REQUÊTE SANS FILTRE DE CABINET ──────────────────────────────────

    /** get-entities faisait un `findAll()` : toutes les fiches de tous les cabinets. */
    public function testGetEntitiesNeRendQueLesSiennes(): void
    {
        $s = $this->semer();
        $groupeA = $this->premier(Groupe::class, $s['entA']);
        $groupeB = $this->premier(Groupe::class, $s['entB']);
        $this->connecterB($s);

        $this->client->request('GET', '/espacedetravail/api/get-entities/Groupe/' . $s['entA']);
        $this->exigerLeRefus('get-entities sur le cabinet A');

        $this->client->request('GET', '/espacedetravail/api/get-entities/Groupe/' . $s['entB']);
        $this->exigerLAcces('get-entities sur son cabinet');
        $ids = array_map(static fn (array $g): int => (int) $g['id'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertContains($groupeB, $ids);
        self::assertNotContains($groupeA, $ids, 'get-entities rend encore le Groupe du cabinet A.');
    }

    // ── E : CONTRÔLE QUI LAISSAIT PASSER EN CAS DE DOUTE ────────────────────

    /**
     * DocumentController::cibleDAttachement ne comparait que les cibles portant un cabinet
     * non nul : une Operation (cabinet tenu de son bordereau) et une Entreprise passaient.
     */
    public function testAttacherUnDocumentAUneCibleDAutrui(): void
    {
        $s = $this->semer();
        $operationA = $this->premier(Operation::class, $s['entA']);
        $operationB = $this->premier(Operation::class, $s['entB']);
        $this->connecterB($s);

        $this->client->request('GET', '/admin/document/api/attacher/operation/' . $operationA);
        $this->exigerLeRefus("Attacher un document à l'opération #$operationA du cabinet A");
        $this->client->request('GET', '/admin/document/api/attacher/entrepriseRattachee/' . $s['entA']);
        $this->exigerLeRefus("Attacher un document au cabinet A lui-même");

        $this->client->request('GET', '/admin/document/api/attacher/operation/' . $operationB);
        $this->exigerLAcces("Attacher un document à sa propre opération #$operationB");
        $this->client->request('GET', '/admin/document/api/attacher/entrepriseRattachee/' . $s['entB']);
        $this->exigerLAcces('Attacher un document à son propre cabinet');
    }
}
