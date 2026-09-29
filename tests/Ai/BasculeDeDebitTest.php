<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Engine\Socle\DialecteGeminiDuFil;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FUIR UNE PANNE ET FUIR UNE MINUTE PLEINE NE SONT PAS LE MÊME GESTE.
 *
 * ── LA CONTRADICTION DU 2026-09-28, ET CE QU'ELLE A COÛTÉ ───────────────────────
 *
 * Le courtier lisait dans le chat : « Mon moteur a atteint sa limite de débit pour la
 * minute en cours. Relancez-la dans 21 secondes. » Au même instant, la console des
 * fournisseurs affichait les trois modèles Gemini à 100 % de fenêtre libre, et
 * concluait « Peut répondre maintenant ». Les deux écrans disaient vrai. Aucun ne
 * décrivait la situation.
 *
 * Le journal du message l'explique en trois lignes :
 *
 *     repli   | abandonne=gemini-3.1-flash-lite    | motif=indisponible
 *     repli   | abandonne=gemini-flash-lite-latest | motif=indisponible
 *     message | modele=gemini-3.5-flash-lite | issue=budget_atteint | restant=0
 *
 * Le message fuit deux 503 passagers, arrive sur le troisième modèle, QUI RÉPOND. La
 * planification consomme alors toute sa fenêtre. Vient la rédaction : il n'y a plus
 * de place. La bascule de débit cherche un modèle disponible — et ne regardait que
 * les secours PAS ENCORE ESSAYÉS, liste vide à ce stade. Les deux modèles quittés,
 * dont la fenêtre était entièrement libre, étaient hors de sa vue.
 *
 * ── POURQUOI LA RÈGLE « ON NE REVIENT JAMAIS EN ARRIÈRE » ÉTAIT JUSTE, ET
 *    POURQUOI ELLE NE S'APPLIQUE PAS ICI ────────────────────────────────────────
 *
 * Elle protège d'un vrai gâchis : rejouer un modèle qui vient de rendre un 503, c'est
 * repayer un échec certain à chaque tour. Mais elle répond à la question « qui est en
 * panne ? », alors que la bascule de débit pose la question « qui a de la place ? ».
 * Un modèle quitté il y a cinq secondes n'a rien consommé depuis : c'est le seul à
 * avoir de la place, précisément parce qu'il n'a pas répondu.
 *
 * Au pire on paie un aller-retour réseau. L'alternative était d'annoncer une demi-minute
 * d'attente à un utilisateur qui n'avait aucune raison d'attendre.
 */
class BasculeDeDebitTest extends TestCase
{
    private const PRINCIPAL = 'modele-un';
    private const REPLIS = 'modele-deux,modele-trois';

    /** @return list<string> */
    private static function chaine(): array
    {
        return [self::PRINCIPAL, 'modele-deux', 'modele-trois'];
    }

    /** @param list<MockResponse> $reponses */
    private function dialecte(array $reponses = []): DialecteGeminiDuFil
    {
        return new DialecteGeminiDuFil(
            new MockHttpClient($reponses),
            static fn (): string => 'PROMPT',
            new DialecteGemini(new TrousseCatalogue([])),
            'gm-test',
            static fn (): string => self::PRINCIPAL,
            static fn (): string => self::REPLIS,
            new NullLogger(),
            4096,
            15,
        );
    }

    private function requete(): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Ket',
                'entrepriseNom' => 'Courtage Test',
                'perimetre'     => [],
                'date'          => '2026-09-28',
            ],
            messages: [['role' => 'user', 'content' => 'combien de clients ?']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /**
     * ⚠ LE TEST QUI MANQUAIT. Aucun n'exerçait cette bascule : c'est pourquoi une suite
     * de 3 410 tests est restée verte pendant que Ket faisait patienter le courtier
     * devant deux modèles libres.
     */
    public function testUnModeleDejaQuitteRedevientCandidatQuandSaFenetreEstLibre(): void
    {
        // Les trois modèles refusent : c'est la journée du 2026-09-28, telle que le
        // fournisseur l'a vécue. La bascule d'ERREUR vide alors la liste des secours.
        $surcharge = static fn (): MockResponse => new MockResponse(
            '{"error":{"code":503,"status":"UNAVAILABLE","message":"This model is currently experiencing high demand."}}',
            ['http_code' => 503],
        );
        $dialecte = $this->dialecte([$surcharge(), $surcharge(), $surcharge()]);
        $requete = $this->requete();

        try {
            $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::PLANIFICATION);
            self::fail('Les trois modèles refusent : l’appel doit échouer.');
        } catch (\Throwable) {
            // Attendu : plus aucun secours à essayer.
        }

        self::assertSame(
            'modele-trois',
            $dialecte->modeleCourant(),
            'Les deux secours ont été consommés par la bascule d’erreur.'
        );

        // Le modèle courant a répondu et consommé sa minute ; le PREMIER, quitté en
        // premier, n'a rien consommé depuis — sa fenêtre est intacte.
        $retenu = $dialecte->basculerFauteDeDebit(
            static fn (string $m): bool => $m === self::PRINCIPAL,
            $requete,
            Phase::REDACTION,
        );

        self::assertSame(
            self::PRINCIPAL,
            $retenu,
            'Le modèle quitté pour une panne a une minute libre : il doit redevenir candidat pour un problème de débit.'
        );
        self::assertSame(self::PRINCIPAL, $dialecte->modeleCourant());
    }

    /**
     * L'OSCILLATION EST BORNÉE. Deux modèles qui se remplissent à tour de rôle ne
     * doivent pas se renvoyer la balle jusqu'à épuisement des tours : chaque modèle
     * n'est retenu qu'une fois par ce chemin, dans un message.
     */
    public function testUnModeleNEstRetenuQuUneFoisParLaBasculeDeDebit(): void
    {
        $dialecte = $this->dialecte();
        $requete = $this->requete();
        $toujours = static fn (): bool => true;

        $retenus = [];
        for ($i = 0; $i < 6; ++$i) {
            $choix = $dialecte->basculerFauteDeDebit($toujours, $requete, Phase::REDACTION);
            if ($choix === null) {
                break;
            }
            $retenus[] = $choix;
        }

        self::assertCount(
            2,
            $retenus,
            'Trois modèles, un déjà courant : au plus deux bascules de débit, puis plus rien.'
        );
        self::assertSame($retenus, array_unique($retenus), 'Aucun modèle ne doit être retenu deux fois.');
    }

    /** Aucun modèle n'a de place : la bascule le dit, elle n'en invente pas un. */
    public function testSansPlaceNullePartLaBasculeRendNull(): void
    {
        self::assertNull(
            $this->dialecte()->basculerFauteDeDebit(
                static fn (): bool => false,
                $this->requete(),
                Phase::REDACTION,
            )
        );
    }

    /** Le modèle courant ne se choisit pas lui-même : c'est lui qui vient de manquer de place. */
    public function testLeModeleCourantNEstJamaisSonPropreRepli(): void
    {
        $dialecte = $this->dialecte();

        $retenu = $dialecte->basculerFauteDeDebit(
            static fn (string $m): bool => $m === self::PRINCIPAL,
            $this->requete(),
            Phase::REDACTION,
        );

        self::assertNull($retenu, 'Seul le modèle courant a de la place : il n’y a pas de bascule à faire.');
        self::assertSame(self::PRINCIPAL, $dialecte->modeleCourant());
    }
}
