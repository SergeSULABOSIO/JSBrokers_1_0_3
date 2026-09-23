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

    /**
     * ⚠ LA RÈGLE INTANGIBLE : À SEC, LE NAVIGATEUR PREND LA MAIN — SANS RIEN DEMANDER.
     *
     * Elle ne dépend d'AUCUN réglage. Que « navigateur » soit coché dans la console
     * ou non, qu'il figure dans la chaîne ou pas : dès que plus aucune voix du serveur
     * n'a de souffle, la page lit elle-même. Un quota épuisé est un fait, pas une
     * décision — et demander son avis à l'utilisateur au moment où Ket devrait parler
     * serait lui faire payer deux fois le même incident.
     *
     * Ce test existe parce que l'écran de console affiche « écarté » à côté du
     * navigateur tant qu'on ne l'a pas coché : il ne faudrait pas qu'un jour quelqu'un
     * en conclue que le repli se configure. Il ne se configure pas. Ce qui se
     * configure, c'est de le mettre en PREMIER — donc de ne plus jamais appeler le
     * serveur, même quand il a du souffle.
     */
    public function testAUnQuotaEpuiseLeNavigateurPrendLaMainSansEtreConfigure(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', [], FournisseurDeVoix::QUOTA, epuise: true),
            self::faux('gemini', [], FournisseurDeVoix::QUOTA, epuise: true),
        ], 'elevenlabs,gemini');

        self::assertFalse($voix->leNavigateurDAbord(), 'il n’est même pas nommé dans la chaîne');
        self::assertFalse(
            $voix->uneVoixPeutParler(),
            'et pourtant la page lit elle-même : le repli ne se demande pas, il s’applique',
        );
    }

    /** Une seule voix encore vivante suffit à garder la parole au serveur. */
    public function testLeRepliNeSeDeclencheQueQuandPlusRienNeRepond(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', [], FournisseurDeVoix::QUOTA, epuise: true),
            self::faux('gemini', ['son'], FournisseurDeVoix::COMPLET),
        ], 'elevenlabs,gemini');

        self::assertTrue($voix->uneVoixPeutParler(), 'gemini a encore du souffle');
    }

    /**
     * LE NAVIGATEUR EN TÊTE : on ne dérange plus le serveur du tout.
     *
     * Ce n'est pas une panne, c'est un CHOIX d'exploitant, posé depuis la console :
     * une voix de serveur demande un aller-retour — jusqu'à dix secondes mesurées en
     * production le 2026-09-23 — là où le navigateur parle instantanément. Certains
     * cabinets préféreront la plus belle voix, d'autres la plus rapide.
     */
    public function testLeNavigateurEnTeteCourtCircuiteLeServeur(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', ['son'], FournisseurDeVoix::COMPLET),
            self::faux('gemini', ['son'], FournisseurDeVoix::COMPLET),
        ], 'navigateur,elevenlabs,gemini');

        self::assertTrue($voix->leNavigateurDAbord());
        self::assertFalse(
            $voix->uneVoixPeutParler(),
            'la page lit elle-même : inutile d’attendre une réponse du serveur',
        );
        self::assertTrue($voix->estDisponible(), 'les voix du serveur restent configurées');
    }

    /**
     * LE NAVIGATEUR EN DERNIER RESTE CE QU'IL A TOUJOURS ÉTÉ : le filet de sécurité.
     * On compare des RANGS, pas des présences — sans quoi le nommer quelque part
     * dans la liste suffirait à couper les voix du serveur.
     */
    public function testLeNavigateurEnDernierNeChangeRien(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', ['son'], FournisseurDeVoix::COMPLET),
            self::faux('gemini', ['son'], FournisseurDeVoix::COMPLET),
        ], 'elevenlabs,gemini,navigateur');

        self::assertFalse($voix->leNavigateurDAbord());
        self::assertTrue($voix->uneVoixPeutParler(), 'le serveur parle, comme avant');
    }

    /** Sans le nommer, rien ne change : c'est le comportement par défaut. */
    public function testSansLeNommerRienNeChange(): void
    {
        $voix = new VoixDeKet([self::faux('gemini', ['son'], FournisseurDeVoix::COMPLET)], 'elevenlabs,gemini');

        self::assertFalse($voix->leNavigateurDAbord());
        self::assertTrue($voix->uneVoixPeutParler());
    }

    /**
     * Devant une voix ÉCARTÉE de la chaîne, le navigateur l'emporte quand même :
     * ce qui compte est le rang des fournisseurs RÉELLEMENT retenus.
     */
    public function testUneVoixHorsChaineNeProtegePasLeServeur(): void
    {
        $voix = new VoixDeKet([
            self::faux('elevenlabs', ['son'], FournisseurDeVoix::COMPLET),
            self::faux('gemini', ['son'], FournisseurDeVoix::COMPLET),
        ], 'navigateur,gemini');

        self::assertTrue($voix->leNavigateurDAbord(), 'elevenlabs n’est pas dans la chaîne');
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
