<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Utilisateur;
use App\Tests\Security\SemisDeDeuxCabinetsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UNE NOTE SE CRÉE PAR SON DIALOGUE.
 *
 * Elle ne le pouvait pas : « Référence : ce champ est obligatoire » (422), à chaque
 * fois. Le formulaire montre la référence DÉSACTIVÉE — « Générée automatiquement » —,
 * donc jamais soumise ; la colonne est NOT NULL ; et le contrôle des champs obligatoires
 * passait AVANT que la référence ne soit posée (`beforePersist`). Le défaut précédait le
 * chantier de la vue Cartes : même ordre, même champ, au commit 9d850466.
 *
 * Et la note née d'un bordereau : rattachée à lui, qui passe à « Note émise » — mais
 * jamais un bordereau d'un autre cabinet.
 */
class NoteCreationDialogueTest extends WebTestCase
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

    /** @return array{0: array, 1: int, 2: int} les deux cabinets, l'invité et l'assureur de A */
    private function semerEtConnecterA(): array
    {
        $s = $this->semerLesDeuxCabinets();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($s['a']['owner']));
        $invite = $this->em()->getRepository(Invite::class)->findOneBy(['entreprise' => $s['a']['entreprise']]);
        $assureur = $this->identifiantsDuCabinet(Assureur::class, $s['a']['entreprise'])[0];

        return [$s, (int) $invite->getId(), (int) $assureur];
    }

    /** Ce que le dialogue envoie pour une note de débit à un assureur. */
    private function champsDuDialogue(array $s, int $invite, int $assureur, string $nom): array
    {
        return [
            'idEntreprise' => $s['a']['entreprise'],
            'idInvite'     => $invite,
            'nom'          => $nom,
            'type'         => (string) Note::TYPE_NOTE_DE_DEBIT,
            'addressedTo'  => (string) Note::TO_ASSUREUR,
            'assureur'     => (string) $assureur,
        ];
    }

    private function noteNommee(string $nom): ?Note
    {
        $this->em()->clear();

        return $this->em()->getRepository(Note::class)->findOneBy(['nom' => $nom]);
    }

    public function testUneNoteSeCreeParLeDialogue(): void
    {
        [$s, $invite, $assureur] = $this->semerEtConnecterA();

        $this->client->request('POST', '/admin/note/api/submit', $this->champsDuDialogue($s, $invite, $assureur, 'Note créée par le dialogue'));

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $note = $this->noteNommee('Note créée par le dialogue');
        self::assertNotNull($note, 'La note doit exister en base.');
        self::assertMatchesRegularExpression('/^N\d+$/', (string) $note->getReference(), 'Référence générée automatiquement.');
    }

    /**
     * LES VALEURS DE NAISSANCE NE SE CHOISISSENT PAS DEPUIS LA REQUÊTE. Posées désormais
     * avant le contrôle des champs, elles ne doivent rien céder à un client qui les
     * enverrait : la référence est un champ désactivé, `validated` et `signature` ne sont
     * pas des champs du formulaire.
     */
    public function testLesValeursDeNaissanceIgnorentCeQueLaRequeteEnvoie(): void
    {
        [$s, $invite, $assureur] = $this->semerEtConnecterA();

        $this->client->request('POST', '/admin/note/api/submit', $this->champsDuDialogue($s, $invite, $assureur, 'Note forcée')
            + ['reference' => 'PIRATE', 'validated' => '1', 'signature' => 'PIRATE']);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $note = $this->noteNommee('Note forcée');
        self::assertMatchesRegularExpression('/^N\d+$/', (string) $note->getReference(), 'La référence envoyée est ignorée.');
        self::assertFalse($note->isValidated(), 'Une note naît NON validée, quoi que dise la requête.');
        self::assertNotSame('PIRATE', $note->getSignature());
        self::assertNotNull($note->getSignature());
        self::assertNotNull($note->getSentAt());
    }

    public function testUneNoteNeeDUnBordereauDuCabinetLuiEstRattachee(): void
    {
        [$s, $invite, $assureur] = $this->semerEtConnecterA();
        $bordereau = $this->identifiantsDuCabinet(Bordereau::class, $s['a']['entreprise'])[0];

        $this->client->request('POST', '/admin/note/api/submit', $this->champsDuDialogue($s, $invite, $assureur, 'Note du bordereau')
            + ['parent_field_name' => 'bordereau', 'parent_id' => (string) $bordereau]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $note = $this->noteNommee('Note du bordereau');
        self::assertSame($bordereau, $note?->getBordereau()?->getId(), 'La note est rattachée à son bordereau.');
        self::assertSame(Bordereau::STEP_NOTE_EMISE, $this->em()->find(Bordereau::class, $bordereau)->getCurrentAnalysisStep(),
            'Le bordereau passe à « Note émise » dans la même écriture.');
    }

    public function testLeBordereauDUnAutreCabinetNeSeFacturePas(): void
    {
        [$s, $invite, $assureur] = $this->semerEtConnecterA();
        $etranger = $this->identifiantsDuCabinet(Bordereau::class, $s['b']['entreprise'])[0];
        $etapeAvant = $this->em()->find(Bordereau::class, $etranger)->getCurrentAnalysisStep();

        $this->client->request('POST', '/admin/note/api/submit', $this->champsDuDialogue($s, $invite, $assureur, 'Note pirate')
            + ['parent_field_name' => 'bordereau', 'parent_id' => (string) $etranger]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->noteNommee('Note pirate'), 'Aucune note ne doit être écrite.');
        self::assertSame($etapeAvant, $this->em()->find(Bordereau::class, $etranger)->getCurrentAnalysisStep(),
            'Le bordereau étranger ne bouge pas.');
    }
}
