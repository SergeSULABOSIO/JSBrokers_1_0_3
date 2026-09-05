<?php

namespace App\Tests\Echange;

use App\Ai\Mutation\MutationAllowlist;
use App\Echange\Canevas\CanevasDEchange;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE MODULE D'UNE DONNÉE EST CELUI DE LA CARTE D'ACCÈS — jamais une seconde liste.
 *
 * C'est ce qui permet de cocher « Production » d'un geste plutôt que ses dix rubriques
 * une à une. Le regroupement n'est pas déclaré quelque part : il est LU dans la carte
 * des permissions, qui porte déjà le module en tête de chaque entrée. Deux sources
 * finiraient par diverger, et l'utilisateur cocherait un groupe qui ne contient pas ce
 * qu'il croit.
 */
class ModuleDesRessourcesTest extends KernelTestCase
{
    private function canevas(): CanevasDEchange
    {
        self::bootKernel();

        return static::getContainer()->get(CanevasDEchange::class);
    }

    private function resolver(): WorkspaceAccessResolver
    {
        return static::getContainer()->get(WorkspaceAccessResolver::class);
    }

    /**
     * ⚠ AUCUNE DONNÉE NE DOIT RESTER SANS GROUPE.
     *
     * Une donnée sans module tomberait dans un « Autres » que personne ne penserait à
     * cocher : elle serait exclue de tous les imports groupés, en silence. C'est la
     * panne la plus discrète que ce regroupement puisse produire.
     */
    public function testChaqueDonneeEchangeableAUnModule(): void
    {
        $sansModule = [];
        foreach ($this->canevas()->toutes() as $code => $ressource) {
            if ($ressource->module === '' || $ressource->module === 'Autres') {
                $sansModule[] = $code;
            }
        }

        self::assertSame([], $sansModule, 'Ces données ne se rattachent à aucun module.');
    }

    /** Le module rendu est bien celui que la carte d'accès déclare. */
    public function testLeModuleEstCeluiDeLaCarteDAcces(): void
    {
        $attendus = $this->resolver()->modulesEntites();

        foreach ($this->canevas()->toutes() as $code => $ressource) {
            self::assertSame(
                $attendus[$code] ?? null,
                $ressource->module,
                sprintf('Le module de « %s » diverge de la carte d\'accès.', $code),
            );
        }
    }

    /**
     * LES SOUS-ENTITÉS HÉRITENT DE LEUR PARENT.
     *
     * Un paiement de prime appartient aux Finances aussi sûrement que la tranche dont il
     * dépend ; un mouvement de congé à l'Administration comme la demande qui l'a produit.
     * Ces cinq-là n'ont pas d'entrée dans la carte — les laisser sans rattachement les
     * rendrait invisibles à tout regroupement.
     */
    public function testLesSousEntitesHeritentDuModuleDeLeurParent(): void
    {
        $modules = $this->resolver()->modulesEntites();

        $attendu = [
            'PaiementPrime'     => 'Finances',       // suit Tranche
            'MouvementConge'    => 'Administration', // suit DemandeConge
            'HistoriqueDemande' => 'Administration', // suit DemandeConge
            'PeriodeBlocage'    => 'Administration', // suit ParametresConge
            // Le régime de travail suit le droit « Invité », qui relève de la gestion des
            // rôles et n'a donc pas de module : il se règle depuis la fiche du
            // collaborateur, comme les congés — c'est de l'administration.
            'RegimeTravail'     => 'Administration',
        ];

        foreach ($attendu as $code => $module) {
            self::assertSame($module, $modules[$code] ?? null, sprintf('Module de « %s ».', $code));
        }
    }

    /**
     * Les modules couvrent le périmètre d'échange sans en inventer : on regroupe les
     * données existantes, on n'ouvre pas une nomenclature parallèle.
     */
    public function testLesModulesSontCeuxDeLApplication(): void
    {
        $connus = ['Finances', 'Marketing', 'Production', 'Sinistre', 'Administration', 'IA'];

        foreach ($this->canevas()->toutes() as $code => $ressource) {
            self::assertContains(
                $ressource->module,
                $connus,
                sprintf('« %s » se rattache à un module inconnu de l\'application.', $code),
            );
        }
    }

    /** Le regroupement doit être utile : plusieurs groupes, chacun non vide. */
    public function testLeRegroupementEstExploitable(): void
    {
        $parModule = [];
        foreach ($this->canevas()->toutes() as $ressource) {
            $parModule[$ressource->module][] = $ressource->code;
        }

        self::assertGreaterThanOrEqual(3, count($parModule), 'Un regroupement d\'un seul groupe ne regroupe rien.');
        self::assertCount(count(MutationAllowlist::MEMBRES), array_merge(...array_values($parModule)));

        foreach ($parModule as $module => $codes) {
            self::assertNotEmpty($codes, sprintf('Le module « %s » est vide.', $module));
        }
    }
}
