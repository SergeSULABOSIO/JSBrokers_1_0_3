<?php

namespace App\Tests\Workspace;

use App\Services\CanvasBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TOUT DIALOGUE DE CRÉATION EXPLIQUE CE QU'IL CRÉE.
 *
 * ── LE MANQUE QUE CELA COMBLE ───────────────────────────────────────────────────────
 * La colonne gauche du dialogue était invisible en création : sur une entité neuve, les
 * attributs calculés valent tous zéro. Un tiers de la largeur perdu au moment précis où
 * l'utilisateur a le plus besoin d'être guidé — il crée un objet dont il ignore
 * peut-être le rôle.
 *
 * ── POURQUOI CE TEST N'A AUCUNE LISTE D'EXCEPTIONS ──────────────────────────────────
 * Une liste d'entités dispensées serait une liste que personne ne relit : on y ajoute
 * un nom le jour où l'on est pressé, et la règle meurt sans bruit. La règle est donc
 * sans exception — tout provider de formulaire en déclare une —, ce qui la rend
 * vérifiable d'un seul coup d'œil et impossible à contourner par distraction.
 *
 * Le test parcourt le RÉPERTOIRE des providers. Il le peut sans risque : `_instanceof`
 * les tague automatiquement, si bien qu'un fichier déposé là EST un provider enregistré.
 * Ajouter un dialogue sans se faire attraper demanderait de le poser ailleurs.
 */
class DialogueDescriptionCreationTest extends KernelTestCase
{
    /** Au-delà, ce n'est plus une mise en contexte : c'est une notice que personne ne lit. */
    private const PARAGRAPHES_MAX = 4;

    /** En deçà, la phrase n'explique rien — elle nomme. */
    private const PREMIER_PARAGRAPHE_MIN = 60;

    private CanvasBuilder $canvasBuilder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->canvasBuilder = static::getContainer()->get(CanvasBuilder::class);
    }

    /**
     * @return array<int, array{0: string, 1: array<string, mixed>}> [entité, canevas]
     */
    private function canevas(): array
    {
        $canevas = [];

        $repertoire = \dirname(__DIR__, 2) . '/src/Services/Canvas/Provider/Form';

        foreach (glob($repertoire . '/*FormCanvasProvider.php') as $fichier) {
            $entite = str_replace('FormCanvasProvider.php', '', basename($fichier));
            $classe = 'App\\Entity\\' . $entite;

            if (!class_exists($classe)) {
                continue;
            }

            try {
                $canevas[] = [$entite, $this->canvasBuilder->getEntityFormCanvas(new $classe(), null)];
            } catch (\Throwable $e) {
                $this->fail(sprintf('Le canevas de « %s » ne se construit pas : %s', $entite, $e->getMessage()));
            }
        }

        return $canevas;
    }

    public function testLaDecouverteTrouveBienTousLesDialogues(): void
    {
        // Si la découverte tombe à zéro, tout le reste passerait à vide sans rien dire.
        $this->assertGreaterThanOrEqual(40, count($this->canevas()));
    }

    public function testChaqueDialogueDeclareUneDescriptionDeCreation(): void
    {
        foreach ($this->canevas() as [$entite, $canvas]) {
            $this->assertIsArray(
                $canvas['parametres']['description_creation'] ?? null,
                sprintf(
                    'Le dialogue de « %s » n\'explique pas ce qu\'il crée. Ajoutez '
                    . '`description_creation` à son FormCanvasProvider — la règle est sans exception.',
                    $entite,
                ),
            );
        }
    }

    public function testChaqueDescriptionTientEnQuatreParagraphesPleins(): void
    {
        foreach ($this->canevas() as [$entite, $canvas]) {
            $description = $canvas['parametres']['description_creation'] ?? [];

            $this->assertNotEmpty($description, sprintf('La description de « %s » est vide.', $entite));
            $this->assertLessThanOrEqual(
                self::PARAGRAPHES_MAX,
                count($description),
                sprintf('La description de « %s » dépasse %d paragraphes.', $entite, self::PARAGRAPHES_MAX),
            );

            foreach ($description as $rang => $paragraphe) {
                $this->assertIsString($paragraphe, sprintf('« %s » : le paragraphe %d n\'est pas du texte.', $entite, $rang));
                $this->assertNotSame('', trim($paragraphe), sprintf('« %s » : paragraphe %d vide.', $entite, $rang));
                // La colonne accueille du TEXTE : elle rendrait les balises telles quelles.
                $this->assertSame(
                    $paragraphe,
                    strip_tags($paragraphe),
                    sprintf('« %s » : le paragraphe %d contient du HTML.', $entite, $rang),
                );
            }
        }
    }

    /**
     * Le premier paragraphe est celui qui doit se suffire à lui-même : c'est lui que le
     * guide de démarrage reprend sur ses cartes, hors de tout contexte de dialogue.
     */
    public function testLePremierParagrapheSeSuffitALuiMeme(): void
    {
        foreach ($this->canevas() as [$entite, $canvas]) {
            $premier = trim($canvas['parametres']['description_creation'][0] ?? '');

            $this->assertGreaterThanOrEqual(
                self::PREMIER_PARAGRAPHE_MIN,
                mb_strlen($premier),
                sprintf(
                    'Le premier paragraphe de « %s » est trop court pour expliquer quoi que ce soit : « %s »',
                    $entite,
                    $premier,
                ),
            );
        }
    }
}
