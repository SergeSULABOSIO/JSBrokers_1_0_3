<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * ON N'OUVRE PAS LE CABINET D'UN AUTRE.
 *
 * ── CE QUI S'EST PASSE ──────────────────────────────────────────────────────
 * Deux chemins ecrivaient `Utilisateur::$connectedTo`, inegalement gardes, et tous deux
 * persistaient aussitot. C'est la cle de voute : `setFiltreEntreprise()` et tout ce qui
 * lit le gardien suivent cette colonne. Qui l'ecrit decide de ce que l'utilisateur voit.
 *
 *  1. `EntrepriseDashbordController::index()` -- AUCUNE validation. L'identifiant venait
 *     de l'URL et partait en base. Un GET suffisait.
 *
 *  2. `EspaceDeTravailComponentController` -- precede de `validateWorkspaceAccess()`, qui
 *     verifiait la coherence invite <-> entreprise mais JAMAIS l'appartenance de l'invite
 *     a l'utilisateur authentifie. Or un invite du cabinet de la victime est parfaitement
 *     coherent avec le cabinet de la victime : la coherence interne d'un couple ne dit
 *     rien de celui qui le presente. Fournir le couple (idInvite, idEntreprise) de la
 *     victime -- deux entiers sequentiels -- passait le controle.
 *
 * Mesure avant correctif : l'invite du cabinet B prenait le cabinet A pour cabinet actif
 * par les DEUX chemins. Et le premier le faisait EN PLANTANT (HTTP 500) : le flush
 * survenait tot dans l'action, le plantage plus tard ne l'annulait pas.
 *
 * ── D'OU LA FORME DE CES TESTS ──────────────────────────────────────────────
 * On ne juge jamais sur la seule reponse. Une 500 peut accompagner une bascule reussie ;
 * un 403 peut arriver apres l'ecriture. On relit donc `connectedTo` EN BASE a chaque
 * fois : c'est l'etat qui fait foi, pas le code HTTP.
 */
class BasculeDeCabinetTest extends WebTestCase
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

    /** Le cabinet actif, relu en base — l'etat fait foi, pas la reponse. */
    private function cabinetActifEnBase(int $ownerId): ?int
    {
        $this->em()->clear();

        return $this->em()->getRepository(Utilisateur::class)->find($ownerId)?->getConnectedTo()?->getId();
    }

    /**
     * LE CAS NOMINAL, d'abord : sans lui, tout ce qui suit serait vrai pour la mauvaise
     * raison -- une bascule qui refuse TOUJOURS passerait les deux tests suivants.
     */
    public function testOnOuvreSonPropreCabinet(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $idInviteB = $this->premiereInviteDe($seed['b']['owner'], $seed['b']['entreprise']);
        $this->remettreLeCabinetActif($seed['b']['owner'], $seed['a']['entreprise']);
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $idInviteB, $seed['b']['entreprise']));

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());
        self::assertSame($seed['b']['entreprise'], $this->cabinetActifEnBase($seed['b']['owner']),
            'Presenter SA propre invitation doit ouvrir SON cabinet, sans quoi le produit ne marche plus.');
    }

    /**
     * L'ESPACE DE TRAVAIL : presenter l'invitation d'un autre ne doit rien ouvrir.
     */
    public function testLEspaceDeTravailRefuseLInvitationDAutrui(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $idInviteA = $this->premiereInviteDe($seed['a']['owner'], $seed['a']['entreprise']);
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $idInviteA, $seed['a']['entreprise']));
        $code = $this->client->getResponse()->getStatusCode();

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
            "L'espace de travail plante (HTTP %d) au lieu de refuser. Un plantage n'est pas un refus : "
            . "le flush peut avoir eu lieu avant lui.",
            $code,
        ));
        self::assertSame($seed['b']['entreprise'], $this->cabinetActifEnBase($seed['b']['owner']), sprintf(
            "BASCULE VERS LE CABINET D'AUTRUI. Le couple (invite #%d, cabinet #%d) appartient au "
            . "cabinet A, et un invite du cabinet B l'a presente avec succes. validateWorkspaceAccess() "
            . "ne verifiait que la coherence invite <-> entreprise, jamais l'appartenance de l'invite "
            . "a l'UTILISATEUR CONNECTE.",
            $idInviteA,
            $seed['a']['entreprise'],
        ));
    }

    /**
     * LE TABLEAU DE BORD : un GET ne doit rien ouvrir non plus.
     *
     * C'etait le chemin le plus direct -- aucune validation du tout, l'identifiant de
     * l'URL ecrit tel quel.
     */
    public function testLeTableauDeBordRefuseUnCabinetEtranger(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/%d', $seed['a']['entreprise']));
        $code = $this->client->getResponse()->getStatusCode();

        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $code, sprintf(
            "Le tableau de bord plante (HTTP %d). Mesure avant correctif : il repondait 500 ET "
            . "basculait quand meme, le flush survenant tot dans l'action.",
            $code,
        ));
        self::assertSame($seed['b']['entreprise'], $this->cabinetActifEnBase($seed['b']['owner']),
            "Un GET sur le tableau de bord d'un cabinet etranger ne doit rien ecrire.");
    }

    /**
     * UNE PAGE DE CONSULTATION NE MODIFIE JAMAIS L'ETAT — MEME POUR UN CABINET LEGITIME.
     *
     * Le cas est volontairement le plus favorable qui soit : le compte EST invite dans le
     * cabinet A. Rien ne s'opposerait a ce qu'il l'ouvre -- il lui suffirait de passer par
     * l'espace de travail. Mais un GET de consultation n'est pas le geste qui ouvre, et
     * basculer ici le ferait dans le dos de l'utilisateur : il croirait consulter A en
     * restant dans B, alors que tout son filtrage aurait change.
     *
     * On ne protege donc pas cette ecriture, on l'a RETIREE.
     */
    public function testLeTableauDeBordNeBasculeMemePasVersUnCabinetLegitime(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        // Le compte de B est AUSSI invite du cabinet A : l'ouverture lui serait permise.
        $this->rattacherAuCabinet($seed['b']['owner'], $seed['a']['entreprise'], 'Invite croisee A');
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/%d', $seed['a']['entreprise']));
        $reponse = $this->client->getResponse();

        self::assertSame($seed['b']['entreprise'], $this->cabinetActifEnBase($seed['b']['owner']),
            'Un GET de consultation ne doit rien ecrire, meme quand la bascule serait permise. '
            . "Sinon l'utilisateur change de perimetre sans l'avoir demande.");
        self::assertSame(Response::HTTP_FOUND, $reponse->getStatusCode(),
            'Faute de pouvoir afficher ce cabinet, on renvoie au choix d\'espace.');
        self::assertStringContainsString('/admin/entreprise', (string) $reponse->headers->get('Location'));
    }

    /**
     * LE CONTROLE PRECEDE L'ECRITURE — y compris quand l'invitation n'existe pas.
     *
     * Un refus ne doit rien laisser derriere lui : ni cabinet ouvert, ni cabinet ferme.
     */
    public function testUnRefusNeTouchePasAuCabinetDejaOuvert(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', 99999999, $seed['a']['entreprise']));

        self::assertSame($seed['b']['entreprise'], $this->cabinetActifEnBase($seed['b']['owner']),
            'Le cabinet deja ouvert doit survivre a une tentative refusee : un controle place APRES '
            . "l'ecriture laisserait l'utilisateur ailleurs que la ou il etait.");
    }

    /**
     * Le cabinet d'autrui ne s'ouvre pas davantage avec SA PROPRE invitation.
     *
     * Les deux appartenances sont verifiees, pas une seule : l'invitation est bien la
     * notre, mais elle ne vaut pas pour ce cabinet-la.
     */
    public function testSonInvitationNOuvrePasUnAutreCabinet(): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $idInviteB = $this->premiereInviteDe($seed['b']['owner'], $seed['b']['entreprise']);
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($seed['b']['owner']));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $idInviteB, $seed['a']['entreprise']));

        self::assertSame($seed['b']['entreprise'], $this->cabinetActifEnBase($seed['b']['owner']),
            'Une invitation valable pour B ne doit pas ouvrir A. Verifier une seule des deux '
            . 'appartenances laisserait passer exactement ce couple.');
    }
}
