<?php

namespace App\Tests\Ai\Fournisseur;

use App\Ai\Engine\GeminiAiEngine;
use App\Ai\Fournisseur\EtatDesFournisseurs;
use App\Ai\Fournisseur\FournisseurAReplis;
use App\Ai\Fournisseur\MemoireDEpuisement;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'ÉCRAN DOIT NOMMER LE MODÈLE QUI RÉPOND, PAS CELUI QUI EST CONFIGURÉ.
 *
 * ── CE QUI ÉTAIT FAUX ───────────────────────────────────────────────────────
 * La console affichait `GEMINI_MODEL` — un nom, présenté comme LE modèle. Or ce
 * moteur porte une chaîne de secours (`GEMINI_MODELES_REPLI`) : sur un 503 ou un
 * quota atteint, il bascule sur le suivant et continue de répondre. L'agent lisait
 * donc « gemini-3.1-flash-lite », croyait savoir à quoi Ket était branchée, et
 * cherchait la cause d'une réponse lente ou médiocre du côté d'un modèle qui
 * n'avait pas parlé.
 *
 * Un écran de réglage qui nomme la mauvaise chose est pire qu'un écran muet : on
 * lui fait confiance.
 *
 * ⚠ LA MÉMOIRE D'ÉPUISEMENT EST GLOBALE (cache partagé) : on efface les marques
 * posées ici, en setUp ET en tearDown, sinon on écarte un modèle pour les tests
 * d'autres fichiers.
 */
class ChaineDeModelesTest extends KernelTestCase
{
    private const MODELES = ['gemini-3.1-flash-lite', 'gemini-flash-lite-latest', 'gemini-3.5-flash-lite'];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->oublierTout();
    }

    protected function tearDown(): void
    {
        $this->oublierTout();
        parent::tearDown();
    }

    private function oublierTout(): void
    {
        $memoire = static::getContainer()->get(MemoireDEpuisement::class);
        foreach (self::MODELES as $modele) {
            $memoire->oublier(MemoireDEpuisement::cle('moteur', 'gemini', $modele));
        }
    }

    private function moteur(): GeminiAiEngine
    {
        return static::getContainer()->get(GeminiAiEngine::class);
    }

    /** Le moteur DÉCLARE sa chaîne : sans cela, l'écran ne peut rien en dire. */
    public function testLeMoteurDeclareSaChaineDeSecours(): void
    {
        $moteur = $this->moteur();

        self::assertInstanceOf(FournisseurAReplis::class, $moteur);
        self::assertNotSame(
            [],
            $moteur->modelesDeRepli(),
            'Le moteur a des modèles de secours configurés : il doit savoir les nommer.'
        );
        self::assertNotContains(
            $moteur->modeleEnVigueur(),
            $moteur->modelesDeRepli(),
            'Le modèle principal n’appartient pas à la liste des secours : il est rendu à part.'
        );
    }

    /** Tant que rien n'est à sec, c'est le modèle principal qui répond. */
    public function testSansMarqueCEstLeModelePrincipalQuiRepond(): void
    {
        $etat = $this->etatDuMoteur();

        self::assertSame($this->moteur()->modeleEnVigueur(), $etat['repondAvec']);
        self::assertGreaterThan(1, \count($etat['chaine']), 'La chaîne entière doit être exposée.');
        self::assertTrue($etat['chaine'][0]['principal']);
    }

    /**
     * ⚠ LE TEST QUI COMPTE. Le modèle principal à sec, l'écran doit nommer le
     * SECOURS — celui qui parle réellement — et non continuer d'afficher le premier.
     */
    public function testLeModelePrincipalASecCEstLeSecoursQuiEstNomme(): void
    {
        $moteur = $this->moteur();
        $principal = $moteur->modeleEnVigueur();
        $premierSecours = $moteur->modelesDeRepli()[0];

        static::getContainer()->get(MemoireDEpuisement::class)
            ->marquer(MemoireDEpuisement::cle('moteur', 'gemini', $principal), 3600);

        $etat = $this->etatDuMoteur();

        self::assertSame(
            $premierSecours,
            $etat['repondAvec'],
            'Le principal étant à sec, c’est le secours qui répond : l’écran doit le nommer.'
        );
        self::assertTrue($etat['chaine'][0]['epuise'], 'Le maillon à sec doit être signalé comme tel.');
        self::assertFalse($etat['chaine'][1]['epuise']);
    }

    /** Toute la chaîne à sec : l'écran le dit, plutôt que de nommer un modèle muet. */
    public function testTouteLaChaineASecNeNommeAucunModele(): void
    {
        $memoire = static::getContainer()->get(MemoireDEpuisement::class);
        foreach (self::MODELES as $modele) {
            $memoire->marquer(MemoireDEpuisement::cle('moteur', 'gemini', $modele), 3600);
        }

        self::assertNull(
            $this->etatDuMoteur()['repondAvec'],
            'Aucun modèle ne peut répondre : l’écran ne doit en nommer aucun.'
        );
    }

    /** @return array<string, mixed> */
    private function etatDuMoteur(): array
    {
        foreach (static::getContainer()->get(EtatDesFournisseurs::class)->tout()['moteur'] as $ligne) {
            if ($ligne['nom'] === 'gemini') {
                return $ligne;
            }
        }

        self::fail('Le moteur Gemini doit figurer dans l’état des fournisseurs.');
    }
}
