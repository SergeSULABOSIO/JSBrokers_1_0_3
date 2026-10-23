<?php

namespace App\Tests\Ai;

use App\Ai\Fichier\ConversationFichierResolver;
use App\Ai\Scope\AiScope;
use App\Entity\AssistantConversation;
use App\Entity\AssistantConversationFichier;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Fichier\TemporairesDuCabinet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * LA COPIE REMISE À L'EXÉCUTION EST UN FICHIER DE PASSAGE DU CABINET (Serge, 2026-10-23).
 *
 * À l'exécution d'un plan de Ket, le résolveur remet une COPIE de la pièce jointe (Vich la
 * déplacera au flush, l'original reste à la conversation). Si l'enregistrement échouait, la copie
 * — le fichier d'un client — restait dans le dossier temporaire du système, hors de portée de la
 * suppression du cabinet. Elle naît désormais dans le dossier de passage DU CABINET, et ce que
 * Vich n'a pas emporté est effacé à la fin du travail (fin de requête, ou entre deux messages
 * du worker).
 */
class ConversationFichierResolverCopieTest extends TestCase
{
    private const ID_CABINET = 990000321;

    private string $original;
    private string $dossierDePassage;

    protected function setUp(): void
    {
        $this->original = (string) tempnam(sys_get_temp_dir(), 'phpunit_cfr_');
        file_put_contents($this->original, 'contrat du client');
        $this->dossierDePassage = sys_get_temp_dir() . '/phpunit_passages_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->original);
        (new Filesystem())->remove($this->dossierDePassage);
    }

    public function testLaCopieNaitDansLeDossierDePassageDuCabinet(): void
    {
        // Le service vit le temps du travail (dans l'application : le conteneur, toute la requête).
        $temporaires = new TemporairesDuCabinet($this->dossierDePassage);
        $copie = $this->resolveur($temporaires)->resoudre('@fichier:7', $this->scope(), true)->upload->getPathname();

        self::assertNotSame($this->original, $copie, 'À l\'exécution, une COPIE est remise.');
        self::assertSame(
            str_replace('\\', '/', $this->dossierDePassage . '/' . self::ID_CABINET),
            str_replace('\\', '/', \dirname($copie)),
            'La copie est rangée chez le cabinet : elle part avec lui.',
        );
        self::assertFileExists($copie);
    }

    public function testUneCopieQueVichNAPasEmporteeEstEffaceeALaFinDuTravail(): void
    {
        $temporaires = new TemporairesDuCabinet($this->dossierDePassage);
        $copie = $this->resolveur($temporaires)->resoudre('@fichier:7', $this->scope(), true)->upload->getPathname();

        self::assertInstanceOf(ResetInterface::class, $temporaires, 'Le worker de Ket remet les services à zéro entre deux messages.');
        $temporaires->reset();

        self::assertFileDoesNotExist($copie, 'L\'enregistrement a échoué : la copie ne reste pas sur le serveur.');
        self::assertFileExists($this->original, 'L\'original de la conversation n\'est jamais touché.');
    }

    public function testUneCopieEmporteeParVichNEstPasPoursuivie(): void
    {
        $temporaires = new TemporairesDuCabinet($this->dossierDePassage);
        $copie = $this->resolveur($temporaires)->resoudre('@fichier:7', $this->scope(), true)->upload->getPathname();

        // Vich a déplacé le binaire à sa place définitive.
        $range = $copie . '.range';
        rename($copie, $range);
        $temporaires->reset();

        self::assertFileExists($range, 'Le fichier rangé par Vich n\'est pas effacé.');
    }

    public function testEnFinDeRequeteAussi(): void
    {
        $temporaires = new TemporairesDuCabinet($this->dossierDePassage);
        $copie = $this->resolveur($temporaires)->resoudre('@fichier:7', $this->scope(), true)->upload->getPathname();

        unset($temporaires); // fin de requête : les services sont détruits
        gc_collect_cycles();

        self::assertFileDoesNotExist($copie);
    }

    public function testLeDryRunNeCopieRien(): void
    {
        $piece = $this->resolveur()->resoudre('@fichier:7', $this->scope(), false);

        self::assertSame($this->original, $piece->upload->getPathname());
        self::assertDirectoryDoesNotExist($this->dossierDePassage);
    }

    public function testUnFichierDePassageAppartientAUnCabinetEnregistre(): void
    {
        $this->expectException(\LogicException::class);
        (new TemporairesDuCabinet($this->dossierDePassage))->nouveau(new Entreprise(), 'pdf');
    }

    private function resolveur(?TemporairesDuCabinet $temporaires = null): ConversationFichierResolver
    {
        $stockage = $this->createMock(StorageInterface::class);
        $stockage->method('resolvePath')->willReturn($this->original);

        return new ConversationFichierResolver($stockage, $temporaires ?? new TemporairesDuCabinet($this->dossierDePassage));
    }

    private function scope(): AiScope
    {
        $fichier = (new AssistantConversationFichier())->setNomOriginal('contrat.pdf')->setNomFichierStocke('x.pdf')->setMimeType('application/pdf')->setTaille(17);
        (new \ReflectionProperty(AssistantConversationFichier::class, 'id'))->setValue($fichier, 7);
        $conversation = new AssistantConversation();
        $conversation->addFichier($fichier);
        $entreprise = new Entreprise();
        (new \ReflectionProperty(Entreprise::class, 'id'))->setValue($entreprise, self::ID_CABINET);

        return new AiScope($entreprise, new Invite(), $conversation);
    }
}
