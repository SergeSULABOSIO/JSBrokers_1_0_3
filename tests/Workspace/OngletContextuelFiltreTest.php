<?php

namespace App\Tests\Workspace;

use App\Services\Canvas\LienDeCollection;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN ONGLET CONTEXTUEL NE MONTRE QUE LES ENFANTS DE SON PARENT.
 *
 * ── LE DÉFAUT QUE CE BANC FERME ─────────────────────────────────────────────────────
 * Un onglet contextuel a DEUX chemins de lecture, et un seul était juste. Le premier
 * affichage passe par le getter Doctrine du parent : il ne peut rendre que ses enfants.
 * Mais dès la page 2, une recherche, un « Réinitialiser » ou un rafraîchissement après
 * enregistrement, la liste repart vers la rubrique ENTIÈRE de l'enfant.
 *
 * Elle n'était alors bornée que par un nom de champ DEVINÉ par le navigateur, cherché dans
 * le canevas de FORMULAIRE du parent — alors que les onglets, eux, naissent du canevas
 * d'ENTITÉ. Deux listes indépendantes, que rien ne confrontait. Pour quatre des six onglets
 * d'un client — pistes, sinistres, notes, partenaires — la devinette rendait `null`, et la
 * liste affichait TOUT LE CABINET sous un en-tête et une pastille au nom du client.
 *
 * Le pire cas de figure : l'onglet s'ouvre juste, puis ment au premier geste. Un écran qui
 * affiche un périmètre sans l'appliquer est pire qu'un écran vide — il AFFIRME.
 *
 * ── CE QUE CE TEST VÉRIFIE, ET POURQUOI C'EST CELUI-LÀ ──────────────────────────────
 * Il ne vérifie pas un écran, il vérifie une COUVERTURE : pour CHAQUE onglet contextuel du
 * workspace, Doctrine doit savoir nommer le champ qui relie l'enfant au parent. C'est le
 * seul correctif durable — la liste des onglets et celle des filtres deviennent la même, et
 * le prochain onglet ajouté ne pourra plus naître non filtré en silence.
 *
 * L'exclusivité réelle (la liste de X ne contient PAS l'enfant de Y) est prouvée par
 * {@see OngletContextuelExclusiviteTest}, qui fait tourner la vraie requête.
 */
class OngletContextuelFiltreTest extends KernelTestCase
{
    /**
     * TOUS LES ONGLETS DU WORKSPACE, SANS EXCEPTION.
     *
     * On part des entités, pas des fournisseurs de canevas : `supports()` est un prédicat,
     * il ne dit pas quelle entité il décrit. `getEntityCanvas()` fait la résolution, et
     * c'est exactement celle que le navigateur obtient.
     */
    public function testChaqueOngletContextuelSaitSeFiltrerSurSonParent(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var CanvasBuilder $canvasBuilder */
        $canvasBuilder = static::getContainer()->get(CanvasBuilder::class);

        $orphelins = [];
        $onglets = 0;

        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass) {
                continue;
            }
            $parentClass = $metadata->getName();

            try {
                $canvas = $canvasBuilder->getEntityCanvas($parentClass);
            } catch (\Throwable) {
                continue; // Entité sans canevas : elle n'ouvre aucun onglet.
            }

            foreach (($canvas['liste'] ?? []) as $attribut) {
                if (($attribut['type'] ?? null) !== 'Collection') {
                    continue;
                }
                ++$onglets;

                $code = (string) ($attribut['code'] ?? '');
                if (LienDeCollection::pour($em, $parentClass, $code) === null) {
                    $orphelins[] = sprintf('%s → %s', $this->courtNom($parentClass), $code);
                }
            }
        }

        self::assertGreaterThan(
            40,
            $onglets,
            'Le balayage doit trouver les onglets du workspace : un compte anormalement bas '
            . 'signifierait que la boucle ne voit plus les canevas, et le test ne prouverait rien.',
        );

        self::assertSame([], $orphelins, sprintf(
            "Ces onglets contextuels ne savent pas se filtrer sur leur parent : %s.\n"
            . "Dès la deuxième interaction, ils afficheraient TOUT le cabinet sous le nom du "
            . "parent ouvert. Soit la relation Doctrine nomme mal son côté inverse, soit "
            . "l'onglet ne devrait pas exister.",
            implode(', ', $orphelins),
        ));
    }

    /**
     * LES SIX ONGLETS D'UN CLIENT, NOMMÉMENT — c'est la demande d'origine.
     *
     * Le test précédent les couvre déjà, mais noyés dans une cinquantaine d'autres : si la
     * couverture globale régressait, l'échec nommerait un compte, pas un écran. Ici l'échec
     * dit « les pistes du client ne filtrent plus », ce qu'un lecteur comprend.
     *
     * Le champ attendu est écrit en dur À DESSEIN : `assure` et `clients` sont précisément
     * les deux qu'une symétrie hâtive aurait appelés `client`, et qui auraient échoué en
     * silence.
     */
    public function testLesOngletsDUnClientNommentLeBonChamp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $attendus = [
            'contacts'              => ['champ' => 'client', 'nature' => 'to_one'],
            'pistes'                => ['champ' => 'client', 'nature' => 'to_one'],
            'documents'             => ['champ' => 'client', 'nature' => 'to_one'],
            'notes'                 => ['champ' => 'client', 'nature' => 'to_one'],
            // ⚠ PAS `client` : sur un sinistre, le client s'appelle l'ASSURÉ. Un
            // `parentFieldName` posé par symétrie avec ses voisins aurait été rejeté par
            // `hasAssociation()` — sans erreur, sans log, et la liste aurait tout montré.
            'notificationSinistres' => ['champ' => 'assure', 'nature' => 'to_one'],
            // ⚠ ManyToMany : l'enfant porte une COLLECTION de clients, pas une référence.
            // Une égalité n'exprime pas une appartenance — d'où `MEMBER OF`.
            'partenaires'           => ['champ' => 'clients', 'nature' => 'collection'],
        ];

        foreach ($attendus as $collection => $attendu) {
            self::assertSame(
                $attendu,
                LienDeCollection::pour($em, \App\Entity\Client::class, $collection),
                sprintf('L\'onglet « %s » d\'un client doit se filtrer sur ce champ.', $collection),
            );
        }
    }

    private function courtNom(string $fqcn): string
    {
        $morceaux = explode('\\', $fqcn);

        return end($morceaux);
    }
}
