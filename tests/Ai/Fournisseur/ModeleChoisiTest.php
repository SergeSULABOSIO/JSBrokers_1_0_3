<?php

namespace App\Tests\Ai\Fournisseur;

use App\Ai\Fournisseur\ModeleChoisi;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Entity\PlateformeParametres;
use App\Repository\PlateformeParametresRepository;
use PHPUnit\Framework\TestCase;

/**
 * LE MODÈLE CHOISI DEPUIS LA CONSOLE — et le garde-fou qui empêche une faute de
 * frappe d'y couper la parole à Ket.
 *
 * Deux exigences, de natures opposées, tenues par la même classe :
 *
 *  - CE QUE LA CONSOLE DIT S'APPLIQUE. Un agent qui épingle `claude-sonnet-5`
 *    doit voir le message suivant partir sur ce modèle-là. Sans redémarrage :
 *    c'est la raison d'être de cette classe, appelée depuis les méthodes et
 *    jamais depuis un constructeur.
 *
 *  - UNE SAISIE ABERRANTE NE CASSE RIEN. Un champ libre est un champ où l'on se
 *    trompe, et un nom de modèle inexistant vaut un 404 à CHAQUE message. La
 *    valeur douteuse est donc ignorée et le défaut du serveur reprend la main —
 *    Ket répond, simplement pas avec le modèle demandé.
 */
class ModeleChoisiTest extends TestCase
{
    private function politique(?array $enBase): PolitiqueDesFournisseurs
    {
        $parametres = (new PlateformeParametres())->setKetFournisseurs($enBase);
        $repository = $this->createMock(PlateformeParametresRepository::class);
        $repository->method('getSingleton')->willReturn($parametres);

        return new PolitiqueDesFournisseurs($repository);
    }

    /** Base vierge : le `.env` reste la couche de défauts, exactement comme avant. */
    public function testSansPolitiqueLeDefautDuServeurEstRendu(): void
    {
        self::assertSame(
            'claude-haiku-4-5',
            ModeleChoisi::pour(null, 'moteur', 'anthropic', 'claude-haiku-4-5'),
        );
        self::assertSame(
            'claude-haiku-4-5',
            ModeleChoisi::pour($this->politique(null), 'moteur', 'anthropic', 'claude-haiku-4-5'),
        );
    }

    /** LE TEST QUI JUSTIFIE LE CHAMP : ce que l'agent écrit est ce qui est appelé. */
    public function testLeModeleDeLaConsoleLEmporte(): void
    {
        $politique = $this->politique([
            'moteur' => ['reglages' => ['anthropic' => ['modele' => 'claude-sonnet-5']]],
        ]);

        self::assertSame(
            'claude-sonnet-5',
            ModeleChoisi::pour($politique, 'moteur', 'anthropic', 'claude-haiku-4-5'),
        );
    }

    /**
     * Le réglage d'un fournisseur ne déborde jamais sur un autre, ni d'une famille
     * sur l'autre — le même nom « gemini » sert dans les cinq.
     */
    public function testUnReglageNeDebordePasSurSonVoisin(): void
    {
        $politique = $this->politique([
            'voix' => ['reglages' => ['gemini' => ['modele' => 'gemini-tts-special']]],
        ]);

        self::assertSame('gemini-tts-special', ModeleChoisi::pour($politique, 'voix', 'gemini', 'defaut-voix'));
        self::assertSame('defaut-oreille', ModeleChoisi::pour($politique, 'oreille', 'gemini', 'defaut-oreille'));
        self::assertSame('defaut-voix-11', ModeleChoisi::pour($politique, 'voix', 'elevenlabs', 'defaut-voix-11'));
    }

    /**
     * @dataProvider saisiesAberrantes
     *
     * LE GARDE-FOU. Chacune de ces valeurs, appelée telle quelle, vaudrait un refus
     * du fournisseur à chaque message — donc un Ket muet jusqu'à ce que quelqu'un
     * pense à rouvrir la console.
     */
    public function testUneSaisieAberranteRendLaMainAuDefaut(string $saisie): void
    {
        $politique = $this->politique([
            'moteur' => ['reglages' => ['anthropic' => ['modele' => $saisie]]],
        ]);

        self::assertSame(
            'claude-haiku-4-5',
            ModeleChoisi::pour($politique, 'moteur', 'anthropic', 'claude-haiku-4-5'),
            sprintf('« %s » ne doit jamais partir vers le fournisseur.', $saisie),
        );
    }

    public static function saisiesAberrantes(): array
    {
        return [
            'vide'                 => [''],
            'espaces seuls'        => ['   '],
            'une phrase'           => ['le modèle le plus rapide'],
            'un chemin'            => ['../../etc/passwd'],
            'une URL'              => ['https://api.exemple.test/v1/models/x'],
            'un accent'            => ['modèle-français'],
            'une apostrophe'       => ["claude'; DROP"],
            'trop long'            => [str_repeat('a', 101)],
        ];
    }

    /** Une valeur plausible mais entourée d'espaces est simplement rognée. */
    public function testLesEspacesAutourSontRognes(): void
    {
        $politique = $this->politique([
            'moteur' => ['reglages' => ['gemini' => ['modele' => "  gemini-3.1-flash-lite\t"]]],
        ]);

        self::assertSame('gemini-3.1-flash-lite', ModeleChoisi::pour($politique, 'moteur', 'gemini', 'defaut'));
    }

    /** Une liste de modèles : la chaîne de repli de Gemini, ses modèles de voix. */
    public function testUneListeValideEstReprise(): void
    {
        $politique = $this->politique([
            'voix' => ['reglages' => ['gemini' => ['modele' => 'tts-a, tts-b ,tts-c']]],
        ]);

        self::assertSame('tts-a,tts-b,tts-c', ModeleChoisi::liste($politique, 'voix', 'gemini', 'defaut'));
    }

    /**
     * Une liste à moitié valide est écartée EN ENTIER. La garder à moitié ferait
     * échouer un appel sur deux — un défaut bien plus coûteux à diagnostiquer qu'un
     * réglage visiblement sans effet.
     */
    public function testUneListeAMoitieValideEstEcarteeEnEntier(): void
    {
        $politique = $this->politique([
            'voix' => ['reglages' => ['gemini' => ['modele' => 'tts-a, un modèle inventé, tts-c']]],
        ]);

        self::assertSame('defaut', ModeleChoisi::liste($politique, 'voix', 'gemini', 'defaut'));
    }

    /**
     * La même règle sert au contrôleur de console pour refuser la saisie. Les deux
     * bouts doivent juger pareil, sans quoi l'écran accepterait une valeur que
     * l'appel ignorerait ensuite en silence.
     */
    public function testLaFormeJugeeEstLaMemeDesDeuxCotes(): void
    {
        self::assertTrue(ModeleChoisi::estPlausible('claude-haiku-4-5'));
        self::assertTrue(ModeleChoisi::estPlausible('eleven_multilingual_v2'));
        self::assertTrue(ModeleChoisi::estPlausible('gemini-3.1-flash-lite'));
        self::assertTrue(ModeleChoisi::estPlausible('models:v1.2'));
        self::assertFalse(ModeleChoisi::estPlausible('modèle avec espaces'));
        self::assertFalse(ModeleChoisi::estPlausible(''));
    }
}
