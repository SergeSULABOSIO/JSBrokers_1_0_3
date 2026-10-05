<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use App\Service\Workspace\CabinetActif;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * UN CABINET N'EST OUVERT QUE SI L'ON Y A ENCORE UNE INVITE.
 *
 * ── CE QUI S'EST PASSE ──────────────────────────────────────────────────────
 * Tout le cloisonnement repose sur `Utilisateur::$connectedTo`, une colonne lue
 * partout et verifiee nulle part. Deux defauts en decoulaient, mesures avant ce lot :
 *
 *  1. REVOQUER NE FERMAIT RIEN. `Invite` n'a aucun etat d'acceptation ni de
 *     revocation -- revoquer, c'est SUPPRIMER la ligne -- et aucun code ne remettait
 *     `connectedTo` a zero : ni listener, ni subscriber. Le cabinet restait ouvert
 *     pour un compte qui n'y avait plus aucun droit.
 *
 *  2. UN REPLI RATTRAPAIT L'ABSENCE. `ControllerUtilsTrait::getInvite()` retombait
 *     sur `findOneBy(['utilisateur' => $user])` et rendait un invite ARBITRAIRE d'un
 *     autre cabinet. Le perimetre de roles venait alors d'un cabinet pendant que les
 *     requetes filtrees servaient l'autre.
 *
 * Le repli avait ete pose pour « ne pas casser l'acces ». Il repondait a « quel
 * cabinet puis-je ouvrir ? » la ou la question est « quel cabinet est ouvert ? » --
 * deux questions qui n'ont la meme reponse que par accident.
 *
 * ── CE QUE CE TEST TIENT ────────────────────────────────────────────────────
 * Que le gardien {@see CabinetActif} ferme au lieu de choisir, et qu'il ne choisisse
 * jamais a la place de l'utilisateur.
 */
class CabinetActifTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;

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

    private function gardien(): CabinetActif
    {
        return static::getContainer()->get(CabinetActif::class);
    }

    /**
     * Authentifie DANS LE CONTENEUR, et non pour une requete.
     *
     * Le gardien lit `Security::getUser()`, qui interroge le token_storage du conteneur.
     * `KernelBrowser::loginUser()` arme le pare-feu de la requete SUIVANTE : il ne suffit
     * donc pas pour interroger un service directement.
     */
    private function connecterDansLeConteneur(int $ownerId): Utilisateur
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->find($ownerId);
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($utilisateur, 'main', $utilisateur->getRoles()),
        );
        $this->gardien()->reset();

        return $utilisateur;
    }

    /**
     * LE CAS NOMINAL, d'abord : sans lui, tout ce qui suit pourrait etre vrai pour la
     * mauvaise raison -- un gardien qui ferme TOUJOURS passerait les autres tests.
     */
    public function testLeCabinetOuvertEstCeluiOuLUtilisateurAUneInvite(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $utilisateur = $this->connecterDansLeConteneur($seed['b']['owner']);

        self::assertSame($seed['b']['entreprise'], $this->gardien()->entreprise()?->getId(),
            'Le gardien doit rendre le cabinet ou le compte possede une Invite.');
        self::assertTrue($this->gardien()->estOuvert());
        self::assertSame($seed['b']['entreprise'], $this->gardien()->identifiant());
        self::assertSame($utilisateur, $this->gardien()->invite()?->getUtilisateur());
    }

    /**
     * REVOCATION : l'Invite disparait, le cabinet se ferme -- et AUCUN autre ne s'ouvre.
     *
     * Les deux assertions comptent autant l'une que l'autre. « Le cabinet s'est ferme »
     * sans « aucun autre ne s'est ouvert » decrirait exactement l'ancien repli, qui
     * fermait bien celui-la... pour en ouvrir un autre a sa place.
     */
    public function testUneInviteRevoqueeFermeLeCabinetSansEnOuvrirUnAutre(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        // Le compte de B est aussi invite de A, et bascule sur A.
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        // Puis son acces a A est REVOQUE : la ligne d'Invite disparait.
        $this->revoquerLInvite($seed['b']['owner'], $seed['a']['entreprise']);
        $this->connecterDansLeConteneur($seed['b']['owner']);

        self::assertFalse($this->gardien()->estOuvert(),
            'L\'Invite dans le cabinet A a ete supprimee : aucun cabinet ne doit plus etre ouvert.');
        self::assertNull($this->gardien()->entreprise(),
            'Le compte possede encore une Invite dans B, mais B n\'est pas le cabinet qu\'il avait '
            . 'ouvert : le gardien ne doit PAS choisir a sa place. C\'est exactement ce que faisait '
            . 'le repli findOneBy([\'utilisateur\' => $user]) de getInvite().');
        self::assertSame(CabinetActif::AUCUN_CABINET, $this->gardien()->identifiant(),
            'Sans cabinet ouvert, l\'identifiant servi aux filtres doit etre AUCUN_CABINET, qu\'aucune '
            . 'ligne ne porte : un filtre dessus rend une liste VIDE.');
    }

    /**
     * La fermeture est PERSISTEE : la requete suivante ne repose pas la meme question.
     */
    public function testLaFermetureEstEcriteEnBase(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        $this->revoquerLInvite($seed['b']['owner'], $seed['a']['entreprise']);
        $this->connecterDansLeConteneur($seed['b']['owner']);

        $this->gardien()->invite();
        $this->em()->clear();

        self::assertNull(
            $this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner'])?->getConnectedTo(),
            'connectedTo doit etre remis a null en base, sans quoi chaque requete reposerait la meme '
            . 'question pour la meme raison.',
        );
    }

    /**
     * L'ECRAN NE RESTE PAS BLOQUE : on renvoie au choix d'espace, pas une erreur.
     *
     * L'utilisateur n'a rien tente d'interdit -- son droit a disparu pendant qu'il
     * travaillait. D'ou une redirection, et non un 403 qui lui laisserait croire a une
     * faute de sa part.
     */
    public function testLEcranRenvoieAuChoixDEspace(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        $this->revoquerLInvite($seed['b']['owner'], $seed['a']['entreprise']);

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));
        $this->client->request('GET', '/admin/assureur/api/get-form/0');
        $reponse = $this->client->getResponse();

        self::assertSame(Response::HTTP_FOUND, $reponse->getStatusCode(),
            'Un ecran dont le cabinet vient de se fermer doit renvoyer au choix d\'espace, '
            . 'pas afficher une erreur.');
        self::assertStringContainsString('/admin/entreprise', (string) $reponse->headers->get('Location'));
    }

    /**
     * EN XHR, ON NE REDIRIGE PAS : on le DIT.
     *
     * L'espace de travail parle en JSON autant qu'en HTML. Rediriger une requete XHR la
     * ferait suivre la redirection et injecter une PAGE ENTIERE dans un panneau -- un
     * symptome illisible pour une cause simple.
     */
    public function testEnXhrLaFermetureSeDitEnJson(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        $this->revoquerLInvite($seed['b']['owner'], $seed['a']['entreprise']);

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));
        $this->client->xmlHttpRequest('GET', '/admin/assureur/api/get-form/0');
        $reponse = $this->client->getResponse();

        self::assertSame(Response::HTTP_CONFLICT, $reponse->getStatusCode(),
            'Un 409 : rien n\'est interdit, c\'est l\'etat du compte qui a change pendant la requete.');
        $charge = json_decode((string) $reponse->getContent(), true);
        self::assertStringContainsString('/admin/entreprise', $charge['redirect'] ?? '',
            'La reponse doit porter l\'adresse ou aller, a charge pour le client de naviguer.');
    }

    /**
     * PAS DE BOUCLE : la page de destination doit vivre SANS cabinet ouvert.
     *
     * Rediriger vers un ecran qui redirige a son tour enfermerait l'utilisateur dans une
     * boucle -- et c'est le risque propre a toute redirection posee par un gardien : la
     * destination est elle aussi gardee. On verifie donc qu'on y arrive, et qu'on y
     * reste.
     */
    public function testLeChoixDEspaceEstAccessibleSansCabinetOuvert(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        $this->revoquerLInvite($seed['b']['owner'], $seed['a']['entreprise']);

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        // On part d'un ecran dont le cabinet vient de se fermer, et on SUIT la redirection.
        $this->client->request('GET', '/admin/assureur/api/get-form/0');
        $this->client->followRedirect();
        $reponse = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode(), sprintf(
            'Le choix d\'espace repond HTTP %d au lieu de 200 : une redirection vers un ecran '
            . 'lui-meme redirige enfermerait l\'utilisateur dans une boucle.',
            $reponse->getStatusCode(),
        ));
    }

    /**
     * LE REPLI NE DOIT PAS REVENIR.
     *
     * Un test de source, ici, et c'est deliberе : le comportement est deja tenu par les
     * tests ci-dessus, mais la ligne supprimee etait accompagnee d'un commentaire qui la
     * justifiait (« filet de securite... pour ne pas casser l'acces »). Sans cette garde,
     * la meme bonne intention la ferait revenir.
     */
    public function testLeRepliVersUnInviteArbitraireNEstPasRevenu(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/Admin/ControllerUtilsTrait.php');

        self::assertStringNotContainsString(
            "findOneBy(['utilisateur' => \$user])",
            (string) $source,
            'Le repli de getInvite() est de retour. Il rendait un invite ARBITRAIRE d\'un autre '
            . 'cabinet quand aucun ne correspondait au cabinet ouvert, ce qui rouvrait l\'acces '
            . 'precisement quand il venait d\'etre retire. Le gardien CabinetActif ferme ; il ne '
            . 'choisit pas a la place de l\'utilisateur.',
        );
    }
}
