<?php

namespace App\Tests\Ai\Fournisseur;

use App\Ai\Engine\GeminiAiEngine;
use App\Ai\Fournisseur\EtatDesFournisseurs;
use App\Ai\Fournisseur\FournisseurAReplis;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\MemoireDesRefus;
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
        $refus = static::getContainer()->get(MemoireDesRefus::class);
        foreach (self::MODELES as $modele) {
            $memoire->oublier(MemoireDEpuisement::cle('moteur', 'gemini', $modele));
            $refus->oublier($modele);
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

    /**
     * ⚠ LE TEST QUI MANQUAIT LE 2026-09-28, et son absence a coûté la journée.
     *
     * Les 3 410 tests étaient verts pendant que la console affichait
     * « gemini-flash-lite-latest répond · 100 % » à la minute même où ce modèle
     * renvoyait 503 « This model is currently experiencing high demand ». Aucun test
     * ne pouvait le voir : ils mesuraient tous NOTRE compteur, et notre compteur est
     * précisément vide quand le fournisseur refuse — rien ne part, donc rien n'est
     * consommé. Une suite verte ne prouvait rien sur cet écran-là.
     *
     * Le refus du fournisseur est la QUATRIÈME cause de silence, et la seule qui ne se
     * déduise d'aucune donnée locale. Elle doit donc être lue là où elle est écrite.
     */
    public function testUnModeleQueLeFournisseurVientDeRefuserNeRepondPas(): void
    {
        $moteur = $this->moteur();
        $principal = $moteur->modeleEnVigueur();

        static::getContainer()->get(MemoireDesRefus::class)
            ->noter($principal, 'surchargé chez le fournisseur');

        $etat = $this->etatDuMoteur();

        self::assertNotSame(
            $principal,
            $etat['repondAvec'],
            'Le fournisseur vient de refuser ce modèle : l’écran ne doit pas l’annoncer comme répondant.'
        );
        self::assertNotNull($etat['chaine'][0]['refus'], 'Le refus doit être porté par le maillon, pour que l’écran le nomme.');
        self::assertSame($moteur->modelesDeRepli()[0], $etat['repondAvec']);
    }

    /**
     * Toute la chaîne refusée : le verdict doit dire REFUS, et non « peut répondre ».
     *
     * C'est la contradiction exacte constatée sur pièces : notre fenêtre à 100 %, le
     * verdict « a la place pour un tour », et le courtier lisant dans le chat que Ket
     * ne peut pas répondre.
     */
    public function testToutRefuseDonneUnVerdictDeRefusEtAucunModele(): void
    {
        $refus = static::getContainer()->get(MemoireDesRefus::class);
        foreach (self::MODELES as $modele) {
            $refus->noter($modele, 'surchargé chez le fournisseur');
        }

        $etat = $this->etatDuMoteur();

        self::assertNull($etat['repondAvec'], 'Aucun modèle ne peut répondre : l’écran ne doit en nommer aucun.');
        self::assertFalse($etat['verdict']['peut'], 'Le verdict doit refuser, et non annoncer une place disponible.');
        self::assertSame('refuse', $etat['verdict']['cause']);
    }

    /** Le refus s'efface de lui-même : passée la fenêtre, le modèle redevient éligible. */
    public function testUnRefusOublieRendLeModeleAuService(): void
    {
        $moteur = $this->moteur();
        $principal = $moteur->modeleEnVigueur();
        $refus = static::getContainer()->get(MemoireDesRefus::class);

        $refus->noter($principal, 'surchargé chez le fournisseur');
        $refus->oublier($principal);

        self::assertSame(
            $principal,
            $this->etatDuMoteur()['repondAvec'],
            'Un refus passé ne doit pas écarter durablement un modèle : ce n’est pas un épuisement.'
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
