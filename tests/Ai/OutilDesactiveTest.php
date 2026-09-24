<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Reglage\CatalogueDesReglages;
use App\Ai\Reglage\Classe;
use App\Ai\Reglage\PoidsDesDeclarations;
use App\Ai\Reglage\ReglagesDeKet;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Repository\PlateformeParametresRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN OUTIL COUPÉ DISPARAÎT VRAIMENT — des déclarations ET du prompt.
 *
 * ── CE QUE CE FICHIER PROTÈGE ───────────────────────────────────────────────
 * L'écran de console promet un GAIN en jetons. Un interrupteur qui retirerait
 * l'outil de la liste envoyée au fournisseur mais le laisserait nommé dans le
 * prompt serait pire que rien : le modèle promettrait une capacité qu'il ne peut
 * pas appeler — l'outil fantôme que `PromptSansOutilFantomeTest` interdit par
 * ailleurs — et l'économie annoncée serait fausse.
 *
 * C'est aussi ce fichier qui ARBITRE LA CLASSIFICATION. `CatalogueDesReglages`
 * déclare une liste d'outils INDISPENSABLES — ceux que le prompt nomme en dur. Cette
 * liste était une hypothèse ; le test la vérifie en coupant chaque outil
 * DÉSACTIVABLE à tour de rôle. S'il en reste un dans le prompt, c'est qu'il est de
 * fait indispensable, ou que le bloc qui le nomme doit devenir conditionnel.
 *
 * ⚠ SINGLETON GLOBAL, SANS ROLLBACK : `plateforme_parametres` est purgé en setUp ET
 * en tearDown, faute de quoi on fait échouer des tests d'autres fichiers.
 */
class OutilDesactiveTest extends KernelTestCase
{
    use JeuDeTestKetTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purger();
    }

    protected function tearDown(): void
    {
        $this->purger();
        parent::tearDown();
    }

    private function purger(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()->executeStatement('DELETE FROM plateforme_parametres');
        static::getContainer()->get(ReglagesDeKet::class)->refresh();
    }

    /** Coupe des outils et réarme les services qui mettent la carte en cache. */
    private function couper(string ...$noms): void
    {
        $repository = static::getContainer()->get(PlateformeParametresRepository::class);
        $singleton = $repository->getSingleton();
        $singleton->setKetReglages(['outils' => array_fill_keys($noms, false)]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::getContainer()->get(ReglagesDeKet::class)->refresh();
    }

    private function scope(): AiScope
    {
        [$entreprise, $invite] = $this->jeuDeTestKet();

        return new AiScope($entreprise, $invite);
    }

    public function testUnOutilCoupeNEstPlusDeclare(): void
    {
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);
        $scope = $this->scope();

        self::assertContains('conges', $catalogue->nomsDe(Trousse::LECTURE, $scope));

        $this->couper('conges');

        self::assertNotContains(
            'conges',
            $catalogue->nomsDe(Trousse::LECTURE, $scope),
            'Un outil coupé en console ne doit plus être déclaré au fournisseur.'
        );
    }

    /**
     * LE GAIN ANNONCÉ EST LE GAIN RÉEL. La console affiche le poids d'un outil avant
     * de proposer de le couper ; la baisse mesurée du payload doit lui correspondre.
     */
    public function testLePayloadBaisseDuPoidsAnnonce(): void
    {
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);
        $scope = $this->scope();

        $avant = PoidsDesDeclarations::octetsDeLaListe($catalogue->outilsDe(Trousse::LECTURE, $scope));

        $poidsAnnonce = 0;
        foreach (static::getContainer()->get(CatalogueDesReglages::class)->outils() as $ligne) {
            if ($ligne['nom'] === 'statistiques') {
                $poidsAnnonce = $ligne['octets'];
            }
        }
        self::assertGreaterThan(0, $poidsAnnonce);

        $this->couper('statistiques');
        $apres = PoidsDesDeclarations::octetsDeLaListe($catalogue->outilsDe(Trousse::LECTURE, $scope));

        // La virgule de séparation du tableau JSON explique l'octet d'écart : on
        // retire une entrée d'une liste, pas seulement son contenu.
        self::assertEqualsWithDelta(
            $poidsAnnonce,
            $avant - $apres,
            1,
            'Le gain affiché en console doit égaler la baisse réelle du payload.'
        );
    }

    /**
     * LE PROMPT NE LE NOMME PLUS NON PLUS. C'est la moitié qui compte : sans elle,
     * Ket promettrait une capacité absente du tour.
     */
    public function testLePromptNeNommePlusUnOutilCoupe(): void
    {
        $conteneur = static::getContainer();
        [$entreprise, $invite] = $this->jeuDeTestKet();
        $builder = $conteneur->get(AiContextBuilder::class);

        $this->couper('catalogue_des_risques');

        $requete = $builder->build($entreprise, $invite, new AssistantConversation());
        $prompt = $builder->toSystemPrompt($requete, Trousse::LECTURE);

        self::assertStringNotContainsString(
            'catalogue_des_risques',
            $prompt,
            'Le prompt nomme encore un outil coupé : Ket promettrait une capacité qu’elle ne peut pas appeler.'
        );
    }

    /**
     * L'ARBITRE DE LA CLASSIFICATION. Chaque outil déclaré DÉSACTIVABLE doit pouvoir
     * être coupé sans laisser son nom dans le prompt. Celui qui échoue ici est, de
     * fait, INDISPENSABLE — et sa fiche doit le dire.
     */
    public function testChaqueOutilDesactivableDisparaitVraimentDuPrompt(): void
    {
        $conteneur = static::getContainer();
        [$entreprise, $invite] = $this->jeuDeTestKet();
        $builder = $conteneur->get(AiContextBuilder::class);
        $catalogue = $conteneur->get(CatalogueDesReglages::class);

        $fantomes = [];

        foreach ($catalogue->outils() as $ligne) {
            if ($ligne['classe'] !== Classe::DESACTIVABLE) {
                continue;
            }

            $this->couper($ligne['nom']);
            $requete = $builder->build($entreprise, $invite, new AssistantConversation());

            foreach ([Trousse::LECTURE, Trousse::ECRITURE] as $trousse) {
                if (str_contains($builder->toSystemPrompt($requete, $trousse), $ligne['nom'])) {
                    $fantomes[] = sprintf('%s (trousse %s)', $ligne['nom'], $trousse->value);
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $fantomes,
            'Ces outils sont nommés EN DUR dans le prompt : les couper produirait un outil fantôme. '
            . 'Ils appartiennent aux INDISPENSABLES de CatalogueDesReglages, ou le bloc qui les nomme '
            . 'doit devenir conditionnel.'
        );
    }

    /**
     * LA CEINTURE. Le modèle ne devrait pas pouvoir demander un outil coupé — mais
     * une page restée ouverte, ou un plan préparé au tour d'avant, le peut.
     */
    public function testLExecutionDUnOutilCoupeRendIntrouvable(): void
    {
        $this->couper('statistiques');

        $resultat = static::getContainer()->get(ExecuteurDOutils::class)
            ->executer('statistiques', [], $this->scope());

        self::assertSame(
            AiToolResult::STATUS_INTROUVABLE,
            $resultat->status,
            'Un outil coupé doit répondre comme un nom inconnu : l’utilisateur n’a pas à '
            . 'apprendre la configuration de la plateforme.'
        );
    }

    /** Sans réglage, rien ne change : c'est la garantie du déploiement. */
    public function testSansReglageToutResteDeclare(): void
    {
        $noms = static::getContainer()->get(TrousseCatalogue::class)->nomsDe(Trousse::ECRITURE, $this->scope());

        self::assertContains('vigie_echeances', $noms);
        self::assertContains('conges', $noms);
        self::assertTrue(static::getContainer()->get(ReglagesDeKet::class)->estVierge());
    }
}
