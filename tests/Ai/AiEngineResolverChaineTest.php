<?php

namespace App\Tests\Ai;

use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Engine\AiEngineResolver;
use App\Ai\Engine\MoteurDeTexte;
use App\Ai\Engine\SimulatedAiEngine;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;

/**
 * LA CHAÎNE DES MOTEURS DE TEXTE.
 *
 * Le choix du moteur était un `switch` sur trois classes injectées nommément,
 * là où la voix et les oreilles de Ket sont depuis toujours des chaînes taggées
 * ordonnées par une liste. Ce fichier verrouille la règle commune : l'ORDRE dit
 * la préférence, la DISPONIBILITÉ tranche, `AI_ENGINE` prime sur les deux.
 *
 * Aucun vrai moteur ici : ce qu'on vérifie est la logique de sélection, pas la
 * capacité d'un adaptateur à parler à son fournisseur. Des doublures de trois
 * lignes rendent le test lisible — et rapide.
 */
class AiEngineResolverChaineTest extends TestCase
{
    private function moteur(string $nom, bool $disponible, bool $epuise = false): MoteurDeTexte
    {
        return new class($nom, $disponible, $epuise) implements MoteurDeTexte {
            public int $appels = 0;

            public function __construct(private string $leNom, private bool $disponible, private bool $epuise)
            {
            }

            public function name(): string
            {
                return $this->leNom;
            }

            public function nom(): string
            {
                return $this->leNom;
            }

            public function estDisponible(): bool
            {
                return $this->disponible;
            }

            public function estEpuise(): bool
            {
                return $this->epuise;
            }

            public function modelName(): string
            {
                return $this->leNom . '-modele';
            }

            public function reply(AiRequest $request): AiReply
            {
                ++$this->appels;

                return new AiReply('réponse de ' . $this->leNom);
            }
        };
    }

    private function requete(): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'X', 'perimetre' => [], 'date' => '2026-09-22'],
            messages: [['role' => 'user', 'content' => 'bonjour']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /**
     * LA RÈGLE PAR DÉFAUT, INCHANGÉE. Clé Anthropic d'abord, clé Gemini ensuite,
     * simulé sinon : le passage du `switch` à la chaîne ne devait rien changer au
     * comportement, et c'est ce que ces trois cas vérifient.
     */
    public function testLOrdreDecideEtLaCleTranche(): void
    {
        $cas = [
            'aucune clé'        => [false, false, 'simulated'],
            'clé Gemini seule'  => [false, true, 'gemini'],
            'les deux clés'     => [true, true, 'anthropic'],
            'clé Anthropic seule' => [true, false, 'anthropic'],
        ];

        foreach ($cas as $libelle => [$anthropic, $gemini, $attendu]) {
            $resolveur = new AiEngineResolver(
                [
                    $this->moteur('anthropic', $anthropic),
                    $this->moteur('gemini', $gemini),
                    new SimulatedAiEngine([]),
                ],
                'anthropic,gemini,simulated',
            );

            $this->assertSame($attendu, $resolveur->name(), $libelle);
        }
    }

    /**
     * UN NOM ABSENT DE LA LISTE N'EST JAMAIS APPELÉ. C'est ainsi qu'on coupe un
     * fournisseur sans toucher au code — et c'est la moitié de ce que l'écran de
     * console pilotera.
     */
    public function testUnMoteurAbsentDeLOrdreNEstJamaisAppele(): void
    {
        $anthropic = $this->moteur('anthropic', true);
        $gemini = $this->moteur('gemini', true);

        $resolveur = new AiEngineResolver([$anthropic, $gemini, new SimulatedAiEngine([])], 'gemini,simulated');
        $resolveur->reply($this->requete());

        $this->assertSame(0, $anthropic->appels, 'Écarté de la liste : il ne doit pas voir passer un seul message.');
        $this->assertSame(1, $gemini->appels);
    }

    /**
     * UN FOURNISSEUR À SEC N'EST PLUS INTERROGÉ — le cœur de la mécanique.
     *
     * Une clé posée ne suffit pas : encore faut-il qu'il reste du solde. Quand le
     * fournisseur s'est lui-même déclaré à sec — quota du jour, crédits du mois,
     * plafond de dépense —, la chaîne passe au suivant SANS lui parler. C'est
     * toute la différence entre payer une attente pour un refus connu d'avance et
     * aller droit à celui qui peut répondre.
     */
    public function testUnFournisseurASecEstEcarteSansEtreInterroge(): void
    {
        $aSec = $this->moteur('anthropic', disponible: true, epuise: true);
        $gemini = $this->moteur('gemini', disponible: true);

        $resolveur = new AiEngineResolver([$aSec, $gemini, new SimulatedAiEngine([])], 'anthropic,gemini,simulated');
        $reply = $resolveur->reply($this->requete());

        $this->assertSame(0, $aSec->appels, 'Un fournisseur à sec ne doit pas voir passer un seul message.');
        $this->assertSame(1, $gemini->appels);
        $this->assertSame('réponse de gemini', $reply->content);
    }

    /**
     * Toute la chaîne à sec : le simulé répond quand même. Une plateforme sans
     * quota restant doit rendre une réponse déterministe, jamais une page blanche.
     */
    public function testToutALaSecRetombeSurLeSimule(): void
    {
        $resolveur = new AiEngineResolver(
            [
                $this->moteur('anthropic', disponible: true, epuise: true),
                $this->moteur('gemini', disponible: true, epuise: true),
                new SimulatedAiEngine([]),
            ],
            'anthropic,gemini,simulated',
        );

        $this->assertSame('simulated', $resolveur->name());
    }

    /** L'ordre est une PRÉFÉRENCE, pas un classement figé : on peut l'inverser. */
    public function testLOrdreSeRenverse(): void
    {
        $resolveur = new AiEngineResolver(
            [$this->moteur('anthropic', true), $this->moteur('gemini', true), new SimulatedAiEngine([])],
            'gemini,anthropic,simulated',
        );

        $this->assertSame('gemini', $resolveur->name());
    }

    /**
     * `AI_ENGINE` PRIME SUR TOUT — c'est la garde de `.env.test`, qui empêche les
     * tests d'appeler une API réelle même quand une clé traîne en variable
     * d'environnement du poste. Un moteur forcé est pris MÊME SANS CLÉ : c'est une
     * décision d'exploitation, et la masquer rendrait le réglage incompréhensible.
     */
    public function testLeForcageSurclasseLOrdreEtLesCles(): void
    {
        $moteurs = [$this->moteur('anthropic', true), $this->moteur('gemini', false), new SimulatedAiEngine([])];

        $this->assertSame('simulated', (new AiEngineResolver($moteurs, 'anthropic,gemini,simulated', 'simulated'))->name());
        $this->assertSame('gemini', (new AiEngineResolver($moteurs, 'anthropic,gemini,simulated', 'gemini'))->name(),
            'Forcé sans clé : on obéit, au lieu de retomber silencieusement sur un autre.');
    }

    /**
     * Une liste qui ne nomme rien de connu ne doit pas laisser Ket sans moteur.
     * Une mauvaise configuration mérite un repli déterministe, pas une panne.
     */
    public function testUneListeVideRetombeSurLeSimule(): void
    {
        $resolveur = new AiEngineResolver(
            [$this->moteur('anthropic', true), new SimulatedAiEngine([])],
            'fournisseur-inexistant',
        );

        $this->assertSame('simulated', $resolveur->name());
    }
}
