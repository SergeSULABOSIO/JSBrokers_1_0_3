<?php

namespace App\Tests\Frontend;

use App\Services\Canvas\EntityCanvasProvider;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * UNE RUBRIQUE, UNE MASCOTTE (jsb-construire-rubrique §6 ; chantier 8, cas tranchés par Serge
 * le 2026-10-26).
 *
 * La mascotte représente la rubrique partout : menu, onglet, ligne, dialogue, état vide. Deux
 * rubriques au même dessin se confondent, et un dessin d'ACTION (« réglages », « rôle »,
 * « résiliation ») fait croire à un bouton. Trois règles :
 *  (a) aucune mascotte n'est partagée — sauf exception motivée, ci-dessous ;
 *  (b) aucune mascotte n'est un alias `action:*` / `fichier:*`, ni ne dessine comme l'un d'eux
 *      (on compare le SVG RÉSOLU : `invite` et `action:send-email` dessinaient la même enveloppe) ;
 *  (c) le menu et le canevas d'entité déclarent la même mascotte.
 */
class MascottesUniquesTest extends KernelTestCase
{
    /**
     * Les exceptions motivées : des rubriques qui forment UNE famille et partagent sa mascotte.
     * Les cinq rôles sont les cinq onglets d'un même réglage, les droits d'un invité.
     */
    private const FAMILLES = [
        'Rôles' => ['RolesEnAdministration', 'RolesEnFinance', 'RolesEnMarketing', 'RolesEnProduction', 'RolesEnSinistre'],
    ];

    /**
     * Les exceptions motivées à la règle (b). Le pliage de l'arbre de suppression dessine
     * délibérément un dossier fermé / ouvert (« ce qu'il y a dedans est caché ») : il porte
     * toujours son libellé (« Replié ») et ne vit que dans le dialogue de suppression (Serge,
     * 2026-10-26).
     */
    private const DESSINS_D_ACTION_TOLERES = [
        'Classeur' => 'action:replier',
        // L'échange produit justement un classeur Excel : le dessin dit ce qu'on obtient ; l'icône
        // de type de fichier n'apparaît qu'à côté d'un nom de fichier (Serge, 2026-10-26).
        'Echange' => 'fichier:excel',
    ];

    /** @return array<string, string> alias => nom d'icône */
    private static function alias(): array
    {
        return (new \ReflectionClass(IconCanvasProvider::class))->getReflectionConstant('ICON_ALIAS_MAP')->getValue();
    }

    private static function resoudre(string $icone): string
    {
        return self::alias()[$icone] ?? $icone;
    }

    /** @return array<string, string> rubrique (nom court) => mascotte déclarée par son canevas */
    private function mascottesDesCanevas(): array
    {
        self::bootKernel();
        $canevas = static::getContainer()->get(EntityCanvasProvider::class);
        $mascottes = [];
        $noms = array_map(static fn (string $f) => basename($f, '.php'), glob(__DIR__ . '/../../src/Entity/*.php'));
        foreach ([...$noms, 'ProductionIntermediaire'] as $nom) {
            $classe = class_exists('App\\Entity\\' . $nom) ? 'App\\Entity\\' . $nom : $nom;
            try {
                $icone = $canevas->getCanvas($classe)['parametres']['icone'] ?? null;
            } catch (\Throwable) {
                $icone = null;
            }
            if (\is_string($icone) && $icone !== '') {
                $mascottes[$nom] = $icone;
            }
        }

        return $mascottes;
    }

    /** @return array<string, string> rubrique => mascotte : canevas, puis rubriques du menu sans canevas */
    private function mascottesDeToutesLesRubriques(): array
    {
        $mascottes = $this->mascottesDesCanevas();
        foreach (self::entreesDuMenu() as $nom => $icone) {
            $mascottes[$nom] ??= $icone;
        }

        return $mascottes;
    }

    /** @return array<string, string> nom court de la rubrique => icône du menu */
    private static function entreesDuMenu(): array
    {
        $entrees = [];
        $parcourir = function (mixed $noeud) use (&$parcourir, &$entrees): void {
            if (!\is_array($noeud)) {
                return;
            }
            if (isset($noeud['entity_name'], $noeud['icone'])) {
                $entrees[str_replace('App\\Entity\\', '', (string) $noeud['entity_name'])] = (string) $noeud['icone'];

                return;
            }
            foreach ($noeud as $enfant) {
                $parcourir($enfant);
            }
        };
        $parcourir(Yaml::parseFile(__DIR__ . '/../../config/packages/menu.yaml'));

        return $entrees;
    }

    public function testAucuneMascotteNEstPartageeHorsDUneFamille(): void
    {
        $famille = [];
        foreach (self::FAMILLES as $libelle => $membres) {
            foreach ($membres as $membre) {
                $famille[$membre] = $libelle;
            }
        }

        $parDessin = [];
        foreach ($this->mascottesDeToutesLesRubriques() as $rubrique => $icone) {
            $parDessin[self::resoudre($icone)][$famille[$rubrique] ?? $rubrique] = true;
        }
        $partages = array_filter($parDessin, static fn (array $porteurs) => \count($porteurs) > 1);

        self::assertSame([], array_map('array_keys', $partages), 'Deux rubriques ne partagent pas une mascotte.');
    }

    public function testAucuneMascotteNEstUnDessinDAction(): void
    {
        $dessinsDAction = [];
        foreach (self::alias() as $alias => $icone) {
            if (str_starts_with($alias, 'action:') || str_starts_with($alias, 'fichier:')) {
                $dessinsDAction[$icone] = $alias;
            }
        }

        $fautives = [];
        foreach ($this->mascottesDeToutesLesRubriques() as $rubrique => $icone) {
            if (str_starts_with($icone, 'action:') || str_starts_with($icone, 'fichier:')) {
                $fautives[$rubrique] = $icone;
            } elseif (isset($dessinsDAction[self::resoudre($icone)])
                && (self::DESSINS_D_ACTION_TOLERES[$rubrique] ?? null) !== $dessinsDAction[self::resoudre($icone)]) {
                $fautives[$rubrique] = $icone . ' = ' . $dessinsDAction[self::resoudre($icone)];
            }
        }

        self::assertSame([], $fautives, 'Une mascotte ne dessine pas une action.');
    }

    /**
     * (d) LES AUTRES VITRINES REPRENNENT LA MASCOTTE : la page publique des tokens
     * (PlanTarifaireExtension) et le guide de démarrage (OnboardingCatalogue) nomment des
     * rubriques avec une icône recopiée à la main — l'ancienne y survivait après le chantier 8.
     */
    public function testLesVitrinesReprennentLaMascotteDuCanevas(): void
    {
        $mascottes = $this->mascottesDesCanevas();
        $ecarts = [];

        $tokens = (new \ReflectionClass(\App\Twig\Extension\PlanTarifaireExtension::class))->getReflectionConstant('ENTITES')->getValue();
        foreach ($tokens as $classe => $entree) {
            $nom = substr((string) strrchr('\\' . $classe, '\\'), 1);
            if (isset($mascottes[$nom], $entree['icon']) && $entree['icon'] !== $mascottes[$nom]) {
                $ecarts['tokens ' . $nom] = $entree['icon'] . ' ≠ ' . $mascottes[$nom];
            }
        }
        $guide = (new \ReflectionClass(\App\Service\Onboarding\OnboardingCatalogue::class))->getReflectionConstant('ETAPES')->getValue();
        foreach ($guide as $etape) {
            $nom = isset($etape['entite']) ? substr((string) strrchr('\\' . $etape['entite'], '\\'), 1) : null;
            if ($nom !== null && isset($mascottes[$nom], $etape['icone']) && $etape['icone'] !== $mascottes[$nom]) {
                $ecarts['guide ' . $nom] = $etape['icone'] . ' ≠ ' . $mascottes[$nom];
            }
        }

        self::assertSame([], $ecarts, 'Une vitrine qui nomme une rubrique reprend sa mascotte.');
    }

    /**
     * (f) LA MASCOTTE DES INVITÉS NE DIT PAS « E-MAIL ». Elle a été l'enveloppe ; trois champs et
     * boutons d'e-mail l'empruntaient — devenue un collaborateur, elle y serait un contresens.
     * Un e-mail se dit avec `action:send-email`.
     */
    public function testLaMascotteDesInvitesNeSertPasAUnEmail(): void
    {
        $fautifs = [];
        foreach (['templates/home/index.html.twig', 'templates/console/crm/campagne/index.html.twig'] as $gabarit) {
            if (str_contains((string) file_get_contents(__DIR__ . '/../../' . $gabarit), "resolve_icon_name('invite')")) {
                $fautifs[] = $gabarit;
            }
        }
        $fournisseur = (string) file_get_contents(__DIR__ . '/../../src/Services/Canvas/Provider/Form/FournisseurFormCanvasProvider.php');
        if (preg_match('/"email"\s*=>\s*"invite"/', $fournisseur) === 1) {
            $fautifs[] = 'FournisseurFormCanvasProvider (champ email)';
        }

        self::assertSame([], $fautifs, 'Un e-mail se dit avec action:send-email, pas avec la mascotte des invités.');
    }

    public function testLeMenuEtLeCanevasDeclarentLaMemeMascotte(): void
    {
        $mascottes = $this->mascottesDesCanevas();
        $ecarts = [];
        $parcourir = function (mixed $noeud) use (&$parcourir, &$ecarts, $mascottes): void {
            if (!\is_array($noeud)) {
                return;
            }
            if (isset($noeud['entity_name'], $noeud['icone'])) {
                $nom = str_replace('App\\Entity\\', '', (string) $noeud['entity_name']);
                if (isset($mascottes[$nom]) && $mascottes[$nom] !== $noeud['icone']) {
                    $ecarts[$nom] = 'menu ' . $noeud['icone'] . ' ≠ canevas ' . $mascottes[$nom];
                }

                return;
            }
            foreach ($noeud as $enfant) {
                $parcourir($enfant);
            }
        };
        $parcourir(Yaml::parseFile(__DIR__ . '/../../config/packages/menu.yaml'));

        self::assertSame([], $ecarts, 'Le menu et le canevas déclarent la même mascotte.');
    }
}
