<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Routage\CatalogueCondense;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le POIDS des trousses, mesuré — parce que c'est la seule chose que ce chantier
 * promet, et qu'une promesse de performance non mesurée n'est qu'une intention.
 *
 * Référence d'avant (campagne du 2026-08-08/09, 96 tours) : 53 114 octets de prompt
 * système et 71 372 de déclarations à CHAQUE tour, soit 94 % du payload, pour
 * 7 outils réellement appelés sur 33.
 *
 * Ce test échoue si la trousse de lecture cesse d'être substantiellement plus légère
 * que la trousse complète : ce serait le signe qu'une règle d'écriture est revenue
 * dans le socle, ou qu'un outil d'écriture a perdu son marqueur.
 */
class TroussePoidsTest extends KernelTestCase
{
    /** La lecture doit rester nettement sous la trousse complète. Marge : 30 %. */
    private const GAIN_MINIMAL = 0.30;

    /** @return array{prompt: int, outils: int} octets */
    private function poids(Trousse $trousse): array
    {
        $conteneur = static::getContainer();
        $entreprise = $conteneur->get(EntrepriseRepository::class)->findOneBy([]);
        $invite = $conteneur->get(InviteRepository::class)->findOneBy(['entreprise' => $entreprise]);
        $scope = new AiScope($entreprise, $invite);

        $builder = $conteneur->get(AiContextBuilder::class);
        $requete = $builder->build($entreprise, $invite, new AssistantConversation());

        $declarations = [];
        foreach ($conteneur->get(TrousseCatalogue::class)->outilsDe($trousse, $scope) as $outil) {
            $declarations[] = [
                'name'        => $outil->name(),
                'description' => $outil->description(),
                'parameters'  => $outil->schema(),
            ];
        }

        return [
            'prompt' => strlen($builder->toSystemPrompt($requete, $trousse)),
            'outils' => strlen((string) json_encode($declarations, JSON_UNESCAPED_UNICODE)),
        ];
    }

    public function testLaTrousseDeLectureAllegeSubstantiellementLePayload(): void
    {
        static::bootKernel();

        $lecture = $this->poids(Trousse::LECTURE);
        $ecriture = $this->poids(Trousse::ECRITURE);

        $totalLecture = $lecture['prompt'] + $lecture['outils'];
        $totalEcriture = $ecriture['prompt'] + $ecriture['outils'];
        $gain = 1 - ($totalLecture / $totalEcriture);

        // Trace lisible : ces chiffres sont la raison d'être du chantier.
        fwrite(STDERR, sprintf(
            "\n  trousse LECTURE  : prompt %6d o + outils %6d o = %6d o\n"
            . "  trousse ÉCRITURE : prompt %6d o + outils %6d o = %6d o\n"
            . "  gain sur un tour de lecture : %.0f %%\n",
            $lecture['prompt'], $lecture['outils'], $totalLecture,
            $ecriture['prompt'], $ecriture['outils'], $totalEcriture,
            $gain * 100,
        ));

        self::assertGreaterThan(
            self::GAIN_MINIMAL,
            $gain,
            sprintf('La trousse de lecture ne fait gagner que %.0f %% : le socle a dû se réalourdir.', $gain * 100),
        );
    }

    /**
     * Le catalogue soumis à l'aiguilleur doit rester d'un ordre de grandeur en
     * dessous des déclarations complètes — sinon router coûterait ce qu'il économise.
     */
    public function testLeCatalogueCondenseResteMarginal(): void
    {
        static::bootKernel();

        $condense = strlen(static::getContainer()->get(CatalogueCondense::class)->texte());
        $complet = $this->poids(Trousse::ECRITURE)['outils'];

        fwrite(STDERR, sprintf("  catalogue condensé : %d o (contre %d o de déclarations)\n", $condense, $complet));

        self::assertLessThan($complet / 5, $condense);
    }
}
