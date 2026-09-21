<?php

namespace App\Tests\Ai;

use App\Ai\Voix\FournisseurDeVoix;
use App\Ai\Voix\VoixDeKet;
use PHPUnit\Framework\TestCase;

/**
 * L'orchestrateur des voix : ordre de préférence, passage au suivant tant qu'aucun son
 * n'est sorti, et jamais de reprise par une autre voix une fois la lecture commencée.
 */
class VoixDeKetTest extends TestCase
{
    /**
     * Fournisseur factice : $morceaux émis puis $statut rendu.
     *
     * @param list<string> $morceaux
     */
    public static function faux(
        string $nom,
        array $morceaux,
        string $statut,
        bool $disponible = true,
        bool $epuise = false,
    ): FournisseurDeVoix {
        return new class($nom, $morceaux, $statut, $disponible, $epuise) implements FournisseurDeVoix {
            public int $appels = 0;

            public bool $vitesse = false;

            public function __construct(
                private readonly string $nomFaux,
                private readonly array $morceaux,
                private readonly string $statut,
                private readonly bool $disponible,
                private readonly bool $epuise = false,
            ) {
            }

            public function estEpuise(): bool
            {
                return $this->epuise;
            }

            public function nom(): string
            {
                return $this->nomFaux;
            }

            public function voix(): string
            {
                return 'voix-' . $this->nomFaux;
            }

            public function estDisponible(): bool
            {
                return $this->disponible;
            }

            public function modele(bool $vitesse = false): string
            {
                return ($vitesse ? 'rapide-' : 'riche-') . $this->nomFaux;
            }

            public function flux(string $texte, bool $vitesse = false): \Generator
            {
                ++$this->appels;
                $this->vitesse = $vitesse;
                foreach ($this->morceaux as $morceau) {
                    yield $morceau;
                }

                return $this->statut;
            }
        };
    }

    /**
     * SAVOIR D'AVANCE QU'AUCUNE VOIX NE PARLERA — c'est ce qui permet à la page de
     * brancher la synthèse du navigateur sans commencer par demander un refus.
     *
     * Relevé le 2026-09-21 : crédits ElevenLabs du mois épuisés, trois modèles Gemini
     * épuisés. Toutes les lectures passaient déjà par le navigateur, mais chacune
     * attendait d'abord un « non » du serveur.
     */
    public function testToutesLesVoixASecSeDitAvantDAppeler(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', [], FournisseurDeVoix::QUOTA, epuise: true),
            self::faux('gemini', [], FournisseurDeVoix::QUOTA, epuise: true),
        ], 'elevenlabs,gemini');

        self::assertTrue($voix->estDisponible(), 'les fournisseurs restent configurés');
        self::assertFalse($voix->uneVoixPeutParler(), 'mais aucun ne parlera : inutile de l’appeler');
    }

    /** ⚠ ET IL SUFFIT D'UNE SEULE qui ait encore du souffle pour que l'on demande. */
    public function testUneSeuleVoixEncoreDisponibleSuffit(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', [], FournisseurDeVoix::QUOTA, epuise: true),
            self::faux('gemini', ['son'], FournisseurDeVoix::COMPLET),
        ], 'elevenlabs,gemini');

        self::assertTrue($voix->uneVoixPeutParler());
    }

    /** @return array{0: string, 1: string} audio concaténé et statut */
    private static function ecouter(VoixDeKet $voix): array
    {
        $flux = $voix->flux('Bonjour.');
        $audio = '';
        foreach ($flux as $morceau) {
            $audio .= $morceau;
        }

        return [$audio, $flux->getReturn()];
    }

    public function testLOrdreDePreferenceEstRespecte(): void
    {
        $eleven = self::faux('elevenlabs', ['E'], FournisseurDeVoix::COMPLET);
        $gemini = self::faux('gemini', ['G'], FournisseurDeVoix::COMPLET);

        $voix = new VoixDeKet([$gemini, $eleven], 'elevenlabs,gemini');
        [$audio, $statut] = self::ecouter($voix);

        self::assertSame(['E', FournisseurDeVoix::COMPLET], [$audio, $statut]);
        self::assertSame('elevenlabs', $voix->dernierFournisseur()?->nom());
        self::assertSame(0, $gemini->appels, 'le suivant n’est pas appelé quand le premier parle');
        self::assertSame('elevenlabs:voix-elevenlabs:riche-elevenlabs', VoixDeKet::identite($eleven));
    }

    /**
     * LE MODÈLE FAIT PARTIE DE L'IDENTITÉ DE LA VOIX. Sans cela, la phrase lue en Live
     * (modèle rapide) et la même phrase écoutée à l'écrit (modèle riche) partageraient
     * une entrée de cache : on entendrait l'une à la place de l'autre.
     */
    public function testLaVitesseChoisitLeModeleRapideEtUneAutreCleDeCache(): void
    {
        $eleven = self::faux('elevenlabs', ['E'], FournisseurDeVoix::COMPLET);
        $voix = new VoixDeKet([$eleven], 'elevenlabs,gemini');

        $flux = $voix->flux('Bonjour.', true);
        foreach ($flux as $morceau) {
        }

        self::assertTrue($eleven->vitesse, 'la vitesse est transmise au fournisseur');
        self::assertNotSame(VoixDeKet::identite($eleven), VoixDeKet::identite($eleven, true));
        self::assertSame('elevenlabs:voix-elevenlabs:rapide-elevenlabs', VoixDeKet::identite($eleven, true));
    }

    public function testElevenLabsEpuiseGeminiPrendLaMain(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', [], FournisseurDeVoix::QUOTA),
            self::faux('gemini', ['G1', 'G2'], FournisseurDeVoix::COMPLET),
        ], 'elevenlabs,gemini');

        self::assertSame(['G1G2', FournisseurDeVoix::COMPLET], self::ecouter($voix));
        self::assertSame('gemini', $voix->dernierFournisseur()?->nom());
    }

    public function testLOrdreSeRegleParLaVariable(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', ['E'], FournisseurDeVoix::COMPLET),
            self::faux('gemini', ['G'], FournisseurDeVoix::COMPLET),
        ], 'gemini,elevenlabs');

        self::assertSame('G', self::ecouter($voix)[0]);
    }

    public function testAucunSonNullePartRendQuota(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', [], FournisseurDeVoix::QUOTA),
            self::faux('gemini', [], FournisseurDeVoix::ECHEC),
        ], 'elevenlabs,gemini');

        self::assertSame(['', FournisseurDeVoix::QUOTA], self::ecouter($voix));
        self::assertNull($voix->dernierFournisseur());
    }

    public function testUnePanneApresLePremierSonNeRelancePasUneAutreVoix(): void
    {
        $gemini = self::faux('gemini', ['G'], FournisseurDeVoix::COMPLET);
        $voix = new VoixDeKet([self::faux('elevenlabs', ['E'], FournisseurDeVoix::ECHEC), $gemini], 'elevenlabs,gemini');

        self::assertSame(['E', FournisseurDeVoix::ECHEC], self::ecouter($voix));
        self::assertSame(0, $gemini->appels);
    }

    public function testFournisseursIndisponiblesOuNonListesIgnores(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', ['E'], FournisseurDeVoix::COMPLET, false),
            self::faux('autre', ['A'], FournisseurDeVoix::COMPLET),
        ], 'elevenlabs,gemini');

        self::assertFalse($voix->estDisponible());
        self::assertSame(['', FournisseurDeVoix::INDISPONIBLE], self::ecouter($voix));
    }
}
