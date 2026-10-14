<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Utilisateur;
use App\Service\Note\NumerotationDesNotes;
use App\Service\Workspace\WorkspaceMutationService;
use App\Tests\Security\SemisDeDeuxCabinetsTrait;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA RÉFÉRENCE D'UNE NOTE EST UN NUMÉRO : unique dans le cabinet, et chronologique.
 *
 * Décisions du 2026-10-14 : le produit génère `ND-AAAA-NNNNN` / `NC-AAAA-NNNNN` (une série
 * par cabinet, par année et par type) ; le champ reste éditable ; une référence n'appartient
 * qu'à une note du cabinet. Avant : `'N' . time()`, sans ordre ni unicité.
 */
class NumerotationDesNotesTest extends WebTestCase
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

    private static function annee(): int
    {
        return (int) (new \DateTimeImmutable())->format('Y');
    }

    /** Le numéro attendu, dans l'année courante. */
    private static function numero(string $prefixe, int $sequence): string
    {
        return sprintf('%s-%d-%05d', $prefixe, self::annee(), $sequence);
    }

    /** Se connecte comme propriétaire du cabinet `a` ou `b` ; rend son entreprise, son invité et un assureur. */
    private function connecter(array $s, string $cabinet): array
    {
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($s[$cabinet]['owner']));
        $invite = $this->em()->getRepository(Invite::class)->findOneBy(['entreprise' => $s[$cabinet]['entreprise']]);

        return [
            'entreprise' => $s[$cabinet]['entreprise'],
            'invite'     => (int) $invite->getId(),
            'assureur'   => (int) $this->identifiantsDuCabinet(Assureur::class, $s[$cabinet]['entreprise'])[0],
        ];
    }

    /** Une note créée par le dialogue ; rend la réponse décodée et le statut. */
    private function creerParLeDialogue(array $c, string $nom, array $plus = []): array
    {
        $this->client->request('POST', '/admin/note/api/submit', $plus + [
            'idEntreprise' => $c['entreprise'],
            'idInvite'     => $c['invite'],
            'nom'          => $nom,
            'type'         => (string) Note::TYPE_NOTE_DE_DEBIT,
            'addressedTo'  => (string) Note::TO_ASSUREUR,
            'assureur'     => (string) $c['assureur'],
        ]);
        $reponse = $this->client->getResponse();

        return ['statut' => $reponse->getStatusCode(), 'corps' => json_decode((string) $reponse->getContent(), true) ?? []];
    }

    private function referenceDe(string $nom): ?string
    {
        $this->em()->clear();

        return $this->em()->getRepository(Note::class)->findOneBy(['nom' => $nom])?->getReference();
    }

    public function testUneNoteSansReferenceRecoitLeNumeroSuivantDeSaSerie(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');

        $this->creerParLeDialogue($c, 'Première note');
        $this->creerParLeDialogue($c, 'Deuxième note');

        self::assertSame(self::numero('ND', 1), $this->referenceDe('Première note'));
        self::assertSame(self::numero('ND', 2), $this->referenceDe('Deuxième note'), 'Chronologique : la suivante prend le numéro suivant.');
    }

    /** Une note de crédit a sa propre série : aucune des deux ne présente de trou dû à l'autre. */
    public function testUneNoteDeCreditASaPropreSerie(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');

        $this->creerParLeDialogue($c, 'Débit');
        $this->creerParLeDialogue($c, 'Crédit', ['type' => (string) Note::TYPE_NOTE_DE_CREDIT]);
        $this->creerParLeDialogue($c, 'Débit suivant');

        self::assertSame(self::numero('ND', 1), $this->referenceDe('Débit'));
        self::assertSame(self::numero('NC', 1), $this->referenceDe('Crédit'));
        self::assertSame(self::numero('ND', 2), $this->referenceDe('Débit suivant'));
    }

    /** Chaque cabinet a ses séries ; et une note écrite hors de l'écran est numérotée pareil. */
    public function testChaqueCabinetASesSeries(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $this->creerParLeDialogue($this->connecter($s, 'a'), 'Note de A');

        $em = $this->em();
        $noteB = (new Note())->setNom('Note de B')->setType(Note::TYPE_NOTE_DE_DEBIT)
            ->setAddressedTo(Note::TO_ASSUREUR)->setSignature('t')->setValidated(false);
        $noteB->setEntreprise($em->find(Entreprise::class, $s['b']['entreprise']));
        $em->persist($noteB);
        $em->flush();

        self::assertSame(self::numero('ND', 1), $this->referenceDe('Note de A'));
        self::assertSame(self::numero('ND', 1), $this->referenceDe('Note de B'));
    }

    /** LE CHAMP RESTE ÉDITABLE : une référence saisie est conservée et ne consomme aucun numéro. */
    public function testUneReferenceSaisieEstConserveeEtNeConsommeAucunNumero(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');

        $reponse = $this->creerParLeDialogue($c, 'Note référencée', ['reference' => 'CONV-ASSUREUR-7']);
        self::assertSame(200, $reponse['statut'], json_encode($reponse['corps'], JSON_UNESCAPED_UNICODE));
        $this->creerParLeDialogue($c, 'Note numérotée');

        self::assertSame('CONV-ASSUREUR-7', $this->referenceDe('Note référencée'));
        self::assertSame(self::numero('ND', 1), $this->referenceDe('Note numérotée'));
    }

    /** Un numéro déjà saisi à la main dans le cabinet est sauté : la série ne collisionne jamais. */
    public function testUnNumeroDejaSaisiALaMainEstSaute(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');

        $this->creerParLeDialogue($c, 'Saisie à la main', ['reference' => self::numero('ND', 1)]);
        $this->creerParLeDialogue($c, 'Générée');

        self::assertSame(self::numero('ND', 2), $this->referenceDe('Générée'));
    }

    public function testUneReferenceDejaPorteeDansLeCabinetEstRefusee(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');
        $this->creerParLeDialogue($c, 'Originale', ['reference' => 'DOUBLON-1']);

        // La casse ne distingue pas deux références : la base non plus.
        $reponse = $this->creerParLeDialogue($c, 'Copie', ['reference' => 'doublon-1']);

        self::assertSame(422, $reponse['statut']);
        self::assertStringContainsString(
            'La référence « doublon-1 » est déjà portée par une autre note de ce cabinet. '
            . 'Choisissez-en une autre, ou laissez le champ vide pour recevoir le numéro suivant.',
            json_encode($reponse['corps'], JSON_UNESCAPED_UNICODE),
        );
        self::assertNull($this->referenceDe('Copie'), 'Rien n’est écrit.');
    }

    public function testLaMemeReferenceDansDeuxCabinetsEstPermise(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $this->creerParLeDialogue($this->connecter($s, 'a'), 'Chez A', ['reference' => 'PARTAGEE']);

        $reponse = $this->creerParLeDialogue($this->connecter($s, 'b'), 'Chez B', ['reference' => 'PARTAGEE']);

        self::assertSame(200, $reponse['statut'], json_encode($reponse['corps'], JSON_UNESCAPED_UNICODE));
        self::assertSame('PARTAGEE', $this->referenceDe('Chez B'));
    }

    /** En modification : reprendre le numéro d'une autre note est refusé ; le vider aussi. */
    public function testModifierUneNoteNePeutNiReprendreUnNumeroNiLeVider(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');
        $this->creerParLeDialogue($c, 'Première');
        $this->creerParLeDialogue($c, 'Seconde');
        $this->em()->clear();
        $seconde = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Seconde']);

        $reponse = $this->creerParLeDialogue($c, 'Seconde', ['id' => (string) $seconde->getId(), 'reference' => self::numero('ND', 1)]);
        self::assertSame(422, $reponse['statut']);
        self::assertStringContainsString(
            'La référence « ' . self::numero('ND', 1) . ' » est déjà portée par une autre note de ce cabinet. Choisissez-en une autre.',
            json_encode($reponse['corps'], JSON_UNESCAPED_UNICODE),
        );

        $reponse = $this->creerParLeDialogue($c, 'Seconde', ['id' => (string) $seconde->getId(), 'reference' => '']);
        self::assertSame(422, $reponse['statut'], 'Une note émise garde un numéro.');

        $reponse = $this->creerParLeDialogue($c, 'Seconde', ['id' => (string) $seconde->getId(), 'reference' => 'ND-RENOMMEE']);
        self::assertSame(200, $reponse['statut'], 'Le champ reste éditable.');
        self::assertSame('ND-RENOMMEE', $this->referenceDe('Seconde'));
    }

    /** Ket écrit par le circuit commun : sa note est numérotée, et le doublon refusé avant le plan. */
    public function testKetNumeroteEtRefuseLeDoublon(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $c = $this->connecter($s, 'a');
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        $op = fn (array $plus = []) => new MutationOperation('create', 'Note', null, $plus + [
            'nom'         => 'Note de Ket',
            'type'        => Note::TYPE_NOTE_DE_DEBIT,
            'addressedTo' => Note::TO_ASSUREUR,
            'assureur'    => $c['assureur'],
        ]);

        $em->wrapInTransaction(fn () => $service->executer($op(), $scope, $em->find(Utilisateur::class, $s['a']['owner'])));
        self::assertSame(self::numero('ND', 1), $this->referenceDe('Note de Ket'));

        $analyse = $service->analyserOperation($op(['nom' => 'Copie de Ket', 'reference' => self::numero('ND', 1)]), $scope);
        self::assertFalse($analyse['ok']);
        self::assertStringContainsString('est déjà portée par une autre note de ce cabinet', json_encode($analyse, JSON_UNESCAPED_UNICODE));
    }

    /** Ket ne vide pas la référence d'une note émise : refus lisible, et non une erreur SQL. */
    public function testKetNeVidePasLaReferenceDUneNoteEmise(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $c = $this->connecter($s, 'a');
        $this->creerParLeDialogue($c, 'Note émise');
        $this->em()->clear();
        $note = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Note émise']);

        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        $op = new MutationOperation('edit', 'Note', (int) $note->getId(), ['reference' => '']);

        $analyse = $service->analyserOperation($op, $scope);
        self::assertFalse($analyse['ok']);
        self::assertStringContainsString('Une note émise garde sa référence', json_encode($analyse, JSON_UNESCAPED_UNICODE));

        try {
            $em->wrapInTransaction(fn () => $service->executer($op, $scope, $em->find(Utilisateur::class, $s['a']['owner'])));
            self::fail('L’exécution refuse aussi.');
        } catch (\App\Service\Workspace\MutationException) {
        }
        self::assertSame(self::numero('ND', 1), $this->referenceDe('Note émise'));
    }

    /** Une référence tient sur une ligne et dans la colonne : sinon 422, pas une erreur SQL. */
    public function testUneReferenceTropLongueOuSurPlusieursLignesEstRefusee(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');

        foreach (['longue' => str_repeat('R', 256), 'deux lignes' => "REF\nPIRATE"] as $cas => $reference) {
            $reponse = $this->creerParLeDialogue($c, 'Note ' . $cas, ['reference' => $reference]);
            self::assertSame(422, $reponse['statut'], $cas);
            self::assertStringContainsString('Une référence tient sur une ligne, en 255 caractères au plus.',
                json_encode($reponse['corps'], JSON_UNESCAPED_UNICODE), $cas);
        }
    }

    /** Une saisie libre nomme le PDF : guillemet, barre oblique et accent ne cassent pas l'en-tête. */
    public function testLeNomDuPdfSupporteUneReferenceLibre(): void
    {
        $c = $this->connecter($this->semerLesDeuxCabinets(), 'a');
        // Le PDF écrit le montant en lettres : il lui faut un vrai code ISO (le semis pose « USA »).
        $this->em()->getConnection()->executeStatement('UPDATE monnaie SET code = ? WHERE entreprise_id = ?', ['USD', $c['entreprise']]);
        $this->creerParLeDialogue($c, 'Note au nom libre', ['reference' => 'Réf "A"/2026']);
        $this->em()->clear();
        $note = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Note au nom libre']);

        $this->client->request('GET', '/admin/note/download-pdf/' . $note->getId());

        self::assertResponseIsSuccessful();
        $entete = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertSame("inline; filename=note-R_f__A_-2026.pdf; filename*=utf-8''note-R%C3%A9f%20%22A%22-2026.pdf", $entete);
    }

    /**
     * DEUX ENREGISTREMENTS SIMULTANÉS OBTIENNENT DEUX NUMÉROS. Une seconde connexion prend
     * un numéro dans une transaction laissée ouverte : la première ATTEND (ici jusqu'au
     * délai d'une seconde) au lieu de lire la même séquence ; une fois l'autre validée,
     * elle obtient le numéro suivant.
     */
    public function testDeuxEnregistrementsSimultanesObtiennentDeuxNumeros(): void
    {
        $s = $this->semerLesDeuxCabinets();
        $cabinet = $s['a']['entreprise'];
        $connexion = $this->em()->getConnection();
        $autre = DriverManager::getConnection($connexion->getParams());
        $ici = new NumerotationDesNotes($connexion);
        $ailleurs = new NumerotationDesNotes($autre);

        self::assertSame(self::numero('ND', 1), $ici->prochaine($cabinet, Note::TYPE_NOTE_DE_DEBIT, self::annee()));

        $autre->beginTransaction();
        self::assertSame(self::numero('ND', 2), $ailleurs->prochaine($cabinet, Note::TYPE_NOTE_DE_DEBIT, self::annee()));

        $connexion->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $ici->prochaine($cabinet, Note::TYPE_NOTE_DE_DEBIT, self::annee());
            self::fail('Tant que l’autre écriture n’est pas validée, la série est verrouillée.');
        } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
        } finally {
            $autre->commit();
            $autre->close();
            $connexion->executeStatement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        self::assertSame(self::numero('ND', 3), $ici->prochaine($cabinet, Note::TYPE_NOTE_DE_DEBIT, self::annee()));
    }
}
