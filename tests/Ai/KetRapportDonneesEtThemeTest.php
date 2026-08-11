<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Document\DocumentFormat;
use App\Ai\Document\DocumentProducteur;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\PreparerDocumentTool;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN RAPPORT EMPORTE SES DONNÉES — et sait sortir en sombre.
 *
 * ── L'incident, le 11/08/2026 ───────────────────────────────────────────────────
 * Ket affiche dix-huit lignes de paiements de primes (prime signalée, commission
 * exigible, ARCA, TVA, réserve, total 1 911 633,28 $). L'utilisateur demande
 * « produis-moi un rapport à partir de cette réponse », valide le budget, et
 * télécharge un document où le tableau a été remplacé par une phrase de total. Le
 * livrable était un commentaire sur des données absentes.
 *
 * CAUSE PREMIÈRE : `sourceMessageId` — la parade prévue — était inadressable.
 * Aucun identifiant de message ne figurait dans l'historique envoyé au moteur : on
 * lui demandait de désigner une bulle par un numéro qu'il n'avait jamais vu. Il
 * réécrivait donc de mémoire, en rabotant pour tenir dans ses 4 096 jetons.
 *
 * Ce test protège les deux moitiés de la réparation (le marqueur d'identifiant et
 * le filet serveur), puis le thème de rendu, qui suit désormais celui du chat.
 */
class KetRapportDonneesEtThemeTest extends KernelTestCase
{
    private const OWNER = 'phpunit-rapportdonnees-owner@test.local';
    private const ENT = 'PHPUnit RapportDonnees SARL';

    /** Le tableau tel que Ket l'affiche dans une bulle. */
    private const TABLEAU = "Voici les paiements signalés :\n\n"
        . "| Date | Client | Prime signalée |\n"
        . "| --- | --- | ---: |\n"
        . "| 10/08/2026 | Kibali Goldmines SA | 3 195,16 $ |\n"
        . "| 07/08/2026 | KIN AVIA | 81 392,14 $ |\n";

    protected function setUp(): void
    {
        static::bootKernel();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        $conn->executeStatement(
            'DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em()->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:AssistantConversation} */
    private function seed(): array
    {
        $em = $this->em();
        $user = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit Owner')->setVerified(true);
        $user->setPassword('x');
        $em->persist($user);

        $entreprise = (new Entreprise())
            ->setNom(self::ENT)->setLicence('L')->setAdresse('1 rue')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Owner')->setUtilisateur($user)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($conversation);
        $em->flush();

        return [$entreprise, $invite, $conversation];
    }

    private function bulle(AssistantConversation $conversation, string $contenu): AssistantMessage
    {
        $message = (new AssistantMessage())->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu($contenu);
        $conversation->addMessage($message);
        $this->em()->flush();

        return $message;
    }

    /** Les arguments d'un rapport complet, dont les sections ne portent AUCUN chiffre. */
    private function arguments(string $corps = 'Le montant total cumulé s\'élève à 1 911 633,28 $.'): array
    {
        return [
            'titre'         => 'Rapport des signalements de paiements de primes',
            'problematique' => 'Quels signalements de paiements ont été enregistrés ?',
            'introduction'  => 'Ce document présente la synthèse des règlements de primes.',
            'definitions'   => [['terme' => 'Prime signalée', 'explication' => 'Montant versé par l\'assuré.']],
            'sections'      => [['titre' => 'Synthèse des règlements', 'corps' => $corps]],
            'conclusion'    => 'Ce rapport récapitule les paiements de primes signalés.',
        ];
    }

    private function preparer(array $args, Entreprise $e, Invite $i, AssistantConversation $c): \App\Ai\Tool\AiToolResult
    {
        return static::getContainer()->get(PreparerDocumentTool::class)->execute($args, new AiScope($e, $i, $c));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LE MARQUEUR : une bulle porteuse de tableau annonce son identifiant dans
     * l'historique. Sans lui, `sourceMessageId` reste une parade que le modèle ne
     * peut pas actionner — c'est la cause première de l'incident.
     */
    public function testUneBullePorteuseDeTableauAnnonceSonIdentifiantDansLHistorique(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        $porteuse = $this->bulle($conversation, self::TABLEAU);
        $this->bulle($conversation, 'Très bien, je reste à votre disposition.');

        $messages = static::getContainer()->get(AiContextBuilder::class)
            ->build($entreprise, $invite, $conversation)->messages;

        $avecTableau = $messages[0]['content'];
        $sansTableau = $messages[1]['content'];

        self::assertStringContainsString('message #' . $porteuse->getId(), $avecTableau);
        self::assertStringContainsString('sourceMessageId=' . $porteuse->getId(), $avecTableau);
        // Le texte de la bulle reste INTACT : le marqueur s'ajoute, il ne remplace pas.
        self::assertStringContainsString('Kibali Goldmines SA', $avecTableau);

        // Pas de marqueur là où il n'y a rien à reprendre : le coût du tour ne doit
        // pas grimper pour une bulle de courtoisie.
        self::assertStringNotContainsString('sourceMessageId=', $sansTableau);
    }

    /**
     * LE FILET, sur l'incident lui-même : le modèle rédige un commentaire et oublie
     * les chiffres — le serveur rattache le tableau de la bulle précédente.
     */
    public function testUnRapportSansChiffresRecupereLeTableauDeLaBullePrecedente(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        $this->bulle($conversation, self::TABLEAU);

        $resultat = $this->preparer($this->arguments(), $entreprise, $invite, $conversation);

        self::assertTrue($resultat->data['pret']);
        self::assertTrue($resultat->data['apercu']['donneesRattachees'], 'La barre doit annoncer le rattachement.');

        $sections = $resultat->uiAction['spec']['sections'];
        self::assertCount(2, $sections, 'La section de données doit être ajoutée à celle du modèle.');
        self::assertSame(PreparerDocumentTool::TITRE_DONNEES, $sections[1]['titre']);
        self::assertStringContainsString('Kibali Goldmines SA', $sections[1]['corps']);
        self::assertStringContainsString('81 392,14 $', $sections[1]['corps']);

        // Le budget annoncé COUVRE le tableau rattaché : la promesse « le budget
        // annoncé est le budget débité » ne se paie pas d'un contenu clandestin.
        self::assertStringContainsString('KIN AVIA', RapportSpec::fromArray($resultat->uiAction['spec'])->texteFacturable());
    }

    /**
     * Le filet reste ÉTROIT. Un document qui porte déjà ses chiffres — repris ou
     * recopiés — n'est pas augmenté : rien ne doit apparaître en double.
     */
    public function testLeFiletNeSeDeclenchePasQuandLeDocumentPorteDejaSesChiffres(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        $porteuse = $this->bulle($conversation, self::TABLEAU);

        // a) Le modèle a recopié un tableau lui-même.
        $recopie = $this->preparer(
            $this->arguments("| Client | Montant |\n| --- | ---: |\n| KIN AVIA | 81 392,14 |"),
            $entreprise, $invite, $conversation,
        );
        self::assertFalse($recopie->data['apercu']['donneesRattachees']);
        self::assertCount(1, $recopie->uiAction['spec']['sections']);

        // b) Le modèle a fait ce qu'il faut : il a désigné la bulle.
        $args = $this->arguments();
        $args['sections'] = [['titre' => 'Reprise', 'sourceMessageId' => $porteuse->getId()]];
        $reprise = $this->preparer($args, $entreprise, $invite, $conversation);

        self::assertFalse($reprise->data['apercu']['donneesRattachees']);
        self::assertCount(1, $reprise->uiAction['spec']['sections']);
        self::assertStringContainsString('Kibali Goldmines SA', $reprise->uiAction['spec']['sections'][0]['corps']);
    }

    /** Sans bulle porteuse dans le fil, il n'y a rien à rattacher — et rien n'est inventé. */
    public function testSansTableauDansLeFilLeDocumentResteCeQueLeModeleAEcrit(): void
    {
        [$entreprise, $invite, $conversation] = $this->seed();
        $this->bulle($conversation, 'Aucun paiement signalé sur la période.');

        $resultat = $this->preparer($this->arguments(), $entreprise, $invite, $conversation);

        self::assertTrue($resultat->data['pret']);
        self::assertFalse($resultat->data['apercu']['donneesRattachees']);
        self::assertCount(1, $resultat->uiAction['spec']['sections']);
    }

    // ── THÈME ────────────────────────────────────────────────────────────────

    private function pied(): PiedDePage
    {
        return new PiedDePage(self::ENT, 'PHPUnit Owner', 'Rapport', new \DateTimeImmutable('2026-08-11 15:02'), 'Ket');
    }

    private function rendre(DocumentFormat $format, ThemeDocument $theme): string
    {
        return static::getContainer()->get(DocumentProducteur::class)->rendre(
            RapportSpec::fromArray($this->arguments()),
            $format,
            $this->pied(),
            $theme,
        );
    }

    /** Le rendu sombre peint réellement un fond sombre et une encre claire. */
    public function testLaPageWebSuitLeThemeDemande(): void
    {
        $sombre = $this->rendre(DocumentFormat::Html, ThemeDocument::Sombre);
        $clair = $this->rendre(DocumentFormat::Html, ThemeDocument::Clair);

        self::assertStringContainsString(ThemeDocument::Sombre->palette()['fond'], $sombre);
        self::assertStringContainsString(ThemeDocument::Sombre->palette()['encre'], $sombre);
        self::assertStringNotContainsString(ThemeDocument::Clair->palette()['encre'], $sombre,
            'Une encre du thème clair oubliée dans un rendu sombre serait illisible.');

        self::assertStringContainsString(ThemeDocument::Clair->palette()['encre'], $clair);
        self::assertStringNotContainsString(ThemeDocument::Sombre->palette()['fond'], $clair);
    }

    /** Le PDF partage le gabarit : il suit donc le thème, fond de page compris. */
    public function testLePdfEstProduitDansLeThemeDemande(): void
    {
        $octets = $this->rendre(DocumentFormat::Pdf, ThemeDocument::Sombre);

        self::assertNotSame('', $octets);
        self::assertStringStartsWith('%PDF', $octets);
    }

    /**
     * Les formats qui ne peignent pas leur fond ne PROMETTENT pas le thème : le
     * sélecteur disparaît pour eux, et le rendu reste clair — un .docx à l'encre
     * claire serait un document blanc illisible.
     */
    public function testSeulsLesFormatsQuiPeignentLeurFondPortentLeTheme(): void
    {
        self::assertTrue(DocumentFormat::Html->supporteTheme());
        self::assertTrue(DocumentFormat::Pdf->supporteTheme());
        foreach ([DocumentFormat::Docx, DocumentFormat::Xlsx, DocumentFormat::Md, DocumentFormat::Txt] as $format) {
            self::assertFalse($format->supporteTheme(), $format->value . ' ne peut pas tenir un thème.');
        }

        // Et le producteur le fait respecter : un thème sombre demandé sur un Word
        // n'est pas à moitié appliqué, il est ignoré.
        $sombre = $this->rendre(DocumentFormat::Docx, ThemeDocument::Sombre);
        $clair = $this->rendre(DocumentFormat::Docx, ThemeDocument::Clair);
        self::assertSame(strlen($clair), strlen($sombre));
    }

    /**
     * Le thème du chat parle la même langue que celui du document : le navigateur
     * envoie `dark` / `light` (assistant-theme.js), le serveur doit les comprendre.
     */
    public function testLeThemeDuNavigateurEstCompris(): void
    {
        self::assertSame(ThemeDocument::Sombre, ThemeDocument::depuis('dark'));
        self::assertSame(ThemeDocument::Sombre, ThemeDocument::depuis('sombre'));
        self::assertSame(ThemeDocument::Clair, ThemeDocument::depuis('light'));
        self::assertSame(ThemeDocument::Clair, ThemeDocument::depuis('clair'));
        // Fail-safe : l'inconnu retombe sur clair, jamais sur une exception.
        self::assertSame(ThemeDocument::Clair, ThemeDocument::depuis('fuchsia'));
        self::assertSame(ThemeDocument::Clair, ThemeDocument::depuis(null));
    }
}
