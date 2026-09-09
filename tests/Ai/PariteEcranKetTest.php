<?php

namespace App\Tests\Ai;

use App\Ai\Parite\CouvertureDesEcrans;
use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TOUTE ACTION D'ÉCRAN EST DÉCLARÉE : COUVERTE PAR KET, OU ASSUMÉE COMME
 * RÉSERVÉE À L'ÉCRAN.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────
 * Depuis que le terminal choisit la surface, un téléphone ne reçoit QUE la
 * conversation. Ce que l'écran sait faire et que Ket ne sait pas faire devient donc
 * impossible en ambulatoire — la parité n'est plus une intention, c'est la frontière
 * du produit sur mobile.
 *
 * La parité entité par entité est déjà verrouillée par `KetPeripherePariteTest`
 * (toute entité lisible est mutable). Ce qui manquait, c'est l'inventaire des ACTIONS
 * D'ÉCRAN : ces boutons de barre d'outils et de menu contextuel déclarés en
 * `attribute_actions` dans les canevas, qui ne sont ni des lectures ni des écritures
 * ordinaires — envoyer un relevé, marquer une police non renouvelable, décider d'un
 * congé, signaler un paiement.
 *
 * ── CE QUI ARRIVERAIT SANS CE TEST ──────────────────────────────────────────────
 * Rien de visible, et c'est le problème. On ajoute un bouton à une barre d'outils —
 * geste courant, une dizaine de lignes dans un canevas — et l'application gagne une
 * capacité que le mobile n'a pas. Personne ne s'en aperçoit : l'écran fonctionne, les
 * tests passent, et l'écart ne se découvre que le jour où un courtier en déplacement
 * cherche le geste et ne le trouve pas. Un an de petits ajouts, et « on peut tout
 * faire depuis son téléphone » est devenu faux sans qu'aucun commit ne le dise.
 *
 * Ce test rend l'ajout impossible en silence : un bouton neuf casse la suite jusqu'à
 * ce que quelqu'un ait écrit ce que Ket en fait, ou pourquoi elle n'en fait rien.
 *
 * Même famille de garde-fou que `ContratDesActionsTest` (serveur ⇄ navigateur) et
 * `AdaptationEcransEtroitsTest` (CSS ⇄ JS ⇄ gabarit) : une vérité traverse plusieurs
 * fichiers, et aucun langage ne force l'accord.
 */
class PariteEcranKetTest extends KernelTestCase
{
    /**
     * Où vivent les déclarations d'`attribute_actions`. Les canevas de formulaire en
     * portent l'essentiel, mais trois entités et un contrôleur en déclarent aussi —
     * les oublier ferait passer le test en ignorant un quart de l'inventaire.
     */
    private const SOURCES = [
        'src/Services/Canvas/Provider/Form',
        'src/Services/Canvas/FormCanvasProvider.php',
        'src/Entity/Avenant.php',
        'src/Entity/Client.php',
        'src/Entity/Invite.php',
        'src/Controller/Admin/ProductionIntermediaireController.php',
        // L'atelier de rapprochement des bordereaux déclare ses propres gestes de
        // ligne (créer / corriger la police d'une ligne) au même format `event`.
        'src/Controller/Admin/BordereauController.php',
    ];

    /**
     * Les actions d'écran RÉELLEMENT déclarées, relevées dans le code.
     *
     * Lecture statique et non introspection des canevas : construire un canevas
     * demande une entité hydratée et tout son contexte, et une action peut être
     * conditionnée à un attribut calculé. Le fichier, lui, dit sans condition ce que
     * le développeur a écrit — et c'est bien de cela qu'on veut tenir l'inventaire.
     *
     * @return list<string>
     */
    private function actionsReellementDeclarees(): array
    {
        $racine = \dirname(__DIR__, 2);
        $evenements = [];

        foreach (self::SOURCES as $source) {
            $chemin = $racine . '/' . $source;
            self::assertFileExists($chemin, "Source d'actions d'écran introuvable : {$source}.");

            $fichiers = is_dir($chemin)
                ? glob($chemin . '/*.php')
                : [$chemin];

            foreach ($fichiers as $fichier) {
                $code = (string) file_get_contents($fichier);
                // La clé `event` porte le nom technique de l'action — celui que le
                // cerveau route. Les deux styles de guillemets cohabitent dans le
                // projet ; n'en lire qu'un laisserait un tiers de l'inventaire dehors.
                preg_match_all('/["\']event["\']\s*=>\s*["\']([^"\']+)["\']/', $code, $trouves);
                foreach ($trouves[1] as $evenement) {
                    $evenements[$evenement] = true;
                }
            }
        }

        $liste = array_keys($evenements);
        sort($liste);

        return $liste;
    }

    /**
     * L'INVENTAIRE EST COMPLET : AUCUNE ACTION N'EST HORS MANIFESTE.
     */
    public function testChaqueActionDEcranEstDeclareeAuManifeste(): void
    {
        $reelles = $this->actionsReellementDeclarees();
        self::assertNotEmpty(
            $reelles,
            "Aucune action d'écran relevée : la lecture du code a cessé de fonctionner, et ce test "
            . 'ne prouverait plus rien.',
        );

        $declarees = CouvertureDesEcrans::actionsDeclarees();
        $manquantes = array_values(array_diff($reelles, $declarees));

        self::assertSame([], $manquantes, sprintf(
            "Ces actions d'écran ne figurent pas au manifeste de parité : %s.\n\n"
            . "Ajoute chacune dans App\\Ai\\Parite\\CouvertureDesEcrans :\n"
            . "  • COUVERTES        si un outil de Ket obtient le même résultat (nomme-le) ;\n"
            . "  • ECRAN_SEULEMENT  sinon, avec le MOTIF écrit.\n\n"
            . "Ce n'est pas une formalité : sur téléphone, la conversation est la seule surface. "
            . "Une action d'écran sans contrepartie est une chose que le courtier ne pourra pas "
            . 'faire en déplacement.',
            implode(', ', $manquantes),
        ));
    }

    /**
     * LE MANIFESTE NE DÉCRIT QUE DES ACTIONS QUI EXISTENT.
     *
     * L'inverse du test précédent, et il compte autant : une entrée qui survit à la
     * suppression de son bouton donne l'illusion d'une parité sur un geste que
     * personne ne peut plus faire — et, en `ECRAN_SEULEMENT`, elle noircit à tort la
     * liste de ce qu'un téléphone ne sait pas faire.
     */
    public function testLeManifesteNeDecritAucuneActionDisparue(): void
    {
        $fantomes = array_values(array_diff(
            CouvertureDesEcrans::actionsDeclarees(),
            $this->actionsReellementDeclarees(),
        ));

        self::assertSame([], $fantomes, sprintf(
            "Le manifeste de parité décrit des actions d'écran qui n'existent plus : %s.\n"
            . 'Retire-les de CouvertureDesEcrans.',
            implode(', ', $fantomes),
        ));
    }

    /**
     * UNE ACTION EST DANS UNE LISTE, JAMAIS DANS LES DEUX.
     *
     * « Couverte par Ket » et « réservée à l'écran » sont contradictoires. Une action
     * présente deux fois rendrait le manifeste illisible, et surtout inexploitable
     * pour répondre à la question qui compte : que ne peut-on pas faire depuis un
     * téléphone ?
     */
    public function testAucuneActionNEstALaFoisCouverteEtReserveeALEcran(): void
    {
        $doubles = array_values(array_intersect(
            array_keys(CouvertureDesEcrans::COUVERTES),
            array_keys(CouvertureDesEcrans::ECRAN_SEULEMENT),
        ));

        self::assertSame([], $doubles, sprintf(
            'Ces actions figurent dans les DEUX listes du manifeste : %s.',
            implode(', ', $doubles),
        ));
    }

    /**
     * LES OUTILS NOMMÉS PAR LE MANIFESTE EXISTENT VRAIMENT.
     *
     * C'est la vérification qui empêche la parité FANTÔME. Un outil renommé, ou
     * retiré, laisserait derrière lui un manifeste qui continue d'affirmer que
     * l'action est couverte — et l'affirmation traverserait ensuite la fiche de
     * capacités et la page Paramètres IA. Le nom est donc confronté au catalogue
     * réel, celui que le moteur déclare.
     */
    public function testChaqueOutilNommeExisteAuCatalogue(): void
    {
        self::bootKernel();

        $noms = [];
        foreach (static::getContainer()->get(TrousseCatalogue::class)->tous() as $outil) {
            $noms[$outil->name()] = true;
        }
        self::assertNotEmpty($noms, "Catalogue d'outils vide : le test ne prouverait rien.");

        foreach (CouvertureDesEcrans::COUVERTES as $action => $outil) {
            self::assertArrayHasKey($outil, $noms, sprintf(
                "Le manifeste dit que « %s » est couverte par l'outil « %s », qui n'existe pas "
                . "au catalogue.\nSoit l'outil a été renommé (mets le manifeste à jour), soit "
                . 'la parité annoncée est fausse.',
                $action,
                $outil,
            ));
        }
    }

    /**
     * CHAQUE EXCEPTION EST MOTIVÉE, ET LE MOTIF DIT QUELQUE CHOSE.
     *
     * Une liste d'exceptions sans motifs redevient en un an une liste que personne
     * n'ose toucher. Le seuil de longueur n'est pas de la coquetterie : il empêche le
     * « TODO » et le « non applicable », qui ne se relisent pas.
     */
    public function testChaqueExceptionPorteUnMotifExplicite(): void
    {
        foreach (CouvertureDesEcrans::ECRAN_SEULEMENT as $action => $motif) {
            self::assertGreaterThan(60, mb_strlen($motif), sprintf(
                "Le motif de « %s » est trop court pour être un motif : « %s ».\n"
                . "Écris pourquoi la conversation n'est pas le bon endroit — « pas encore fait » "
                . 'est recevable, mais doit être dit.',
                $action,
                $motif,
            ));
        }
    }
}
