<?php

namespace App\Tests\Ai\Fournisseur;

use App\Ai\Fournisseur\MemoireDEpuisement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * LA MÉMOIRE D'ÉPUISEMENT — se souvenir qu'un fournisseur est à sec, pour ne plus
 * lui parler tant qu'il ne peut rien rendre.
 *
 * Ce qui est verrouillé ici : la clé porte sa FAMILLE (sans quoi deux usages d'un
 * même fournisseur se marquent l'un l'autre), l'échéance est RETENUE (sans quoi
 * la console ne peut dire qu'« indisponible », un mot qui n'aide personne), et la
 * marque s'OUBLIE (sans quoi un marquage erroné n'a d'autre issue qu'un accès
 * serveur).
 *
 * Les dates sont CALCULÉES, jamais figées : une horloge injectée, et aucun test
 * qui dorme.
 */
class MemoireDEpuisementTest extends TestCase
{
    private int $instant = 1_000_000;

    private function memoire(?ArrayAdapter $cache = null): MemoireDEpuisement
    {
        return new MemoireDEpuisement($cache ?? new ArrayAdapter(), function (): int { return $this->instant; });
    }

    public function testUnFournisseurNonMarqueNEstPasASec(): void
    {
        $this->assertFalse($this->memoire()->estEpuise('moteur:anthropic:claude-haiku-4-5'));
    }

    public function testUneMarqueTientEtSeRelit(): void
    {
        $memoire = $this->memoire();
        $memoire->marquer('moteur:gemini:flash', 120);

        $this->assertTrue($memoire->estEpuise('moteur:gemini:flash'));
    }

    /**
     * L'ÉCHÉANCE EST RETENUE, PAS SEULEMENT LE FAIT D'ÊTRE À SEC.
     *
     * C'est ce qui permet à la console de dire « à sec jusqu'à 09:05 » et à Ket
     * d'annoncer un délai réel, au lieu d'un « réessayez plus tard » qui n'engage
     * personne.
     */
    public function testLEcheanceEstRetenue(): void
    {
        $memoire = $this->memoire();
        $memoire->marquer('voix:elevenlabs', 300);

        $echeance = $memoire->echeance('voix:elevenlabs');
        $this->assertNotNull($echeance);
        $this->assertSame($this->instant + 300, $echeance->getTimestamp());
    }

    public function testSansMarqueIlNYAPasDEcheance(): void
    {
        $this->assertNull($this->memoire()->echeance('voix:elevenlabs'));
    }

    /**
     * LE DÉFAUT QUE LA CLÉ PAR FAMILLE SUPPRIME.
     *
     * Sans famille dans la clé, un quota de synthèse vocale épuisé écarterait la
     * transcription du même fournisseur — deux compteurs pourtant distincts. Là où
     * les crédits SONT réellement partagés (ElevenLabs facture voix et oreille sur
     * la même réserve), on le dit en donnant la même famille aux deux, plutôt que
     * de le laisser arriver par accident.
     */
    public function testLaFamilleSepareLesUsagesDUnMemeFournisseur(): void
    {
        $memoire = $this->memoire();
        $memoire->marquer(MemoireDEpuisement::cle('voix', 'gemini', 'tts-preview'), 600);

        $this->assertTrue($memoire->estEpuise(MemoireDEpuisement::cle('voix', 'gemini', 'tts-preview')));
        $this->assertFalse(
            $memoire->estEpuise(MemoireDEpuisement::cle('oreille', 'gemini', 'flash-lite')),
            'La voix à sec ne doit pas mettre l’oreille hors jeu : deux compteurs, deux clés.',
        );
        $this->assertFalse(
            $memoire->estEpuise(MemoireDEpuisement::cle('moteur', 'gemini', 'flash-lite')),
            'Ni le moteur de texte.',
        );
    }

    public function testDesCreditsPartagesSeDisentParUneFamilleCommune(): void
    {
        $memoire = $this->memoire();
        $partagee = MemoireDEpuisement::cle('credits', 'elevenlabs');
        $memoire->marquer($partagee, 600);

        // La voix ET l'oreille lisent la même clé : c'est explicite, pas accidentel.
        $this->assertTrue($memoire->estEpuise($partagee));
    }

    /**
     * RÉARMER — sans quoi une marque erronée met un fournisseur hors jeu jusqu'à
     * minuit heure du Pacifique, et la seule issue serait un accès serveur.
     */
    public function testUneMarqueSOublie(): void
    {
        $memoire = $this->memoire();
        $memoire->marquer('moteur:anthropic:claude-haiku-4-5', 86400);
        $memoire->oublier('moteur:anthropic:claude-haiku-4-5');

        $this->assertFalse($memoire->estEpuise('moteur:anthropic:claude-haiku-4-5'));
        $this->assertNull($memoire->echeance('moteur:anthropic:claude-haiku-4-5'));
    }

    /**
     * Une date annoncée par le fournisseur — le plafond de dépense d'Anthropic
     * écrit la sienne en toutes lettres. Calculée, jamais figée.
     */
    public function testUneDateAnnonceeSeTraduitEnSecondes(): void
    {
        $maintenant = new \DateTimeImmutable('2026-09-22 10:00:00', new \DateTimeZone('UTC'));
        $reouverture = $maintenant->modify('+3 hours');

        $this->assertSame(3 * 3600, MemoireDEpuisement::jusqua($reouverture, $maintenant));
    }

    /**
     * Une date DÉJÀ PASSÉE rend une minute, jamais zéro ni un négatif : mieux vaut
     * réessayer une fois de trop que rester fermé sur une horloge mal lue.
     */
    public function testUneDatePasseeNeFermePasIndefiniment(): void
    {
        $maintenant = new \DateTimeImmutable('2026-09-22 10:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(60, MemoireDEpuisement::jusqua($maintenant->modify('-1 day'), $maintenant));
    }

    /** Les deux horizons du projet restent des durées positives et plausibles. */
    public function testLesHorizonsDeQuotaSontDesDureesPlausibles(): void
    {
        $this->assertGreaterThanOrEqual(60, MemoireDEpuisement::jusquAMinuitPacifique());
        $this->assertLessThanOrEqual(86400, MemoireDEpuisement::jusquAMinuitPacifique());

        $this->assertGreaterThanOrEqual(3600, MemoireDEpuisement::jusquAuMoisProchain());
        $this->assertLessThanOrEqual(32 * 86400, MemoireDEpuisement::jusquAuMoisProchain());
    }
}
