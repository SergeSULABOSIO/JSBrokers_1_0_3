<?php

namespace App\Tests\Security;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * UN ATTRIBUT SUFFIT A OUVRIR UNE URL.
 *
 * ── CE QUI S'EST PASSE ──────────────────────────────────────────────────────
 * `#[AsEntityAutocompleteField]` inscrit un champ au registre d'autocompletion, et
 * `EntityAutocompleteController` sert `/autocomplete/{alias}` PAR ALIAS, sans jamais
 * regarder si un formulaire emploie le champ. Sur vingt champs, DIX-HUIT scopaient par
 * `FormListenerFactory::setFiltreEntreprise()`. Les deux autres fuyaient, et tous deux
 * n'etaient employes par AUCUN formulaire -- seul l'attribut ouvrait leur URL :
 *
 *   - `EntrepriseAutocompleteField`, qui n'a JAMAIS eu de `query_builder` : squelette de
 *     `make:autocomplete-field` jamais rempli (placeholder anglais, `choice_label` encore
 *     en commentaire). Mesure faite AVANT correction :
 *     `/autocomplete/entreprise_autocomplete_field` repondait HTTP 200, deux resultats,
 *     LE NOM DES CABINETS EN CLAIR, sans aucune session.
 *
 *   - `ArticleAutocompleteField`, filtre COMMENTE des sa naissance avec pour motif
 *     « l'entite Article n'a pas de relation Entreprise directe ». C'etait faux : Article
 *     utilise AuditableTrait et la colonne `entreprise_id` est NOT NULL.
 *     FUITE GRAVE, contrairement a ce que ses colonnes laissent croire : Article ne
 *     stocke que `quantite`, mais son libelle etait bati sur deux proprietes CALCULEES
 *     par ArticleIndicatorStrategy -- `elementLie`, qui rend
 *     « <reference de police> - <nom du revenu> (<tranche> @<taux>% x <quantite>) »,
 *     et `montantArticle`, le montant REELLEMENT FACTURE. L'endpoint servait donc la
 *     reference de police, le type de revenu, l'echeance, LE TAUX DE COMMISSION et LE
 *     MONTANT des lignes de facture de tous les cabinets -- meme famille de donnees que
 *     la fuite Revenu/Tranche fermee par AutocompleteScopeEntrepriseTest.
 *     Supprime plutot que scope, pour trois raisons dans cet ordre : aucun formulaire ne
 *     l'emploie, Article n'a aucune colonne texte donc aucun `searchable_fields` valide
 *     n'est possible (il declarait `['nom']`, inexistant), et le motif ecrit en
 *     commentaire etait faux.
 *
 * ── LE PIEGE QUI A FAIT DURER ARTICLE ───────────────────────────────────────
 * Un `query_builder` COMMENTE trompe le grep : la ligne existe, donc toute recherche
 * textuelle « ce champ a-t-il un filtre ? » repond oui. Seul ce test fonctionnel fait foi.
 *
 * ── POURQUOI CE TEST SEME DEUX CABINETS ─────────────────────────────────────
 * Une liste vide a deux causes : « on te le refuse » et « il n'y a rien a montrer ». Un
 * test qui ne seme pas confond les deux et passe au vert sur une base vide -- c'est ce
 * qui aurait laisse passer Article, dont aucune ligne n'existait en base de test.
 *
 * Et l'anonymat ne suffit pas a eprouver le filtre : `RegistrationController` est
 * PUBLIC, donc n'importe qui peut s'authentifier ; et le jour ou `access_control`
 * fermera ^/autocomplete, les requetes anonymes ne testeront plus rien du tout. La
 * vraie question n'est donc pas « un anonyme voit-il quelque chose » mais « un courtier
 * authentifie voit-il le portefeuille de son concurrent ». On seme donc DEUX cabinets
 * complets, et l'on verifie pour CHAQUE alias que l'invite de B voit le sien et jamais
 * celui de A.
 *
 * ── POURQUOI IL ENUMERE LE REGISTRE ─────────────────────────────────────────
 * Les alias viennent de `AutocompleterRegistry::getAutocompleterNames()` et la classe de
 * `EntityAutocompleterInterface::getEntityClass()` : aucune carte ecrite ici, sans quoi
 * c'est elle qu'on oublierait de tenir. Un champ ajoute demain entre dans ce test seul.
 */
class AutocompleteEndpointsFermesTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;


    /**
     * PLANCHER D'ALIAS ATTENDUS.
     *
     * Un registre qui retrecit en silence viderait tous les tests de ce fichier de leur
     * substance : ils boucleraient sur moins d'alias et passeraient quand meme. C'est un
     * PLANCHER, pas une egalite -- ajouter un champ ne doit rien casser, en perdre un
     * doit le dire.
     */
    private const ALIAS_ATTENDUS_AU_MOINS = 18;

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


    private function registre(): object
    {
        return static::getContainer()->get('ux.autocomplete.autocompleter_registry');
    }

    /**
     * La classe visee par un alias -- SANS laisser l'autocompleter dans un etat qui fera
     * echouer la requete suivante.
     *
     * `WrappedEntityTypeAutocompleter::getEntityClass()` lit l'option `class` du
     * FORMULAIRE, donc il le construit et le garde en cache. Or le client ne redemarre
     * pas le noyau avant sa PREMIERE requete (`KernelBrowser::doRequest()`, l. 143-148) :
     * cette requete retrouve donc le meme service, avec son formulaire deja cree, et le
     * `setOptions()` que fait le controleur jette alors « The options can only be set
     * before the form is created » (l. 183-190). D'ou le `reset()`, que le bundle expose
     * exactement pour ca et appelle lui-meme entre deux requetes.
     */
    private function classeDeLAlias(string $alias): string
    {
        $autocompleter = $this->registre()->getAutocompleter($alias);
        $classe = $autocompleter->getEntityClass();

        if (method_exists($autocompleter, 'reset')) {
            $autocompleter->reset();
        }

        return $classe;
    }

    /** @return list<string> */
    private function aliasDuRegistre(): array
    {
        $alias = $this->registre()->getAutocompleterNames();
        sort($alias);

        self::assertGreaterThanOrEqual(self::ALIAS_ATTENDUS_AU_MOINS, \count($alias), sprintf(
            'Le registre n\'expose plus que %d alias, alors qu\'on en attend au moins %d. Soit un '
            . 'champ d\'autocompletion a disparu sans que ce plancher soit revu, soit la compilation '
            . 'du conteneur ne les enregistre plus -- et dans les deux cas, TOUTES les boucles de ce '
            . 'fichier tourneraient sur moins d\'entrees en passant au vert sans rien prouver.',
            \count($alias),
            self::ALIAS_ATTENDUS_AU_MOINS,
        ));

        return $alias;
    }





    /**
     * Les identifiants qu'un endpoint propose REELLEMENT.
     *
     * Le statut est verifie ici, et non devine : une 5xx rendait auparavant une liste
     * vide indistinguable d'un refus, et un echec transitoire s'est fait passer pour un
     * « champ muet ». Une panne doit se denoncer comme une panne.
     *
     * @return list<int>
     */
    private function identifiantsProposes(string $alias): array
    {
        $this->client->request('GET', '/autocomplete/' . $alias);
        $reponse = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode(), sprintf(
            'L\'endpoint « %s » repond HTTP %d a un invite de son propre cabinet. Tant qu\'il ne '
            . 'repond pas 200, ni la presence ni l\'absence ne peuvent etre jugees : une liste vide '
            . 'par panne se lirait comme une liste vide par refus.',
            $alias,
            $reponse->getStatusCode(),
        ));

        $charge = json_decode((string) $reponse->getContent(), true);
        self::assertIsArray($charge, sprintf('La reponse de « %s » n\'est pas du JSON.', $alias));

        $options = $charge['results']['options'] ?? $charge['results'] ?? [];
        $ids = [];
        foreach (\is_array($options) ? $options : [] as $option) {
            if (isset($option['value'])) {
                $ids[] = (int) $option['value'];
            }
        }

        return $ids;
    }


    /**
     * SANS SESSION, AUCUN ALIAS NE DOIT RENDRE LA MOINDRE LIGNE.
     *
     * ⚠ DEPUIS L'`access_control` SUR ^/autocomplete, CE TEST NE CONTROLE PLUS LE FILTRE.
     *
     * La regle `- { path: ^/autocomplete, roles: IS_AUTHENTICATED }` arrete la requete AU
     * PARE-FEU : elle redirige vers la connexion avant que le moindre `query_builder` ne
     * soit consulte. Ce qui est verifie ici est donc le PARE-FEU, et plus le cloisonnement.
     *
     * Le controle du filtre est porte, seul, par les tests CONNECTES --
     * `testAucunAliasNeMontreLeCabinetVoisin` et
     * `testUnInviteDesDeuxCabinetsNeVoitQueLeCabinetActif`. Les supprimer en croyant que
     * celui-ci les double rouvrirait la fuite inter-cabinets sans qu'aucun test ne tombe.
     *
     * Trois formes de refus restent admises -- liste vide, 401/403, ou redirection vers la
     * connexion -- pour que ce test survive a un changement de la regle, dans un sens comme
     * dans l'autre.
     */
    public function testAucunAliasNeSertDeDonneesAUnAppelantAnonyme(): void
    {
        $this->semerLesDeuxCabinets();

        foreach ($this->aliasDuRegistre() as $alias) {
            $this->client->request('GET', '/autocomplete/' . $alias);
            $reponse = $this->client->getResponse();
            $code = $reponse->getStatusCode();

            self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
                'L\'endpoint « %s » plante (HTTP %d) pour un appelant anonyme. Un plantage n\'est PAS '
                . 'une protection : il signale un champ mal declare, et la prochaine version du code '
                . 'pourrait le transformer en reponse valide, donc en fuite.',
                $alias,
                $code,
            ));

            if (\in_array($code, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
                continue; // refus net
            }

            if ($code >= 300 && $code < 400) {
                self::assertStringContainsString('/login', (string) $reponse->headers->get('Location'), sprintf(
                    'L\'endpoint « %s » redirige un anonyme ailleurs que vers la connexion.',
                    $alias,
                ));
                continue; // refus par redirection
            }

            self::assertSame(Response::HTTP_OK, $code, sprintf('Reponse inattendue de « %s ».', $alias));

            $charge = json_decode((string) $reponse->getContent(), true);
            $options = \is_array($charge) ? ($charge['results']['options'] ?? $charge['results'] ?? []) : [];
            self::assertSame([], \is_array($options) ? $options : [], sprintf(
                'FUITE SANS AUTHENTIFICATION : « %s » sert des lignes a un appelant qui n\'a pas de '
                . 'session. Le prefixe /autocomplete n\'est couvert par aucune regle access_control, et '
                . '`security` vaut false par defaut dans le bundle : le seul rempart est le '
                . '`query_builder` du champ, qui doit passer par '
                . 'FormListenerFactory::setFiltreEntreprise() (fail-closed : entreprise -1 sans session).',
                $alias,
            ));
        }
    }

    /**
     * LE TEST QUI COMPTE VRAIMENT : UN COURTIER NE VOIT PAS SON CONCURRENT.
     *
     * L'anonymat n'est pas une barriere -- `RegistrationController` est public, donc
     * n'importe qui peut obtenir une session -- et le jour ou `access_control` fermera
     * ^/autocomplete, le test anonyme ne pourra plus rien dire du filtre. C'est ici que
     * le cloisonnement se prouve, pour CHAQUE alias.
     *
     * Les deux assertions vont ensemble : « le voisin est absent » ne vaut que si « le
     * sien est present » dans la MEME reponse. Une reponse tronquee, un filtre qui vide
     * tout, un semis qui ne couvre plus la classe : chacun rendrait la premiere
     * assertion vraie sans rien prouver.
     */
    public function testAucunAliasNeMontreLeCabinetVoisin(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $alias = $this->aliasDuRegistre();

        // TOUT CE QUI TOUCHE AU CONTENEUR EST FAIT AVANT LA PREMIERE REQUETE.
        //
        // Interleaver un acces au conteneur entre deux requetes garde le noyau demarre,
        // et le client ne le redemarre alors plus : les services PARTAGES conservent leur
        // etat d'une requete a l'autre. `WrappedEntityTypeAutocompleter` l'interdit
        // explicitement -- son `setOptions()` jette « The options can only be set before
        // the form is created » (l. 183-190) des la deuxieme requete. En production le
        // probleme n'existe pas, chaque requete partant d'un processus neuf ; ici il
        // produisait une 500 que l'ancienne version de ce test lisait comme « champ muet ».
        $classes = [];
        $duVoisinParAlias = [];
        $siensParAlias = [];
        foreach ($alias as $a) {
            $classe = $this->classeDeLAlias($a);
            $classes[$a] = $classe;
            $duVoisinParAlias[$a] = $this->identifiantsDuCabinet($classe, $seed['a']['entreprise']);
            $siensParAlias[$a] = $this->identifiantsDuCabinet($classe, $seed['b']['entreprise']);
        }
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $fuites = [];
        $muets = [];
        foreach ($alias as $a) {
            $proposes = $this->identifiantsProposes($a);

            $voisin = array_values(array_intersect($proposes, $duVoisinParAlias[$a]));
            if ([] !== $voisin) {
                $fuites[$a] = $classes[$a] . ' #' . implode(', #', $voisin);
            }
            if ([] === array_intersect($proposes, $siensParAlias[$a])) {
                $muets[] = $a;
            }
        }

        self::assertSame([], $muets, sprintf(
            'Ces alias ne proposent AUCUNE des entites du cabinet de l\'utilisateur connecte : %s. '
            . 'Tant que c\'est le cas, l\'assertion de cloisonnement ne prouve rien pour eux : leur '
            . 'liste serait vide pour une raison triviale, pas par filtrage.',
            implode(', ', $muets),
        ));

        self::assertSame([], $fuites, sprintf(
            "FUITE INTER-CABINETS. Un invite du cabinet B se voit proposer des entites du cabinet A :
  %s
"
            . 'Le `query_builder` de ces champs doit passer par '
            . 'FormListenerFactory::setFiltreEntreprise(), qui filtre sur getConnectedTo() -- '
            . 'l\'entreprise ACTIVE de l\'utilisateur, et non une autre.',
            implode("
  ", array_map(
                static fn (string $a, string $d): string => $a . ' -> ' . $d,
                array_keys($fuites),
                $fuites,
            )),
        ));
    }

    /**
     * LE CAS DISCRIMINANT : INVITE DANS LES DEUX CABINETS, CONNECTE A UN SEUL.
     *
     * Le test voisin oppose deux comptes etrangers l un a l autre : un filtre qui se
     * tromperait de QUESTION -- « a quels cabinets cet utilisateur a-t-il acces ? » au
     * lieu de « quel cabinet est ouvert ? » -- y passerait au vert, puisque l attaquant
     * n a aucun acces au cabinet voisin.
     *
     * Ici le compte de B est AUSSI invite du cabinet A, et son connectedTo vaut B. Les
     * deux lectures divergent donc : l union rendrait A et B, le cabinet actif ne rend
     * que B. C est le seul montage qui prouve que setFiltreEntreprise() lit bien
     * getConnectedTo() -- un SEUL cabinet, celui qui est ouvert -- et non l ensemble des
     * cabinets atteignables.
     *
     * Enjeu concret : sans cela, quitter un cabinet ne fermerait rien.
     */
    public function testUnInviteDesDeuxCabinetsNeVoitQueLeCabinetActif(): void
    {
        $seed = $this->semerLesDeuxCabinets();

        // Le proprietaire de B devient AUSSI invite de A. Son cabinet actif reste B.
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');

        $alias = $this->aliasDuRegistre();

        // Tout acces au conteneur AVANT la premiere requete (voir le test voisin).
        $classes = [];
        $idsDeA = [];
        $idsDeB = [];
        foreach ($alias as $a) {
            $classe = $this->classeDeLAlias($a);
            $classes[$a] = $classe;
            $idsDeA[$a] = $this->identifiantsDuCabinet($classe, $seed['a']['entreprise']);
            $idsDeB[$a] = $this->identifiantsDuCabinet($classe, $seed['b']['entreprise']);
        }

        $utilisateur = $this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']);

        // PRECONDITIONS, sans quoi ce test ne discriminerait rien.
        self::assertSame(
            $seed['b']['entreprise'],
            $utilisateur->getConnectedTo()?->getId(),
            "Le cabinet actif du compte doit etre B : s il avait bascule, ce test opposerait de nouveau deux etrangers.",
        );
        self::assertCount(
            2,
            $this->em()->getRepository(Invite::class)->findBy(['utilisateur' => $utilisateur]),
            "Le compte doit etre invite dans les DEUX cabinets, sinon un filtre sur l union rendrait le meme resultat qu un filtre sur le cabinet actif, et le test passerait sans rien distinguer.",
        );

        $this->client->loginUser($utilisateur);

        $fuites = [];
        $muets = [];
        foreach ($alias as $a) {
            $proposes = $this->identifiantsProposes($a);

            $voisin = array_values(array_intersect($proposes, $idsDeA[$a]));
            if ([] !== $voisin) {
                $fuites[$a] = $classes[$a] . ' #' . implode(', #', $voisin);
            }
            if ([] === array_intersect($proposes, $idsDeB[$a])) {
                $muets[] = $a;
            }
        }

        self::assertSame([], $muets, sprintf(
            "Ces alias ne proposent rien du cabinet ACTIF : %s. L assertion de cloisonnement ne "
            . "prouverait alors rien pour eux.",
            implode(', ', $muets),
        ));

        self::assertSame([], $fuites, sprintf(
            "FILTRE SUR L UNION, PAS SUR LE CABINET ACTIF. Le compte est invite dans A et dans B, "
            . "mais c est B qui est ouvert ; or on lui propose des entites de A :
  %s
"
            . "setFiltreEntreprise() doit filtrer sur getConnectedTo() -- le cabinet OUVERT, un seul "
            . "-- et jamais sur l ensemble des cabinets ou le compte possede une Invite. Sinon "
            . "quitter un cabinet ne fermerait rien.",
            implode("
  ", array_map(
                static fn (string $a, string $d): string => $a . ' -> ' . $d,
                array_keys($fuites),
                $fuites,
            )),
        ));
    }

    /**
     * TOUT ALIAS DOIT AVOIR SA RECETTE DE SEMIS.
     *
     * C'est le verrou de non-vacuite du fichier. Les assertions d'absence disent « cet
     * alias ne rend rien » ; elles ne valent que si une entite de SA classe existe en
     * base. Un champ ajoute demain sur une classe que le semis ne cree pas rendrait
     * « liste vide » pour une raison triviale, et le test d'absence deviendrait un test
     * de rien.
     *
     * La classe vient de `EntityAutocompleterInterface::getEntityClass()` : aucune carte
     * alias -> classe n'est ecrite ici, sans quoi c'est elle qu'on oublierait de tenir.
     */
    public function testChaqueAliasAUneRecetteDeSemis(): void
    {
        $couvertes = $this->semerLesDeuxCabinets()['classes'];

        $sansRecette = [];
        foreach ($this->aliasDuRegistre() as $alias) {
            $classe = $this->classeDeLAlias($alias);
            if (!\in_array($classe, $couvertes, true)) {
                $sansRecette[$alias] = $classe;
            }
        }

        self::assertSame([], $sansRecette, sprintf(
            "Ces alias visent une classe qu'aucune recette de semis ne cree :\n  %s\n"
            . "Ajoutez-la a semerUnCabinet(), puis declarez-la dans la liste 'classes' que "
            . "semerLesDeuxCabinets() retourne. Sans cela, les assertions d'absence de ce fichier "
            . "passeraient au vert pour ces alias sans rien prouver : leur liste serait vide faute "
            . "de donnees, pas faute de droits. C'est exactement ce qui a laisse vivre la fuite "
            . "d'Article.",
            implode("\n  ", array_map(
                static fn (string $a, string $c): string => $a . ' -> ' . $c,
                array_keys($sansRecette),
                $sansRecette,
            )),
        ));
    }

    /**
     * UN TERME DE RECHERCHE NE DOIT FAIRE PLANTER AUCUN ALIAS.
     *
     * Sans terme, `searchable_fields` n'est jamais exerce : c'est ce qui laissait passer
     * le `['nom']` d'Article, un champ inexistant sur l'entite. On n'exige RIEN du contenu
     * ici -- les assertions d'absence et de presence restent sur la requete sans terme.
     * On exige seulement que le serveur tienne debout, authentifie comme anonyme.
     */
    public function testAucunAliasNePlanteSurUnTermeDeRecherche(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $alias = $this->aliasDuRegistre();

        foreach ($alias as $a) {
            $this->client->request('GET', '/autocomplete/' . $a . '?query=Cabinet');
            self::assertLessThan(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->client->getResponse()->getStatusCode(),
                sprintf('Anonyme, « %s » plante des qu\'on lui passe un terme de recherche.', $a),
            );
        }

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        foreach ($alias as $a) {
            $this->client->request('GET', '/autocomplete/' . $a . '?query=Cabinet');
            self::assertLessThan(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->client->getResponse()->getStatusCode(),
                sprintf(
                    'Connecte, « %s » plante sur un terme de recherche. Son `searchable_fields` nomme '
                    . 'probablement une propriete que l\'entite n\'a pas.',
                    $a,
                ),
            );
        }
    }

    /**
     * Les deux alias supprimes ne doivent pas revenir par une regeneration distraite.
     */
    public function testLesDeuxAliasNonScopesNExistentPlus(): void
    {
        $alias = $this->aliasDuRegistre();

        self::assertNotContains(
            'entreprise_autocomplete_field',
            $alias,
            'EntrepriseAutocompleteField est de retour. Il n\'avait ni query_builder ni security, et '
            . 'son endpoint rendait la liste de TOUS les cabinets de la plateforme a un appelant '
            . 'anonyme (mesure avant suppression : HTTP 200, deux resultats, noms en clair).',
        );
        self::assertNotContains(
            'article_autocomplete_field',
            $alias,
            'ArticleAutocompleteField est de retour. Son query_builder etait commente sur un motif '
            . 'faux, et son libelle servait la reference de police, le taux de commission et le '
            . 'montant facture. Article n\'a aucune colonne texte : aucun searchable_fields valide '
            . 'n\'est possible. S\'il doit revivre, il lui faut un query_builder reel, pas un '
            . 'commentaire.',
        );
    }
}
