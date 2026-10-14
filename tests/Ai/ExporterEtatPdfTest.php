<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\ExporterEtatTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Utilisateur;
use App\Tests\Security\SemisDeDeuxCabinetsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE PDF QUE KET OUVRE EST UN PDF QUI S'OUVRE.
 *
 * `exporter_etat` envoyait vers l'ancien état TCPDF (`admin.etats.imprimer_note` /
 * `imprimer_bordereau_note`), en 500 pour tout destinataire : `Article::getMontant()` et
 * `Article::getIdPoste()` n'existaient plus. Décision du 2026-10-14 : les deux exports
 * ouvrent le PDF moderne de la note (`admin.note.download_pdf`), qui fusionne le bordereau
 * d'assureur quand la note en porte un ; l'ancien état est supprimé.
 *
 * Et ce PDF garde le contrôle de l'ancienne route : la note du cabinet ouvert, et le droit
 * de LECTURE des notes.
 */
class ExporterEtatPdfTest extends WebTestCase
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

    /** Le cabinet A semé, son propriétaire connecté, et une de ses notes. */
    private function semerEtConnecter(): array
    {
        $s = $this->semerLesDeuxCabinets();
        // Le PDF écrit le montant en lettres : il lui faut un vrai code ISO (le semis pose « USA »).
        $this->em()->getConnection()->executeStatement('UPDATE monnaie SET code = ? WHERE entreprise_id = ?', ['USD', $s['a']['entreprise']]);
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->find($s['a']['owner']));

        return [$s, (int) $this->identifiantsDuCabinet(Note::class, $s['a']['entreprise'])[0]];
    }

    /** @dataProvider lesDeuxExports */
    public function testLeLienDeKetOuvreUnPdf(string $etat): void
    {
        [$s, $note] = $this->semerEtConnecter();
        $em = $this->em();
        $scope = new AiScope(
            $em->find(Entreprise::class, $s['a']['entreprise']),
            $em->getRepository(Invite::class)->findOneBy(['entreprise' => $s['a']['entreprise'], 'proprietaire' => true]),
        );

        $resultat = static::getContainer()->get(ExporterEtatTool::class)->execute(['etat' => $etat, 'idNote' => $note], $scope);
        $url = (string) ($resultat->uiAction['url'] ?? '');
        self::assertSame('/admin/note/download-pdf/' . $note, $url);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful(urldecode((string) $this->client->getResponse()->headers->get('X-Debug-Exception')));
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public static function lesDeuxExports(): iterable
    {
        yield 'note' => ['note_pdf'];
        yield 'note et bordereau' => ['bordereau_pdf'];
    }

    /**
     * UNE NOTE NE SE MONTRE QU'À QUI LIT LES NOTES, sous aucune forme (décision du
     * 2026-10-14) : PDF, aperçu, aperçu imprimable, colonne de travail — ni depuis le
     * bordereau qui la porte. Le propriétaire, lui, voit tout.
     *
     * @dataProvider lesCinqPortes
     */
    public function testUneNoteNeSeMontreQuAQuiLitLesNotes(string $gabarit): void
    {
        [$s, $note] = $this->semerEtConnecter();
        $bordereau = (int) $this->identifiantsDuCabinet(\App\Entity\Bordereau::class, $s['a']['entreprise'])[0];
        // Le bordereau porte la note : sans quoi ses deux portes répondraient 404 avant tout.
        $this->em()->getConnection()->executeStatement('UPDATE note SET bordereau_id = ? WHERE id = ?', [$bordereau, $note]);
        $url = strtr($gabarit, ['{note}' => $note, '{bordereau}' => $bordereau]);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('Le propriétaire voit la note : ' . urldecode((string) $this->client->getResponse()->headers->get('X-Debug-Exception')));

        // Le propriétaire redevient un invité ordinaire, sans aucun rôle : il ne lit pas les notes.
        // (Le propriétaire se reconnaît aussi au titulaire du cabinet : on le confie à l'autre.)
        $connexion = $this->em()->getConnection();
        $connexion->executeStatement('UPDATE invite SET proprietaire = 0 WHERE entreprise_id = ?', [$s['a']['entreprise']]);
        $connexion->executeStatement('UPDATE entreprise SET utilisateur_id = ? WHERE id = ?', [$s['b']['owner'], $s['a']['entreprise']]);

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public static function lesCinqPortes(): iterable
    {
        yield 'PDF' => ['/admin/note/download-pdf/{note}'];
        yield 'aperçu imprimable' => ['/admin/note/apercu/{note}?print=1'];
        yield 'colonne de travail' => ['/admin/note/workspace-apercu/{note}'];
        yield 'adresse de l’aperçu' => ['/admin/note/api/get-preview-url/{note}'];
        yield 'note du bordereau (adresse)' => ['/admin/bordereau/api/get-linked-note-preview-url/{bordereau}?download=1'];
        yield 'note du bordereau (contexte)' => ['/admin/bordereau/api/get-linked-note-context/{bordereau}'];
    }

    public function testLaNoteDUnAutreCabinetNeSeTelechargePas(): void
    {
        [$s] = $this->semerEtConnecter();
        $etrangere = (int) $this->identifiantsDuCabinet(Note::class, $s['b']['entreprise'])[0];

        $this->client->request('GET', '/admin/note/download-pdf/' . $etrangere);

        self::assertResponseStatusCodeSame(404);
    }
}
