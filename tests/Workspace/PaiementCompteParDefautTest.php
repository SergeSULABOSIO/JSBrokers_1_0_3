<?php

namespace App\Tests\Workspace;

use App\Entity\Bordereau;
use App\Entity\CompteBancaire;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * OÙ ENCAISSER : un champ obligatoire ne doit jamais s'ouvrir vide.
 *
 * ── LA PANNE QUE CE BANC FERME ──────────────────────────────────────────────────────
 * Le formulaire de paiement exige un compte bancaire. Il le préremplissait depuis la note
 * — mais seulement si la note en portait un. Une note composée à la main, ou issue d'un
 * bordereau, n'en porte aucun : le champ s'ouvrait vide, et l'enregistrement échouait sur
 * « Ce champ est obligatoire » au terme d'une saisie déjà faite.
 *
 * ── ET UN DÉFAUT LATENT, TROUVÉ EN CHEMIN ───────────────────────────────────────────
 * La note était retrouvée par `parent_id` SANS regarder `parent_field_name`. Or un
 * paiement a deux parents possibles : une note, ou une offre d'indemnisation de sinistre.
 * Ouvert depuis une offre, l'identifiant de celle-ci était cherché parmi les NOTES — et
 * une note sans rapport pouvait prêter son montant, son compte et son destinataire. Le
 * formulaire ne signalait rien : il s'ouvrait prérempli de travers.
 */
class PaiementCompteParDefautTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-compte-defaut@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Compte Defaut SARL';
    private const PASSWORD = 'Test1234!';

    private const COMPTE_PREMIER = 'ZZ-COMPTE-PREMIER-ENREGISTRE';
    private const COMPTE_DE_LA_NOTE = 'ZZ-COMPTE-PORTE-PAR-LA-NOTE';

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
        $conn->executeStatement(
            'DELETE nc FROM note_compte_bancaire nc
             JOIN note n ON nc.note_id = n.id
             JOIN entreprise e ON n.entreprise_id = e.id
             WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach (['paiement', 'note', 'bordereau', 'offre_indemnisation_sinistre', 'compte_bancaire'] as $table) {
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

    /** @return array{0: Entreprise, 1: Invite, 2: CompteBancaire, 3: CompteBancaire} */
    private function cabinet(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new Utilisateur();
        $user->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-CD')->setAdresse('1 rue du Test')
            ->setTelephone('+243000000000')->setRccm('RCCM-CD')->setIdnat('IDNAT-CD')->setNumimpot('IMP-CD');
        $entreprise->setUtilisateur($user);
        $user->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Administrateur')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        // ⚠ L'ORDRE D'ENREGISTREMENT EST L'ENJEU. Le premier compte créé porte un nom qui
        // vient APRÈS l'autre dans l'alphabet : un défaut trié par libellé choisirait le
        // mauvais, et ce test le verrait.
        $premier = (new CompteBancaire())
            ->setIntitule(self::COMPTE_PREMIER)->setNumero('CD-001')
            ->setBanque('Banque Test')->setCodeSwift('TESTCDKI');
        $premier->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($premier);

        $celuiDeLaNote = (new CompteBancaire())
            ->setIntitule(self::COMPTE_DE_LA_NOTE)->setNumero('CD-002')
            ->setBanque('Banque Test')->setCodeSwift('TESTCDKI');
        $celuiDeLaNote->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($celuiDeLaNote);

        $em->flush();

        return [$entreprise, $invite, $premier, $celuiDeLaNote];
    }

    private function note(Entreprise $e, Invite $invite, ?CompteBancaire $compte): Note
    {
        $em = $this->em();

        $bordereau = (new Bordereau())
            ->setType(0)->setNom('BRD CD')->setReference('BRD-CD-' . uniqid())
            ->setReceivedAt(new \DateTimeImmutable('now'))
            ->setPeriodeDebut(new \DateTimeImmutable('now'))
            ->setPeriodeFin(new \DateTimeImmutable('now'))
            ->setMontantComHtPayableNow(1000.0)
            ->setMontantTaxePayableNow(160.0);
        $bordereau->setInvite($invite)->setEntreprise($e);
        $em->persist($bordereau);

        $note = (new Note())
            ->setNom('Note CD')->setReference('CD-' . uniqid())
            ->setType(Note::TYPE_NOTE_DE_DEBIT)->setAddressedTo(Note::TO_ASSUREUR)
            ->setValidated(true)->setSignature('sig')->setBordereau($bordereau)
            ->setSentAt(new \DateTimeImmutable('now'));
        $note->setInvite($invite)->setEntreprise($e);
        if ($compte !== null) {
            $note->addCompte($compte);
        }
        $em->persist($note);
        $em->flush();

        return $note;
    }

    private function connecter(): void
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]));
    }

    /** Le formulaire de paiement ouvert sur ce parent, tel que le dialogue le demande. */
    private function formulaireDePaiement(string $champParent, int $idParent): string
    {
        $this->client->request('GET', sprintf(
            '/admin/paiement/api/get-form?parent_field_name=%s&parent_id=%d',
            $champParent,
            $idParent,
        ));
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * L'identifiant du compte PRÉSÉLECTIONNÉ, ou null si le champ s'ouvre vide.
     *
     * ⚠ ON LIT L'IDENTIFIANT, PAS LE LIBELLÉ. L'étiquette de ce champ est rendue en HTML
     * par un fournisseur dédié, et elle affiche `nom` — pas `intitule`. Un test écrit sur
     * le libellé échouerait pour une raison qui n'a rien à voir avec le défaut, et
     * passerait le jour où le rendu changerait de champ.
     */
    private function compteSelectionne(string $html): ?int
    {
        return preg_match('/<option value="(\d+)" selected="selected"/', $html, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * LE COMPTE DE LA NOTE L'EMPORTE — c'est celui qu'elle annonce à son destinataire,
     * et donc celui sur lequel les fonds arrivent.
     */
    public function testLeCompteDeLaNoteEstPreselectionne(): void
    {
        [$entreprise, $invite, , $celuiDeLaNote] = $this->cabinet();
        $note = $this->note($entreprise, $invite, $celuiDeLaNote);
        $this->connecter();

        $html = $this->formulaireDePaiement('note', $note->getId());

        self::assertSame(
            $celuiDeLaNote->getId(),
            $this->compteSelectionne($html),
            'Encaisser ailleurs que là où la pièce l\'a demandé ferait diverger la '
            . 'comptabilité de ce que l\'assureur a lu.',
        );
    }

    /**
     * ⚠ SANS COMPTE SUR LA NOTE, LE CHAMP NE RESTE PAS VIDE.
     *
     * Une note composée à la main, ou issue d'un bordereau, n'en porte aucun. Le champ
     * étant obligatoire, il s'ouvrait vide et l'enregistrement échouait sur « Ce champ est
     * obligatoire » — au terme d'une saisie déjà faite.
     */
    public function testSansCompteSurLaNoteLePremierDuCabinetEstRetenu(): void
    {
        [$entreprise, $invite, $premier, $celuiDeLaNote] = $this->cabinet();
        $note = $this->note($entreprise, $invite, null);
        $this->connecter();

        $retenu = $this->compteSelectionne($this->formulaireDePaiement('note', $note->getId()));

        self::assertSame($premier->getId(), $retenu,
            'Le premier compte ENREGISTRÉ, et non le premier par ordre alphabétique : '
            . 'renommer un compte ne doit pas changer le défaut proposé.',
        );
        self::assertNotSame($celuiDeLaNote->getId(), $retenu,
            'Le second compte n\'a aucune raison d\'être proposé ici.',
        );
    }

    /**
     * ⚠ UN PAIEMENT OUVERT DEPUIS UNE OFFRE D'INDEMNISATION NE PREND RIEN À UNE NOTE.
     *
     * `parent_id` était lu sans regarder `parent_field_name`. L'identifiant de l'offre
     * était donc cherché parmi les NOTES, et une note sans rapport — celle qui portait ce
     * numéro — prêtait son montant, son compte et son libellé de destinataire. Rien ne le
     * signalait : le formulaire s'ouvrait simplement prérempli de travers.
     */
    public function testUnPaiementDOffreNEmprunteRienAUneNoteHomonyme(): void
    {
        [$entreprise, $invite, , $celuiDeLaNote] = $this->cabinet();
        $note = $this->note($entreprise, $invite, $celuiDeLaNote);

        // L'offre prend DÉLIBÉRÉMENT l'identifiant de la note : c'est la collision que
        // l'ancien code ne distinguait pas.
        $offre = (new OffreIndemnisationSinistre())
            ->setMontantPayable(777.0)->setBeneficiaire('Sinistré test');
        $offre->setEntreprise($entreprise)->setInvite($invite);
        $this->em()->persist($offre);
        $this->em()->flush();

        $this->connecter();
        $html = $this->formulaireDePaiement('offreIndemnisationSinistre', $note->getId());

        self::assertNotSame($celuiDeLaNote->getId(), $this->compteSelectionne($html),
            'Le compte d\'une note sans rapport ne doit pas être proposé pour l\'indemnisation '
            . 'd\'un sinistre.',
        );
        self::assertStringNotContainsString('Règlement relatif à la Note', $html,
            'Ni son libellé de destinataire.',
        );
    }
}
