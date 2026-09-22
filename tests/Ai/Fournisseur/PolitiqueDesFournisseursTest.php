<?php

namespace App\Tests\Ai\Fournisseur;

use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Entity\PlateformeParametres;
use App\Repository\PlateformeParametresRepository;
use PHPUnit\Framework\TestCase;

/**
 * LA POLITIQUE DES FOURNISSEURS — ce que la console décide, et ce qu'elle ne
 * décide pas.
 *
 * Ce qui est verrouillé ici tient en une phrase : une base vierge doit se
 * comporter EXACTEMENT comme avant l'existence de cet écran. Tout le reste —
 * l'ordre, l'épinglage, les réglages — ne fait que se superposer aux variables
 * d'environnement, clé par clé, jamais en bloc.
 */
class PolitiqueDesFournisseursTest extends TestCase
{
    private function politique(?array $enBase): PolitiqueDesFournisseurs
    {
        $parametres = (new PlateformeParametres())->setKetFournisseurs($enBase);
        $repository = $this->createMock(PlateformeParametresRepository::class);
        $repository->method('getSingleton')->willReturn($parametres);

        return new PolitiqueDesFournisseurs(
            $repository,
            moteursParDefaut: 'anthropic,gemini,simulated',
            comprenantsParDefaut: 'anthropic,gemini',
            finisseursParDefaut: 'anthropic,gemini',
            voixParDefaut: 'elevenlabs,gemini',
            oreillesParDefaut: 'elevenlabs,gemini',
        );
    }

    /**
     * LE CAS QUI COMPTE LE PLUS : rien en base. La plateforme doit se comporter
     * comme avant, sur le seul `.env`. Une migration qui changerait le
     * comportement du jour où elle passe serait une mauvaise migration.
     */
    public function testUneBaseViergeRendExactementLesDefautsDuEnv(): void
    {
        $politique = $this->politique(null);

        $this->assertSame('anthropic,gemini,simulated', $politique->ordre('moteur'));
        $this->assertSame('anthropic,gemini', $politique->ordre('comprehension'));
        $this->assertSame('anthropic,gemini', $politique->ordre('dictee'));
        $this->assertSame('elevenlabs,gemini', $politique->ordre('voix'));
        $this->assertSame('elevenlabs,gemini', $politique->ordre('oreille'));
        $this->assertSame(PolitiqueDesFournisseurs::MODE_CHAINE, $politique->mode('moteur'));
    }

    public function testUnOrdrePersonnaliseRemplaceCeluiDuEnv(): void
    {
        $politique = $this->politique(['moteur' => ['ordre' => ['gemini', 'anthropic']]]);

        $this->assertSame('gemini,anthropic', $politique->ordre('moteur'));
    }

    /**
     * ÉPINGLER, C'EST COUPER LES AUTRES. Un fournisseur épinglé est le seul de la
     * liste : les suivants ne seront jamais appelés, ce qui est précisément ce que
     * l'agent demande en épinglant.
     */
    public function testEpinglerNeLaisseQuUnSeulFournisseur(): void
    {
        $politique = $this->politique([
            'voix' => ['mode' => PolitiqueDesFournisseurs::MODE_EPINGLE, 'ordre' => ['elevenlabs', 'gemini']],
        ]);

        $this->assertSame('elevenlabs', $politique->ordre('voix'));
        $this->assertSame(PolitiqueDesFournisseurs::MODE_EPINGLE, $politique->mode('voix'));
    }

    /**
     * FUSION CLÉ PAR CLÉ. Personnaliser une famille ne doit rien faire aux autres :
     * sans cela, régler la voix couperait le moteur, et personne ne comprendrait
     * pourquoi.
     */
    public function testPersonnaliserUneFamilleNeTouchePasLesAutres(): void
    {
        $politique = $this->politique(['voix' => ['ordre' => ['gemini']]]);

        $this->assertSame('gemini', $politique->ordre('voix'));
        $this->assertSame('anthropic,gemini,simulated', $politique->ordre('moteur'));
        $this->assertSame('elevenlabs,gemini', $politique->ordre('oreille'));
    }

    /**
     * UNE LISTE VIDE N'EST PAS UNE POLITIQUE. Décocher toutes les cases d'une
     * famille couperait Ket entièrement — pour un geste qui ressemble à « je
     * réfléchis encore ». On garde le défaut, et l'agent voit que rien n'a changé.
     */
    public function testUneListeVideNeCoupePasLaFamille(): void
    {
        $politique = $this->politique(['moteur' => ['ordre' => []]]);

        $this->assertSame('anthropic,gemini,simulated', $politique->ordre('moteur'));
    }

    /**
     * Un réglage saisi seul — un modèle, une voix — ne doit pas emporter l'ordre
     * avec lui. C'est le même piège que ci-dessus, par une autre porte.
     */
    public function testUnReglageSeulNeCoupePasLOrdre(): void
    {
        $politique = $this->politique([
            'moteur' => ['reglages' => ['anthropic' => ['modele' => 'claude-sonnet-5']]],
        ]);

        $this->assertSame('anthropic,gemini,simulated', $politique->ordre('moteur'));
        $this->assertSame('claude-sonnet-5', $politique->reglage('moteur', 'anthropic', 'modele'));
    }

    public function testUnReglageAbsentRendSonDefaut(): void
    {
        $politique = $this->politique(null);

        $this->assertSame(
            'claude-haiku-4-5',
            $politique->reglage('moteur', 'anthropic', 'modele', 'claude-haiku-4-5'),
        );
    }

    /**
     * Un mode inconnu — une valeur tapée à la main, un JSON d'une version
     * antérieure — retombe sur la chaîne plutôt que de mettre la famille dans un
     * état que personne ne sait lire.
     */
    public function testUnModeInconnuRetombeSurLaChaine(): void
    {
        $politique = $this->politique(['moteur' => ['mode' => 'n’importe quoi']]);

        $this->assertSame(PolitiqueDesFournisseurs::MODE_CHAINE, $politique->mode('moteur'));
    }

    /**
     * `refresh()` est l'UNIQUE mécanisme d'invalidation du projet : le contrôleur
     * l'appelle après son `flush()`. Sans lui, l'écran réafficherait la politique
     * d'avant l'enregistrement, dans la même requête.
     */
    public function testRefreshRelitLaPolitique(): void
    {
        $parametres = new PlateformeParametres();
        $repository = $this->createMock(PlateformeParametresRepository::class);
        $repository->method('getSingleton')->willReturn($parametres);
        $politique = new PolitiqueDesFournisseurs($repository, moteursParDefaut: 'anthropic,gemini');

        $this->assertSame('anthropic,gemini', $politique->ordre('moteur'));

        $parametres->setKetFournisseurs(['moteur' => ['ordre' => ['gemini']]]);
        $this->assertSame('anthropic,gemini', $politique->ordre('moteur'), 'le cache de requête tient');

        $politique->refresh();
        $this->assertSame('gemini', $politique->ordre('moteur'));
    }

    /**
     * « Rien de personnalisé » se dit par l'ABSENCE, jamais par un tableau vide —
     * qui signifierait « aucun fournisseur ». Même normalisation que pour les
     * formats de document.
     */
    public function testUneCarteVideRedevientNull(): void
    {
        $this->assertNull((new PlateformeParametres())->setKetFournisseurs([])->getKetFournisseurs());
    }
}
