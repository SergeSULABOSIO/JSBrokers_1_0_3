<?php

namespace App\Tests\Workspace;

use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * L'URL D'UNE COLLECTION MÈNE QUELQUE PART.
 *
 * ── LA PANNE QUE CE TEST EMPÊCHE ────────────────────────────────────────────────────
 * L'URL de liste d'un widget de collection se DÉDUIT du nom du champ qui porte le parent :
 * un champ `piste` donne /admin/piste/api/…. La déduction tombe dès que ce champ ne peut
 * pas porter le nom de l'entité — `parametres` pour `ParametresConge`, `inviteRattache`
 * pour `Invite` — et le widget appelle alors une route qui n'existe pas. Le formulaire
 * s'ouvre normalement ; seule la collection affiche « Impossible de charger la liste ».
 * Rien dans les journaux du serveur ne le signale : c'est un 404 sur une URL jamais
 * déclarée.
 *
 * L'échappatoire existe (`parentRouteName`) mais elle est facile à oublier, et l'oubli ne
 * se voit qu'en ouvrant le dialogue concerné. D'où ce contrat : chaque `listUrl` produite
 * par un canevas de formulaire doit correspondre à une route réelle.
 *
 * ── POURQUOI IL BALAIE TOUT ─────────────────────────────────────────────────────────
 * Vérifier le seul dialogue qu'on vient d'écrire laisserait les quarante autres exposés
 * au même oubli. Le coût est le même ; la couverture, non.
 */
class CollectionListUrlContratTest extends KernelTestCase
{
    /**
     * Les URL de collection produites par tous les canevas de formulaire pointent une
     * route existante.
     */
    public function testChaqueUrlDeCollectionCorrespondAUneRouteReelle(): void
    {
        static::bootKernel();
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $verifiees = 0;
        $manquantes = [];
        $inspectes = [];

        foreach ($this->providers() as $provider) {
            $entite = $this->entiteDe($provider);
            if ($entite === null) {
                continue;
            }

            try {
                $canvas = $provider->getCanvas($entite, null);
            } catch (\Throwable) {
                // Un canevas qui exige un contexte (parent posé, entreprise connectée) ne
                // se rend pas à froid. On ne le compte pas comme une réussite : la
                // couverture réellement atteinte est affirmée plus bas.
                continue;
            }

            $inspectes[] = $provider::class;

            foreach ($this->collectionsDe($canvas) as $champ) {
                $url = (string) ($champ['options']['listUrl'] ?? '');
                if ($url === '') {
                    continue;
                }

                $verifiees++;
                $racine = $this->racineDe($url);

                if ($routes->get('admin.' . $racine . '.api.get_collection') === null) {
                    $manquantes[] = sprintf(
                        '%s → %s (route admin.%s.api.get_collection absente)',
                        $provider::class,
                        $url,
                        $racine,
                    );
                }
            }
        }

        self::assertGreaterThan(
            20,
            $verifiees,
            'Le balayage doit voir un nombre significatif de collections, sinon il ne prouve rien.',
        );

        self::assertSame(
            [],
            $manquantes,
            "Ces collections appellent une route inexistante. Le remède est `parentRouteName`\n"
            . "dans la configuration de la collection, qui remplace le nom de champ déduit par\n"
            . "le nom de route réel de l'entité parente :\n  - " . implode("\n  - ", $manquantes),
        );
    }

    /** @return iterable<FormCanvasProviderInterface> */
    private function providers(): iterable
    {
        foreach (glob(__DIR__ . '/../../src/Services/Canvas/Provider/Form/*FormCanvasProvider.php') ?: [] as $fichier) {
            $fqcn = 'App\\Services\\Canvas\\Provider\\Form\\' . basename($fichier, '.php');
            if (!class_exists($fqcn)) {
                continue;
            }

            try {
                $service = static::getContainer()->get($fqcn);
            } catch (\Throwable) {
                continue; // Service non exposé au conteneur de test.
            }

            if ($service instanceof FormCanvasProviderInterface) {
                yield $service;
            }
        }
    }

    /**
     * L'entité que ce provider sert, devinée de son nom puis confirmée par `supports()`.
     *
     * On l'instancie SANS constructeur : certaines entités en exigent des arguments, et ce
     * test ne s'intéresse qu'à la forme du canevas, jamais à l'état de l'objet.
     */
    private function entiteDe(FormCanvasProviderInterface $provider): ?object
    {
        $court = str_replace('FormCanvasProvider', '', (new \ReflectionClass($provider))->getShortName());
        $fqcn = 'App\\Entity\\' . $court;

        if (!class_exists($fqcn) || !$provider->supports($fqcn)) {
            return null;
        }

        try {
            return (new \ReflectionClass($fqcn))->newInstanceWithoutConstructor();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Les champs de widget « collection » d'un canevas, à travers rangées et colonnes.
     *
     * @return iterable<array<string, mixed>>
     */
    private function collectionsDe(array $canvas): iterable
    {
        foreach ($canvas['form_layout'] ?? [] as $rangee) {
            foreach ($rangee['colonnes'] ?? [] as $colonne) {
                foreach ($colonne['champs'] ?? [] as $champ) {
                    if (is_array($champ) && ($champ['widget'] ?? null) === 'collection') {
                        yield $champ;
                    }
                }
            }
        }
    }

    /** '/admin/parametresconge/api/1/periodesBlocage' → 'parametresconge'. */
    private function racineDe(string $url): string
    {
        $segments = array_values(array_filter(explode('/', $url)));

        return $segments[1] ?? '';
    }
}
