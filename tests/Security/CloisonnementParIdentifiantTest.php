<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * LE CLOISONNEMENT NE TIENT QU'AU NUMERO QU'ON DEMANDE.
 *
 * ── CE QUI REND CE TEST POSSIBLE ────────────────────────────────────────────
 * AUCUN `SQLFilter` Doctrine n'est declare dans ce projet : ni `filters:` dans
 * `config/packages/doctrine.yaml`, ni classe etendant `SQLFilter`, ni
 * `getFilters()->enable(...)`. Rien ne restreint donc un `->find($id)` au cabinet
 * ouvert, et il n'est meme pas besoin de basculer de workspace pour lire ailleurs.
 *
 * Le seul controle en place est `mayAccessEntity()`, qui ne juge que le TYPE de
 * l'entite au regard du perimetre de roles du demandeur. Ce n'est PAS une barriere
 * inter-cabinets :
 *   - l'inscription est publique (`RegistrationController`) ;
 *   - tout inscrit devient proprietaire de son cabinet ;
 *   - `WorkspaceAccessResolver::can()` rend `true` sans condition pour un
 *     proprietaire (l. 347), et `true` pour toute classe absente de sa carte
 *     (l. 365-367, repli permissif).
 *
 * Le motif « type verifie, instance non verifiee » se retrouve sur les TROIS verbes :
 *   - lecture      ControllerUtilsTrait:2274-2278  (get-entity-details, type LIBRE)
 *   - ecriture     ControllerUtilsTrait:1197-1211  (submit, id dans le CORPS)
 *   - suppression  ControllerUtilsTrait:1425-1430  (delete/{id})
 *
 * ── CE QU'IL DOIT FAIRE AUJOURD'HUI ─────────────────────────────────────────
 * ECHOUER. Il est ecrit AVANT le correctif, et chaque echec nomme la route, la
 * classe et l'identifiant qui ont franchi la frontiere.
 *
 * ── NON-VACUITE ─────────────────────────────────────────────────────────────
 * Memes exigences qu'AutocompleteEndpointsFermesTest, pour les memes raisons :
 *   - les routes viennent du ROUTEUR, jamais d'une liste ecrite ici ;
 *   - la classe visee vient de la REFLEXION sur le parametre type de l'action,
 *     donc d'une source que le code de production tient deja a jour ;
 *   - un plancher de routes : un routeur qui retrecit doit faire ECHOUER, pas vider ;
 *   - une classe hors de `CLASSES_SEMEES` n'est pas jugee, et la liste des classes
 *     ainsi ecartees est publiee par un test dedie -- « refuse » et « rien a
 *     montrer » resteraient sinon indistincts ;
 *   - une 5xx est un echec : un plantage n'est pas une protection.
 */
class CloisonnementParIdentifiantTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;

    /** Un routeur qui retrecit viderait ce fichier de sa substance. */
    private const ROUTES_FORMULAIRE_AU_MOINS = 40;
    private const ROUTES_SUPPRESSION_AU_MOINS = 40;
    private const CLASSES_DOCTRINE_AU_MOINS = 80;

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

    /**
     * Les routes dont le chemin contient $motif, avec la classe que leur action declare.
     *
     * La classe vient de la reflexion sur le parametre type de l'action -- celui que le
     * resolver d'entite de Symfony remplit. Ecrire ici une carte route -> classe
     * reviendrait a creer un second endroit a oublier.
     *
     * @return array<string, array{chemin: string, classe: class-string}>
     */
    private function routesAvecClasse(string $motif): array
    {
        $routes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $nom => $route) {
            if (!str_contains($route->getPath(), $motif)) {
                continue;
            }
            $controleur = (string) $route->getDefault('_controller');
            if (!str_contains($controleur, '::')) {
                continue;
            }
            [$classe, $methode] = explode('::', $controleur);
            foreach ((new \ReflectionMethod($classe, $methode))->getParameters() as $parametre) {
                $type = $parametre->getType();
                if ($type instanceof \ReflectionNamedType && str_starts_with($type->getName(), 'App\\Entity\\')) {
                    $routes[$nom] = ['chemin' => $route->getPath(), 'classe' => $type->getName()];
                    break;
                }
            }
        }

        return $routes;
    }

    /**
     * Le gabarit de chemin du detail generique, PRIS AU ROUTEUR.
     *
     * Ces routes sont prefixees (/admin/invite/api/..., /espacedetravail/api/...). Les
     * ecrire a la main ici donnait un 404 -- donc un test qui passait sans rien
     * eprouver. C'est la forme la plus silencieuse de la vacuite : une assertion
     * d'absence satisfaite par une URL qui n'existe pas.
     */
    private function gabaritDuDetail(): string
    {
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            if (str_contains($route->getPath(), 'get-entity-details')) {
                return $route->getPath();
            }
        }

        self::fail('Aucune route get-entity-details au routeur : ce test ne prouverait rien.');
    }

    /** Le chemin du detail pour un type court et un identifiant. */
    private function urlDuDetail(string $gabarit, string $court, int $id): string
    {
        return str_replace(['{entityType}', '{id}'], [$court, (string) $id], $gabarit);
    }
    /** Ouvre une session au nom du proprietaire du cabinet B. */
    private function connecterB(int $ownerId): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($ownerId));
    }

    /** Le premier identifiant que ce cabinet possede pour cette classe, sinon null. */
    private function unIdentifiantDe(string $classe, int $entrepriseId): ?int
    {
        $ids = $this->identifiantsDuCabinet($classe, $entrepriseId);

        return $ids[0] ?? null;
    }

    /**
     * LECTURE : le detail d'une entite d'autrui, par simple numero.
     *
     * `{entityType}` etant LIBRE, on balaie TOUTES les classes de la metadonnee
     * Doctrine -- pas une seule valeur : c'est ce balayage qui montre que la surface
     * n'est pas bornee, et qu'elle depasse les entites du cabinet (Coupon,
     * TokenPurchase, Utilisateur, Entreprise n'ont rien a faire dans un espace de
     * travail).
     */
    public function testLeDetailDUneEntiteDAutruiEstRefuse(): void
    {
        $seed = $this->semerLesDeuxCabinets();

        $classesDoctrine = [];
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta->isMappedSuperclass) {
                $classesDoctrine[] = $meta->getName();
            }
        }
        sort($classesDoctrine);

        self::assertGreaterThanOrEqual(self::CLASSES_DOCTRINE_AU_MOINS, \count($classesDoctrine), sprintf(
            'La metadonnee Doctrine ne declare plus que %d classes : le balayage de {entityType} '
            . 'porterait sur une surface reduite et passerait au vert sans rien prouver.',
            \count($classesDoctrine),
        ));

        // Tout acces au conteneur AVANT la premiere requete : le client ne redemarre pas
        // le noyau avant celle-ci, et des services partages garderaient leur etat.
        $cibles = [];
        foreach ($classesDoctrine as $classe) {
            if (!\in_array($classe, self::CLASSES_SEMEES, true)) {
                continue;
            }
            $id = $this->unIdentifiantDe($classe, $seed['a']['entreprise']);
            if ($id !== null) {
                $cibles[(new \ReflectionClass($classe))->getShortName()] = $id;
            }
        }
        $gabarit = $this->gabaritDuDetail();
        $this->connecterB($seed['b']['owner']);

        self::assertNotSame([], $cibles, 'Aucune cible semee : ce test ne prouverait rien.');

        $fuites = [];
        foreach ($cibles as $court => $id) {
            $this->client->request('GET', $this->urlDuDetail($gabarit, $court, $id));
            $reponse = $this->client->getResponse();

            self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $reponse->getStatusCode(), sprintf(
                'GET /api/get-entity-details/%s/%d plante (HTTP %d). Un plantage n\'est pas une '
                . 'protection.',
                $court,
                $id,
                $reponse->getStatusCode(),
            ));

            if ($reponse->getStatusCode() === Response::HTTP_OK) {
                $charge = json_decode((string) $reponse->getContent(), true);
                if (!empty($charge['entity'])) {
                    $fuites[] = sprintf('%s #%d', $court, $id);
                }
            }
        }

        self::assertSame([], $fuites, sprintf(
            "LECTURE INTER-CABINETS. Un invite du cabinet B obtient le detail d'entites du cabinet "
            . "A, par simple numero et sans aucune bascule :\n  %s\n"
            . 'ControllerUtilsTrait::getEntityDetailsForType() ne verifie que le TYPE '
            . '(mayAccessEntity, l. 2274) puis charge par ->find($id) (l. 2278) sans regarder '
            . "l'entreprise. Aucun SQLFilter ne rattrape cela.",
            implode("\n  ", $fuites),
        ));
    }

    /**
     * LA LISTE BLANCHE EXISTE DEJA -- et ce test la tient.
     *
     * Contrairement a ce qu'une lecture partielle de getEntityDetailsForType() laisse
     * croire, `{entityType}` n'est PAS libre : la methode commence par
     * `in_array($entityType, JSBDynamicSearchService::$allowedEntities)` (l. 2267) et
     * refuse tout le reste par un 403. Coupon, PlateformeParametres, InvoiceCounter,
     * TokenPurchase, ErreurApplicative, Utilisateur, les Crm* et Evaluation n'y figurent
     * pas : ils sont bel et bien fermes.
     *
     * Ce test fige cette fermeture -- c'est elle, et non le repli permissif du resolver,
     * qui borne la surface. Si quelqu'un ajoute un type de plateforme a la liste, il doit
     * l'apprendre ici.
     *
     * RESTE LE CAS D'ENTREPRISE. Elle EST dans la liste blanche, alors qu'elle designe
     * le cabinet lui-meme : la consulter, c'est lire l'objet locataire, et rien dans
     * l'espace de travail n'a besoin de lire celui des autres.
     */
    public function testSeulsLesTypesDuMetierSontConsultables(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $gabarit = $this->gabaritDuDetail();

        // UN IDENTIFIANT QUI EXISTE VRAIMENT, sinon le refus n'en est pas un : un 404
        // pour absence de ligne se lirait comme un refus.
        $horsMetier = ['Coupon', 'PlateformeParametres', 'InvoiceCounter', 'TokenPurchase',
            'ErreurApplicative', 'Utilisateur', 'CrmProfil', 'Evaluation'];
        $cibles = [];
        $introuvables = [];
        $horsPortee = [];
        $prefixe = 'App' . chr(92) . 'Entity' . chr(92);
        foreach ($horsMetier as $court) {
            $fqcn = $prefixe . $court;
            // L'endpoint construit la classe par CONCATENATION : une entite rangee dans
            // un sous-espace de noms (App\Entity\Crm\...) lui est inatteignable. Ce
            // n'est pas une decision d'acces, c'est un hasard de rangement.
            if (!class_exists($fqcn)) {
                $horsPortee[] = $court;
                continue;
            }
            $id = $this->em()->createQuery(sprintf('SELECT e.id FROM %s e', $fqcn))
                ->setMaxResults(1)->getOneOrNullResult()['id'] ?? null;
            if ($id === null) {
                $introuvables[] = $court;
                continue;
            }
            $cibles[$court] = (int) $id;
        }
        // Entreprise est jugee a part : elle est DANS la liste blanche.
        $idEntrepriseA = $seed['a']['entreprise'];
        $this->connecterB($seed['b']['owner']);

        self::assertNotSame([], $cibles, sprintf(
            'Aucun type hors metier n\'a pu etre eprouve (absents de la base : %s ; non nommables '
            . 'par concatenation : %s) : ce test ne prouverait rien.',
            implode(', ', $introuvables) ?: 'aucun',
            implode(', ', $horsPortee) ?: 'aucun',
        ));

        $servis = [];
        foreach ($cibles as $court => $idReel) {
            $this->client->request('GET', $this->urlDuDetail($gabarit, $court, $idReel));
            $code = $this->client->getResponse()->getStatusCode();
            if ($code !== Response::HTTP_FORBIDDEN && $code !== Response::HTTP_NOT_FOUND) {
                $servis[] = sprintf('%s #%d (HTTP %d)', $court, $idReel, $code);
            }
        }

        self::assertSame([], $servis, sprintf(
            "Ces types hors metier ne sont plus refuses par la liste blanche :
  %s
"
            . 'JSBDynamicSearchService::$allowedEntities est la seule chose qui borne la surface de '
            . "get-entity-details. Y ajouter un type de plateforme ou de console l'ouvre a tous les "
            . 'cabinets.',
            implode("
  ", $servis),
        ));

        // ENTREPRISE : dans la liste blanche, donc consultable -- y compris celle d'autrui.
        $this->client->request('GET', $this->urlDuDetail($gabarit, 'Entreprise', $idEntrepriseA));
        $reponse = $this->client->getResponse();
        $code = $reponse->getStatusCode();

        self::assertTrue(
            $code === Response::HTTP_FORBIDDEN || $code === Response::HTTP_NOT_FOUND,
            sprintf(
                "L'ENTREPRISE D'AUTRUI EST CONSULTABLE. Un invite du cabinet B demande le detail de "
                . "l'Entreprise #%d (cabinet A) et obtient HTTP %d%s.
"
                . "Entreprise figure dans JSBDynamicSearchService::\$allowedEntities alors qu'elle "
                . "designe le LOCATAIRE lui-meme. Aucun ecran de l'espace de travail n'a besoin de "
                . "lire le cabinet d'un autre.",
                $idEntrepriseA,
                $code,
                $code >= 500
                    ? ' (' . urldecode((string) $reponse->headers->get('X-Debug-Exception')) . ')'
                    : '',
            ),
        );
    }

    /**
     * EDITION : le formulaire d'une entite d'autrui s'ouvre-t-il ?
     *
     * `renderFormCanvas()` lit l'entreprise et l'invite dans la SESSION, puis ne controle
     * que le TYPE. L'entite, elle, a ete resolue par son numero dans l'URL.
     */
    public function testLeFormulaireDEditionDUneEntiteDAutruiEstRefuse(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $routes = $this->routesAvecClasse('/api/get-form/');

        self::assertGreaterThanOrEqual(self::ROUTES_FORMULAIRE_AU_MOINS, \count($routes), sprintf(
            'Le routeur n\'expose plus que %d routes get-form : ce test tournerait sur moins '
            . 'd\'entrees en passant au vert sans rien prouver.',
            \count($routes),
        ));

        $cibles = [];
        foreach ($routes as $nom => $route) {
            if (!\in_array($route['classe'], self::CLASSES_SEMEES, true)) {
                continue;
            }
            $id = $this->unIdentifiantDe($route['classe'], $seed['a']['entreprise']);
            if ($id !== null) {
                $cibles[$nom] = ['url' => str_replace(['{id?}', '{id}'], (string) $id, $route['chemin']), 'classe' => $route['classe'], 'id' => $id];
            }
        }
        $this->connecterB($seed['b']['owner']);

        self::assertNotSame([], $cibles, 'Aucune route semee : ce test ne prouverait rien.');

        $fuites = [];
        foreach ($cibles as $nom => $cible) {
            $this->client->request('GET', $cible['url']);
            $code = $this->client->getResponse()->getStatusCode();

            self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
                '%s plante (HTTP %d) sur une entite d\'un autre cabinet.',
                $cible['url'],
                $code,
            ));

            if ($code === Response::HTTP_OK) {
                $fuites[] = sprintf('%s -> %s #%d', $nom, $cible['classe'], $cible['id']);
            }
        }

        self::assertSame([], $fuites, sprintf(
            "EDITION INTER-CABINETS. Le formulaire d'entites du cabinet A s'ouvre pour un invite "
            . "du cabinet B :\n  %s",
            implode("\n  ", $fuites),
        ));
    }

    /**
     * SUPPRESSION : le verbe le plus grave, et le moins garde.
     *
     * `handleDeleteApi()` ne fait qu'un `mayAccessEntity($entity, SUPPRESSION)` -- le
     * TYPE -- sans meme passer par `validateWorkspaceAccess()`.
     *
     * On verifie la REPONSE et la SURVIE de la ligne : un refus qui supprimerait quand
     * meme ne vaudrait rien, et une 404 apres coup pourrait venir de la suppression
     * elle-meme.
     */
    public function testLaSuppressionDUneEntiteDAutruiEstRefusee(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $routes = $this->routesAvecClasse('/api/delete/');

        self::assertGreaterThanOrEqual(self::ROUTES_SUPPRESSION_AU_MOINS, \count($routes), sprintf(
            'Le routeur n\'expose plus que %d routes delete.',
            \count($routes),
        ));

        $cibles = [];
        foreach ($routes as $nom => $route) {
            if (!\in_array($route['classe'], self::CLASSES_SEMEES, true)) {
                continue;
            }
            $id = $this->unIdentifiantDe($route['classe'], $seed['a']['entreprise']);
            if ($id !== null) {
                $cibles[$nom] = ['url' => str_replace('{id}', (string) $id, $route['chemin']), 'classe' => $route['classe'], 'id' => $id];
            }
        }
        $this->connecterB($seed['b']['owner']);

        self::assertNotSame([], $cibles, 'Aucune route semee : ce test ne prouverait rien.');

        $acceptees = [];
        foreach ($cibles as $nom => $cible) {
            $this->client->request('DELETE', $cible['url']);
            $code = $this->client->getResponse()->getStatusCode();

            self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
                '%s plante (HTTP %d).',
                $cible['url'],
                $code,
            ));

            if ($code < 300) {
                $acceptees[] = sprintf('%s -> %s #%d', $nom, $cible['classe'], $cible['id']);
            }
        }

        self::assertSame([], $acceptees, sprintf(
            "SUPPRESSION INTER-CABINETS. Un invite du cabinet B supprime des entites du cabinet "
            . "A :\n  %s\n"
            . 'handleDeleteApi() (ControllerUtilsTrait:1425-1430) ne controle que le TYPE, et ne '
            . "passe meme pas par validateWorkspaceAccess().",
            implode("\n  ", $acceptees),
        ));
    }

    /**
     * ECRITURE, CAS 1 : modifier une entite d'autrui en postant son numero.
     *
     * `handleFormSubmission()` charge l'entite par `$data['id']` et ne controle que le
     * TYPE. L'identifiant vient du CORPS, pas de l'URL : une route a `{id}` ne suffit
     * donc pas a faire l'inventaire des surfaces d'ecriture.
     */
    public function testModifierUneEntiteDAutruiParSonIdentifiantEstRefuse(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $id = $this->unIdentifiantDe(\App\Entity\Groupe::class, $seed['a']['entreprise']);
        $idEntrepriseB = $seed['b']['entreprise'];
        $idInviteB = $this->premiereInviteDe($seed['b']['owner'], $idEntrepriseB);
        $this->connecterB($seed['b']['owner']);

        self::assertNotNull($id, 'Aucun Groupe seme dans le cabinet A : ce test ne prouverait rien.');

        $this->client->request('POST', '/admin/groupe/api/submit', [
            'id' => $id,
            'idEntreprise' => $idEntrepriseB,
            'idInvite' => $idInviteB,
            'nom' => 'Renomme par le cabinet voisin',
            'description' => 'Modification inter-cabinets',
        ]);
        $code = $this->client->getResponse()->getStatusCode();

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
            'POST /admin/groupe/api/submit plante (HTTP %d).',
            $code,
        ));
        self::assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $code, sprintf(
            'ECRITURE INTER-CABINETS. Un invite du cabinet B modifie le Groupe #%d du cabinet A en '
            . "postant simplement son identifiant (HTTP %d).\n"
            . 'handleFormSubmission() (ControllerUtilsTrait:1197-1204) charge par $data[\'id\'] et '
            . 'ne controle que le TYPE.',
            $id,
            $code,
        ));
    }

    /**
     * ECRITURE, CAS 2 : creer DANS le cabinet d'autrui.
     *
     * A la creation, l. 1209-1211 pose `setEntreprise($currentEntreprise)` -- une
     * entreprise tiree des identifiants SOUMIS, valides par `validateWorkspaceAccess()`
     * qui verifie la coherence invite <-> entreprise mais JAMAIS l'appartenance de
     * l'invite a l'utilisateur authentifie.
     */
    public function testCreerDansLeCabinetDAutruiEstRefuse(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $idEntrepriseA = $seed['a']['entreprise'];
        $idInviteA = $this->premiereInviteDe($seed['a']['owner'], $idEntrepriseA);
        $avant = \count($this->identifiantsDuCabinet(\App\Entity\Groupe::class, $idEntrepriseA));
        $this->connecterB($seed['b']['owner']);

        $this->client->request('POST', '/admin/groupe/api/submit', [
            'idEntreprise' => $idEntrepriseA,
            'idInvite' => $idInviteA,
            'nom' => 'Groupe pose chez le voisin',
            'description' => 'Creation inter-cabinets',
        ]);
        $code = $this->client->getResponse()->getStatusCode();

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
            'POST /admin/groupe/api/submit plante (HTTP %d).',
            $code,
        ));

        $apres = \count($this->identifiantsDuCabinet(\App\Entity\Groupe::class, $idEntrepriseA));
        self::assertSame($avant, $apres, sprintf(
            'CREATION DANS LE CABINET D\'AUTRUI. Le cabinet A comptait %d Groupe, il en compte %d '
            . "apres un POST emis par un invite du cabinet B portant les identifiants d'espace de "
            . "travail de A (HTTP %d).\n"
            . 'validateWorkspaceAccess() (ControllerUtilsTrait:402) verifie que l\'invite appartient '
            . 'a l\'entreprise, jamais que l\'invite appartient a l\'UTILISATEUR CONNECTE.',
            $avant,
            $apres,
            $code,
        ));
    }

    /**
     * LA BASCULE : prendre le cabinet d'autrui pour cabinet actif.
     *
     * Deux chemins, inegalement gardes, et tous deux persistent `connectedTo` :
     *   - EntrepriseDashbordController:73, aucune validation du tout ;
     *   - EspaceDeTravailComponentController:96 et :315, precedes de
     *     validateWorkspaceAccess(), qui ne verifie pas l'appartenance a l'utilisateur.
     *
     * C'est la cle de voute : `setFiltreEntreprise()` filtre sur `getConnectedTo()`.
     */
    public function testLaBasculeVersLeCabinetDAutruiEstRefusee(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $idEntrepriseA = $seed['a']['entreprise'];
        $idEntrepriseB = $seed['b']['entreprise'];
        $idInviteA = $this->premiereInviteDe($seed['a']['owner'], $idEntrepriseA);
        $this->connecterB($seed['b']['owner']);

        $chemins = [
            'tableau de bord' => sprintf('/admin/entreprise_dashbord/%d', $idEntrepriseA),
            'espace de travail' => sprintf('/espacedetravail/%d/%d', $idInviteA, $idEntrepriseA),
        ];

        $bascules = [];
        $plantages = [];
        foreach ($chemins as $libelle => $url) {
            $this->client->request('GET', $url);
            $reponse = $this->client->getResponse();
            $code = $reponse->getStatusCode();

            // ON REGARDE LE CABINET ACTIF MEME APRES UNE 500. `setConnectedTo()` et son
            // `flush()` surviennent TOT dans l'action (EntrepriseDashbordController:73) :
            // un plantage survenu ensuite n'annule pas l'ecriture. Juger la reponse sans
            // relire l'etat laisserait croire a une protection la ou il n'y a qu'un
            // accident.
            if ($code >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                $plantages[] = sprintf('%s (HTTP %d : %s)', $libelle, $code, urldecode(
                    (string) $reponse->headers->get('X-Debug-Exception'),
                ));
            }

            $actif = $this->em()->getRepository(Utilisateur::class)
                ->find($seed['b']['owner'])?->getConnectedTo()?->getId();
            if ($actif === $idEntrepriseA) {
                $bascules[] = sprintf('%s (%s, HTTP %d)', $libelle, $url, $code);
                // On remet le cabinet actif a B pour juger le chemin suivant isolement.
                $this->remettreLeCabinetActif($seed['b']['owner'], $idEntrepriseB);
            }
        }

        self::assertSame([], $bascules, sprintf(
            "BASCULE VERS LE CABINET D'AUTRUI. Le compte du cabinet B a pris le cabinet A pour "
            . "cabinet actif, et la valeur est PERSISTEE sur Utilisateur :\n  %s\n"
            . 'Des lors, setFiltreEntreprise() filtre sur le cabinet de la victime, et tout ce qui '
            . "lit connectedTo le suit. La bascule doit exiger une Invite appartenant a "
            . "l'utilisateur courant.",
            implode("\n  ", $bascules),
        ));
    }

    /**
     * LE REPLI : une Invite revoquee laisse-t-elle l'acces ouvert ?
     *
     * `Invite` n'a aucun etat d'acceptation ni de revocation : revoquer, c'est supprimer
     * la ligne. Or aucun code ne remet `connectedTo` a zero -- aucun listener, aucun
     * subscriber. Et `getInvite()` (ControllerUtilsTrait:430-440) retombe alors sur
     * `findOneBy(['utilisateur' => $user])`, c'est-a-dire sur un invite ARBITRAIRE d'un
     * autre cabinet.
     *
     * Le perimetre de roles viendrait donc d'un cabinet pendant que `connectedTo` en
     * designe un autre.
     */
    public function testUneInviteRevoqueeFermeLAcces(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        // Le compte de B est aussi invite de A, et bascule sur A.
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        // Puis son acces a A est REVOQUE : la ligne d'Invite disparait.
        $this->revoquerLInvite($seed['b']['owner'], $seed['a']['entreprise']);

        $idGroupeA = $this->unIdentifiantDe(\App\Entity\Groupe::class, $seed['a']['entreprise']);
        $gabarit = $this->gabaritDuDetail();
        $this->connecterB($seed['b']['owner']);

        self::assertNotNull($idGroupeA, 'Aucun Groupe seme dans A : ce test ne prouverait rien.');

        $this->client->request('GET', $this->urlDuDetail($gabarit, 'Groupe', $idGroupeA));
        $code = $this->client->getResponse()->getStatusCode();

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
            'La lecture apres revocation plante (HTTP %d).',
            $code,
        ));

        $charge = $code === Response::HTTP_OK
            ? json_decode((string) $this->client->getResponse()->getContent(), true)
            : [];

        self::assertEmpty($charge['entity'] ?? [], sprintf(
            "REVOCATION SANS EFFET. L'Invite du compte dans le cabinet A a ete supprimee, mais son "
            . "connectedTo designe toujours A et la lecture du Groupe #%d reussit (HTTP %d).\n"
            . 'Rien ne remet connectedTo a zero, et le repli de getInvite() '
            . "(ControllerUtilsTrait:430-440) rattrape l'absence d'invite en prenant un invite "
            . "arbitraire d'un autre cabinet.",
            $idGroupeA,
            $code,
        ));
    }

    /**
     * LES RECETTES QUI MANQUENT, publiees plutot que tues.
     *
     * Les tests ci-dessus n'osent juger que les classes semees. Celles qui ne le sont
     * pas doivent etre VISIBLES : tant qu'elles manquent, le correctif ne peut pas etre
     * declare vert, puisque rien ne l'eprouve sur elles.
     *
     * Ce test ne bloque pas le constat de fuite -- il le complete.
     */
    public function testLeSemisCouvreToutesLesClassesVisees(): void
    {
        $visees = [];
        foreach (['/api/get-form/', '/api/delete/'] as $motif) {
            foreach ($this->routesAvecClasse($motif) as $route) {
                $visees[$route['classe']] = true;
            }
        }
        $sansRecette = array_values(array_diff(array_keys($visees), self::CLASSES_SEMEES));
        sort($sansRecette);

        self::assertSame([], $sansRecette, sprintf(
            "%d classes sur %d sont visees par une route /api/get-form ou /api/delete sans qu'aucune "
            . "recette de semis ne les cree :\n  %s\n"
            . 'Tant qu\'elles manquent, les tests de ce fichier les ecartent, et le cloisonnement '
            . "n'est prouve que sur les %d autres. Ajoutez-les a SemisDeDeuxCabinetsTrait::"
            . 'semerUnCabinet(), puis a CLASSES_SEMEES.',
            \count($sansRecette),
            \count($visees),
            implode("\n  ", $sansRecette),
            \count($visees) - \count($sansRecette),
        ));
    }
}
