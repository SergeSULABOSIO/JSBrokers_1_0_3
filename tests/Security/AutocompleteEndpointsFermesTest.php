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
    private const OWNER_A_EMAIL = 'phpunit-endpoints-a@test.local';
    private const OWNER_B_EMAIL = 'phpunit-endpoints-b@test.local';
    private const ENTREPRISE_A_NOM = 'PHPUnit Endpoints Cabinet A SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit Endpoints Cabinet B SARL';

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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_A_NOM, self::ENTREPRISE_B_NOM];
        $emails = [self::OWNER_A_EMAIL, self::OWNER_B_EMAIL];

        // Enfants d'abord : chaque table ne part qu'une fois ses references levees.
        $tables = [
            'article', 'revenu_pour_courtier', 'tranche', 'avenant', 'bordereau',
            'cotation', 'piste', 'condition_partage', 'autorite_fiscale', 'taxe',
            'compte_bancaire', 'chargement', 'classeur', 'client', 'groupe',
            'portefeuille', 'partenaire', 'assureur', 'risque', 'type_revenu', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }

        // Denoue la FK circulaire utilisateur.connected_to_id avant de supprimer l'entreprise.
        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
    }

    /**
     * Les deux cabinets, complets et symetriques.
     *
     * @return array{a: array{owner: int, entreprise: int}, b: array{owner: int, entreprise: int}, classes: list<class-string>}
     */
    private function semerLesDeuxCabinets(): array
    {
        $a = $this->semerUnCabinet('A', self::ENTREPRISE_A_NOM, self::OWNER_A_EMAIL);
        $b = $this->semerUnCabinet('B', self::ENTREPRISE_B_NOM, self::OWNER_B_EMAIL);

        $em = $this->em();
        $em->flush();

        $ids = [
            'a' => ['owner' => $a['owner']->getId(), 'entreprise' => $a['entreprise']->getId()],
            'b' => ['owner' => $b['owner']->getId(), 'entreprise' => $b['entreprise']->getId()],
            // CE QUE CETTE RECETTE COUVRE, declare ici et nulle part ailleurs. Le test de
            // couverture compare cette liste aux classes que le registre expose : un alias
            // dont la classe manque ici rendrait « liste vide » indecidable.
            'classes' => [
                Article::class, Assureur::class, AutoriteFiscale::class, Avenant::class,
                Bordereau::class, Chargement::class, Classeur::class, Client::class,
                CompteBancaire::class, ConditionPartage::class, Groupe::class, Invite::class,
                Partenaire::class, Portefeuille::class, RevenuPourCourtier::class,
                Risque::class, Taxe::class, Tranche::class, TypeRevenu::class,
            ],
        ];

        $em->clear();

        return $ids;
    }

    /**
     * UNE entite par classe autocompletee, dans un cabinet complet.
     *
     * @return array{owner: Utilisateur, entreprise: Entreprise}
     */
    private function semerUnCabinet(string $suffixe, string $nomEntreprise, string $email): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail($email)
            ->setNom('PHPUnit Endpoints ' . $suffixe)
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom($nomEntreprise)
            ->setLicence('LIC-' . $suffixe)
            ->setAdresse('1 rue des Endpoints')
            ->setTelephone('+243000000' . \ord($suffixe))
            ->setRccm('RCCM-' . $suffixe)
            ->setIdnat('IDNAT-' . $suffixe)
            ->setNumimpot('IMP-' . $suffixe)
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        // L'entreprise ACTIVE : c'est elle que setFiltreEntreprise() lit via getConnectedTo().
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprietaire ' . $suffixe);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $portefeuille = (new Portefeuille())->setNom('Portefeuille ' . $suffixe)->setGestionnaire($invite);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $groupe = (new Groupe())->setNom('Groupe ' . $suffixe)->setDescription('Groupe de test');
        $groupe->setEntreprise($entreprise);
        $em->persist($groupe);

        $client = (new Client())->setNom('Client ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise)->setPortefeuille($portefeuille);
        $em->persist($client);

        $assureur = (new Assureur())->setNom('Assureur ' . $suffixe);
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $risque = (new Risque())
            ->setCode('R' . $suffixe)
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('Risque ' . $suffixe)
            ->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        // Dates CALCULEES : un test soumis a une date figee se met a mentir tout seul.
        $maintenant = new \DateTimeImmutable('now');

        $piste = (new Piste())
            ->setNom('Piste ' . $suffixe)
            ->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('Risque de test endpoints')
            ->setExercice((int) $maintenant->format('Y'))
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation ' . $suffixe)->setDuree(12);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setStartingAt($maintenant->modify('-30 days'))
            ->setEndingAt($maintenant->modify('+335 days'))
            ->setDescription('Avenant de test endpoints')
            ->setReferencePolice('POL-' . $suffixe . '-001')
            ->setNonRenouvelable(false)
            ->setCotation($cotation);
        $avenant->setEntreprise($entreprise);
        $em->persist($avenant);

        // `shared`, `multipayments` et `redevable` sont NOT NULL sans defaut.
        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission ' . $suffixe)
            ->setShared(false)
            ->setMultipayments(false)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu ' . $suffixe);
        $revenu->setCotation($cotation)->setTypeRevenu($typeRevenu);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $tranche = (new Tranche())
            ->setNom('Tranche ' . $suffixe)
            ->setPourcentage(100.0) // 100 % en POINTS (convention du projet).
            ->setPayableAt($maintenant->modify('-30 days'))
            ->setEcheanceAt($maintenant->modify('-5 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        $article = (new Article())->setQuantite(1.0)->setTranche($tranche);
        $article->setEntreprise($entreprise);
        $em->persist($article);

        $taxe = (new Taxe())
            ->setCode('T' . $suffixe)
            ->setDescription('Taxe de test endpoints')
            ->setTauxIARD('10')
            ->setTauxVIE('5')
            ->setRedevable(Taxe::REDEVABLE_ASSUREUR);
        $taxe->setEntreprise($entreprise);
        $em->persist($taxe);

        $autorite = (new AutoriteFiscale())
            ->setNom('Autorite ' . $suffixe)
            ->setAbreviation('A' . $suffixe)
            ->setTaxe($taxe);
        $autorite->setEntreprise($entreprise);
        $em->persist($autorite);

        $compte = (new CompteBancaire())
            ->setIntitule('Compte ' . $suffixe)
            ->setNumero('0000-' . $suffixe)
            ->setBanque('Banque ' . $suffixe)
            ->setCodeSwift('FERMCDK' . $suffixe);
        $compte->setEntreprise($entreprise);
        $em->persist($compte);

        $chargement = (new Chargement())->setNom('Chargement ' . $suffixe);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        $classeur = (new Classeur())->setNom('Classeur ' . $suffixe)->setDescription('Classeur de test');
        $classeur->setEntreprise($entreprise);
        $em->persist($classeur);

        $partenaire = (new Partenaire())->setNom('Partenaire ' . $suffixe)->setPart(50.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $condition = (new ConditionPartage())
            ->setNom('Condition ' . $suffixe)
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)
            ->setSeuil(0.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);

        $bordereau = (new Bordereau())
            ->setNom('Bordereau ' . $suffixe)
            ->setType(Bordereau::TYPE_BOREDERAU_PRODUCTION)
            ->setReference('BDX-' . $suffixe . '-001')
            ->setReceivedAt($maintenant->modify('-10 days'))
            ->setPeriodeDebut($maintenant->modify('-40 days'))
            ->setPeriodeFin($maintenant->modify('-10 days'))
            ->setAssureur($assureur)
            ->setInvite($invite)
            ->setEntreprise($entreprise);
        $em->persist($bordereau);

        return ['owner' => $owner, 'entreprise' => $entreprise];
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
     * Les identifiants qu'une entreprise possede, pour une classe donnee.
     *
     * @return list<int>
     */
    private function identifiantsDuCabinet(string $classe, int $entrepriseId): array
    {
        return array_map('intval', $this->em()
            ->createQuery(sprintf('SELECT e.id FROM %s e WHERE e.entreprise = :ese', $classe))
            ->setParameter('ese', $entrepriseId)
            ->getSingleColumnResult());
    }

    /**
     * SANS SESSION, AUCUN ALIAS NE DOIT RENDRE LA MOINDRE LIGNE.
     *
     * Trois formes de refus sont admises, parce que le durcissement a venir
     * (`access_control` sur ^/autocomplete) en produira une autre que celle d'aujourd'hui :
     * liste vide, 401/403, ou redirection vers la connexion. Ce test ne doit pas se mettre
     * en travers de son propre durcissement.
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
