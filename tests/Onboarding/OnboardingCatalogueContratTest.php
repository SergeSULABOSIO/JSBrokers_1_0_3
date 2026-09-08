<?php

namespace App\Tests\Onboarding;

use App\Entity\Assureur;
use App\Entity\Chargement;
use App\Entity\Groupe;
use App\Entity\Monnaie;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\TypeAbsence;
use App\Entity\TypeRevenu;
use App\Service\Onboarding\OnboardingCatalogue;
use App\Services\CanvasBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * LE CONTRAT DU CATALOGUE DE DÉMARRAGE.
 *
 * Une carte du guide promet deux choses : ouvrir un dialogue, et lister l'existant. Les
 * deux reposent sur des canevas déclarés ailleurs, dans des fichiers que personne ne
 * pense à rouvrir en ajoutant une étape. Ce test est le garde-fou : il attrape la carte
 * qui n'ouvrirait rien et celle qui listerait des lignes vides, AVANT l'utilisateur.
 */
class OnboardingCatalogueContratTest extends KernelTestCase
{
    private OnboardingCatalogue $catalogue;
    private CanvasBuilder $canvasBuilder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalogue = static::getContainer()->get(OnboardingCatalogue::class);
        $this->canvasBuilder = static::getContainer()->get(CanvasBuilder::class);
    }

    public function testChaqueEtapeOuvreUnVraiDialogue(): void
    {
        foreach ($this->catalogue->etapes() as $etape) {
            $classe = $etape['entite'];
            $this->assertTrue(class_exists($classe), sprintf("L'entité de l'étape « %s » n'existe pas.", $etape['cle']));

            $canvas = $this->canvasBuilder->getEntityFormCanvas(new $classe(), null);

            $this->assertArrayHasKey(
                'endpoint_form_url',
                $canvas['parametres'] ?? [],
                sprintf("L'étape « %s » n'a pas d'URL de formulaire : sa carte n'ouvrirait rien.", $etape['cle']),
            );
            $this->assertArrayHasKey(
                'endpoint_submit_url',
                $canvas['parametres'] ?? [],
                sprintf("L'étape « %s » n'a pas d'URL de soumission : le dialogue s'ouvrirait sans pouvoir enregistrer.", $etape['cle']),
            );
        }
    }

    public function testChaqueEtapeSaitSeListerCommeSaRubrique(): void
    {
        foreach ($this->catalogue->etapes() as $etape) {
            $canvas = $this->canvasBuilder->getListeCanvas($etape['entite']);

            $this->assertNotEmpty(
                $canvas['colonne_principale']['texte_principal']['attribut_code'] ?? null,
                sprintf(
                    "L'étape « %s » n'expose aucun attribut d'affichage : ses lignes n'auraient pas de libellé.",
                    $etape['cle'],
                ),
            );
        }
    }

    /**
     * Le « pourquoi » d'une carte n'est pas écrit dans le catalogue : c'est le premier
     * paragraphe de la description que le dialogue affiche dans sa colonne gauche. Un
     * seul texte, deux surfaces — encore faut-il qu'il existe.
     */
    public function testChaqueEtapeExpliquePourquoiElleCompte(): void
    {
        foreach ($this->catalogue->etapes() as $etape) {
            $classe = $etape['entite'];
            $description = $this->canvasBuilder->getEntityFormCanvas(new $classe(), null)['parametres']['description_creation'] ?? null;

            $this->assertIsArray($description, sprintf("L'étape « %s » n'a pas de description_creation.", $etape['cle']));
            $this->assertNotEmpty($description, sprintf("La description de « %s » est vide.", $etape['cle']));
            $this->assertLessThanOrEqual(
                4,
                count($description),
                sprintf("La description de « %s » dépasse 4 paragraphes.", $etape['cle']),
            );

            foreach ($description as $paragraphe) {
                $this->assertNotSame('', trim((string) $paragraphe), sprintf("Un paragraphe vide dans « %s ».", $etape['cle']));
            }
        }
    }

    /**
     * Un paramètre semé d'office est DÉJÀ posé à la création du cabinet : le redemander
     * ferait refaire un travail fait, et le score n'avancerait jamais pour une bonne
     * raison. Seule exception admise : la monnaie, dont le semis laisse un taux de change
     * provisoire — c'est-à-dire faux.
     */
    public function testAucuneEtapeNeRedemandeUnCatalogueDejaSeme(): void
    {
        $semes = [
            Taxe::class, Chargement::class, TypeRevenu::class,
            Risque::class, Groupe::class, TypeAbsence::class,
        ];

        foreach ($this->catalogue->etapes() as $etape) {
            $this->assertNotContains(
                $etape['entite'],
                $semes,
                sprintf("L'étape « %s » redemande un catalogue déjà semé à la création.", $etape['cle']),
            );
        }

        // La monnaie n'est là QUE pour son taux de change, jamais pour en créer une.
        $monnaie = array_values(array_filter(
            $this->catalogue->etapes(),
            static fn (array $e): bool => $e['entite'] === Monnaie::class,
        ));
        $this->assertCount(1, $monnaie);
        $this->assertTrue($monnaie[0]['singleton'], 'Le taux de change est un réglage unique, pas une accumulation.');
    }

    /**
     * Une carte annonce « Rubrique Production » pour dire où retrouver plus tard ce
     * qu'on vient de créer. Si ce nom ne correspond à aucun groupe du menu, la promesse
     * est fausse et personne ne s'en aperçoit.
     */
    public function testChaqueBlocDesigneUnVraiGroupeDuMenu(): void
    {
        $menu = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/menu.yaml');
        $groupes = array_keys($menu['parameters']['app.menu_data']['colonne_1']['groupes']);

        foreach ($this->catalogue->etapes() as $etape) {
            $this->assertContains(
                $etape['bloc'],
                $groupes,
                sprintf("Le bloc « %s » de l'étape « %s » n'est pas un groupe du menu.", $etape['bloc'], $etape['cle']),
            );
        }
    }

    public function testLesClesSontUniquesEtLesPoidsConnus(): void
    {
        $cles = array_column($this->catalogue->etapes(), 'cle');
        $this->assertSame(count($cles), count(array_unique($cles)), 'Deux étapes portent la même clé.');

        foreach ($this->catalogue->etapes() as $etape) {
            $this->assertContains($etape['poids'], [
                OnboardingCatalogue::POIDS_BLOQUANT,
                OnboardingCatalogue::POIDS_STRUCTURANT,
                OnboardingCatalogue::POIDS_CONFORT,
            ], sprintf("Poids inconnu sur « %s ».", $etape['cle']));
        }

        $this->assertGreaterThan(0, $this->catalogue->poidsTotal());
        // Le guide doit valoir le détour : une poignée d'étapes ne serait pas un parcours.
        $this->assertGreaterThanOrEqual(10, count($cles));
    }

    public function testEtapeRendLEntreeDemandeeEtRienDAutre(): void
    {
        $this->assertSame('assureurs', $this->catalogue->etape('assureurs')['cle']);
        $this->assertSame(Assureur::class, $this->catalogue->etape('assureurs')['entite']);
        // Une clé venue du DOM ne doit jamais être crue sur parole.
        $this->assertNull($this->catalogue->etape('nimporte-quoi'));
    }
}
