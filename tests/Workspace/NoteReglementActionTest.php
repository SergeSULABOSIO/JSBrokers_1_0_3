<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SIGNALER LE RÈGLEMENT D'UNE NOTE, DEPUIS LA RUBRIQUE NOTES.
 *
 * ── CE QUE CE BANC PROTÈGE ──────────────────────────────────────────────────────────
 * Émettre une note n'est que la moitié du geste : elle part pour être PAYÉE. L'encaissement
 * ne se saisissait qu'en ouvrant la note et en descendant dans l'accordéon « Paiements
 * liés » — deux détours, et seulement si l'on y pensait.
 *
 * Trois choses doivent tenir, et chacune a déjà failli manquer :
 *
 *   1. L'ACTION EST DÉCLARÉE DANS UN FOURNISSEUR DE CANEVAS. L'événement existait d'abord
 *      côté JavaScript seulement, et le manifeste de parité le décrivait : deux tests de
 *      l'assistant sont tombés, faute de libellé lisible pour un geste pourtant offert.
 *      Une action qui n'est déclarée nulle part n'a pas de nom.
 *   2. ELLE DISPARAÎT SUR UNE NOTE SOLDÉE. Proposer de régler ce qui est payé invite au
 *      double encaissement, et ouvre un formulaire sans montant à proposer.
 *   3. LA ROUTE NOMME LA COLLECTION. C'est par ce nom que le paiement enregistré va se
 *      ranger dans le widget « Paiements liés » au lieu d'attendre un rechargement.
 */
class NoteReglementActionTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-note-reglement@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Note Reglement SARL';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
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

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL],
        );
        // ENFANTS D'ABORD, et `bordereau` APRÈS `note` : la note le référence, et le
        // bordereau référence lui-même l'invité. L'omettre fait échouer la suppression de
        // l'invité, et l'exception laisse le noyau démarré — tous les tests suivants
        // tombent alors sur « the kernel should only be booted once », qui ne dit rien
        // de la vraie cause.
        foreach (['paiement', 'note', 'bordereau', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN entreprise e ON t.entreprise_id = e.id
                 WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email = :email OR e.nom = :nom',
            ['email' => self::OWNER_EMAIL, 'nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /** @return array{0: Entreprise, 1: Invite, 2: Client} */
    private function cabinet(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-NR')->setAdresse('1 rue du Test')->setTelephone('+243000000000')
            ->setRccm('RCCM-NR')->setIdnat('IDNAT-NR')->setNumimpot('IMP-NR');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $client = (new Client())->setNom('ZZ-CLIENT-NOTE-REGLEMENT');
        $client->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($client);

        $em->flush();

        return [$entreprise, $invite, $client];
    }

    /**
     * Une note dont le montant vient d'un BORDEREAU : c'est le cas le plus courant, et
     * c'est aussi le second chemin de calcul du solde — celui qu'on oublie.
     */
    private function note(Entreprise $e, Invite $invite, Client $client, float $ht, float $paye): Note
    {
        $em = $this->em();

        $bordereau = (new \App\Entity\Bordereau())
            ->setType(0)->setNom('Bordereau NR')->setReference('BRD-NR-' . uniqid())
            ->setReceivedAt(new \DateTimeImmutable('now'))
            ->setPeriodeDebut(new \DateTimeImmutable('now'))
            ->setPeriodeFin(new \DateTimeImmutable('now'))
            ->setMontantComHtPayableNow($ht)
            ->setMontantTaxePayableNow(0.0);
        $bordereau->setInvite($invite)->setEntreprise($e);
        $em->persist($bordereau);

        $note = (new Note())
            ->setNom('Note NR')->setReference('NR-' . uniqid())
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_CLIENT)
            ->setValidated(true)->setSignature('sig')
            ->setClient($client)->setBordereau($bordereau)
            ->setSentAt(new \DateTimeImmutable('now'));
        $note->setInvite($invite)->setEntreprise($e);
        $em->persist($note);

        if ($paye > 0.0) {
            $paiement = (new Paiement())
                ->setMontant($paye)->setPaidAt(new \DateTimeImmutable('now'))
                ->setReference('PAY-NR-' . uniqid())->setNote($note);
            $paiement->setEntreprise($e)->setInvite($invite);
            $em->persist($paiement);
            // ⚠ LES DEUX CÔTÉS, SINON LE SOLDE RESTE ENTIER. `setNote()` seul ne suffit
            // pas : la collection inverse de la note demeure l'ArrayCollection vide de
            // son constructeur tant que l'entité managée n'est pas re-hydratée, et le
            // calcul du montant payé, qui la parcourt, rendrait zéro.
            $note->addPaiement($paiement);
        }

        $em->flush();

        return $note;
    }

    /** @return array<int, array> les actions proposées sur le canevas de cette note */
    private function actionsDe(Entreprise $e, Note $note): array
    {
        /** @var CanvasBuilder $builder */
        $builder = static::getContainer()->get(CanvasBuilder::class);

        return $builder->getEntityFormCanvas($note, $e->getId())['parametres']['attribute_actions'] ?? [];
    }

    private function actionDeReglement(array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['event'] ?? null) === 'ui:note.paiement-request') {
                return $action;
            }
        }

        return null;
    }

    /**
     * L'ACTION EXISTE, ET ELLE PORTE SON LIBELLÉ.
     *
     * C'est la déclaration qui manquait : l'événement n'était émis que par le JavaScript
     * de la fenêtre de facturation, si bien que l'inventaire de l'assistant ne pouvait
     * nommer le geste que par son nom technique — et deux tests de parité tombaient.
     */
    public function testLActionEstDeclareeAvecSonLibelleEtSaRoute(): void
    {
        [$entreprise, $invite, $client] = $this->cabinet();
        $note = $this->note($entreprise, $invite, $client, 1000.0, 0.0);

        $action = $this->actionDeReglement($this->actionsDe($entreprise, $note));

        self::assertNotNull($action, 'La rubrique Notes doit proposer « Signaler le règlement ».');
        self::assertSame('Signaler le règlement', $action['label']);
        self::assertSame('paiement', $action['icon'], 'L\'alias vient d\'IconCanvasProvider.');
        self::assertSame('/admin/note/api/%id%/paiement-context', $action['url']);
        self::assertSame(['field' => 'aUnSoldeDu', 'value' => true], $action['condition']);
    }

    /**
     * ⚠ UNE NOTE SOLDÉE NE PROPOSE PLUS RIEN À RÉGLER.
     *
     * La condition se lit sur un indicateur, calculé par DEUX chemins — note à articles
     * et note de bordereau. Ce banc emprunte le second : c'est celui qu'on oublie, et son
     * oubli priverait du bouton la plupart des notes.
     */
    public function testLeBoutonDisparaitSurUneNoteSoldee(): void
    {
        [$entreprise, $invite, $client] = $this->cabinet();

        $impayee = $this->note($entreprise, $invite, $client, 1000.0, 0.0);
        $partielle = $this->note($entreprise, $invite, $client, 1000.0, 400.0);
        $soldee = $this->note($entreprise, $invite, $client, 1000.0, 1000.0);

        /** @var CanvasBuilder $builder */
        $builder = static::getContainer()->get(CanvasBuilder::class);
        foreach ([$impayee, $partielle, $soldee] as $note) {
            $builder->loadAllCalculatedValues($note);
        }

        self::assertTrue($impayee->aUnSoldeDu, 'Rien n\'a été encaissé : tout reste dû.');
        self::assertTrue($partielle->aUnSoldeDu, 'Un règlement partiel laisse un solde.');
        self::assertFalse($soldee->aUnSoldeDu,
            'Proposer de régler ce qui est payé inviterait à un double encaissement.',
        );
    }

    /**
     * LA BARRE D'OUTILS N'AFFICHE QUE QUATRE ENTRÉES EN LIGNE ; au-delà, le surplus tombe
     * dans « Autres actions ». La rubrique Notes en comptait trois : la quatrième tient
     * encore, sans regroupement. La cinquième ne tiendrait pas.
     */
    public function testLaRubriqueResteSousLePlafondDeLaBarre(): void
    {
        [$entreprise, $invite, $client] = $this->cabinet();
        $note = $this->note($entreprise, $invite, $client, 1000.0, 0.0);

        $actions = $this->actionsDe($entreprise, $note);
        $entrees = [];
        foreach ($actions as $action) {
            // Une famille compte pour UNE entrée, quel que soit son nombre de membres.
            $entrees[$action['groupe'] ?? ('seule:' . $action['event'] . $action['label'])] = true;
        }

        self::assertLessThanOrEqual(4, count($entrees), sprintf(
            'Au-delà de quatre entrées, le surplus quitte la barre. Entrées : %s.',
            implode(', ', array_keys($entrees)),
        ));
    }

    /**
     * LA ROUTE NOMME LA COLLECTION QUI ATTEND LE PAIEMENT.
     *
     * C'est par ce nom que le cerveau retrouve le widget « Paiements liés » à l'écran et
     * lui adresse l'enregistrement. Sans lui, le paiement n'apparaîtrait qu'au prochain
     * chargement de la note — ce que le propriétaire a signalé.
     */
    public function testLaRouteNommeLaCollectionEtPreremplitLeSolde(): void
    {
        [$entreprise, $invite, $client] = $this->cabinet();
        $note = $this->note($entreprise, $invite, $client, 1000.0, 400.0);

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));
        $this->client->request('GET', sprintf('/admin/note/api/%d/paiement-context', $note->getId()));
        self::assertResponseIsSuccessful();

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('paiements', $charge['collection'],
            'Le nom de la collection vient du serveur : c\'est le même fournisseur qui '
            . 'déclare l\'action et le widget.',
        );
        self::assertSame($note->getId(), $charge['noteId']);
        self::assertEqualsWithDelta(600.0, (float) $charge['solde'], 0.01,
            'Facturé 1000, encaissé 400 : il reste 600 à réclamer.',
        );
    }

    /** Une note entièrement payée ne prérenseigne aucun montant. */
    public function testUneNoteSoldeeNeProposeAucunMontant(): void
    {
        [$entreprise, $invite, $client] = $this->cabinet();
        $note = $this->note($entreprise, $invite, $client, 1000.0, 1000.0);

        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));
        $this->client->request('GET', sprintf('/admin/note/api/%d/paiement-context', $note->getId()));
        self::assertResponseIsSuccessful();

        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($charge['solde'],
            'Mieux vaut un champ vide qu\'un montant que personne ne doit.',
        );
    }
}
