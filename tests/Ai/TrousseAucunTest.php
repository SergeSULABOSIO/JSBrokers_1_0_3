<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;

/**
 * UN « MERCI » NE TRANSPORTE PLUS CINQUANTE-DEUX OUTILS — MAIS UN « OK » QUI VALIDE, SI.
 *
 * ── CE QUE CE TEST PROTÈGE ──────────────────────────────────────────────────────
 *
 * Un message sur trois n'appelle aucun outil, et beaucoup sont des acquiescements. Ils
 * emportaient les 64 Ko de déclarations de la trousse de lecture pour une réponse d'une
 * phrase. La trousse `AUCUN` les allège.
 *
 * Mais la MÊME phrase peut être tout autre chose. « Ok » après « voulez-vous que je
 * l'enregistre ? » est une VALIDATION : si elle partait sans outil d'écriture, le
 * courtier n'aurait jamais le bouton qu'il vient de demander, et Ket lui reproposerait
 * une troisième fois ce qu'elle lui a déjà offert deux.
 *
 * C'est pourquoi le déclencheur `acquiescement` se place APRÈS les six signaux
 * structurels. Sa place EST la garantie, et ces tests la verrouillent : ils échouent si
 * quelqu'un le remonte en tête « pour capter plus ».
 *
 * ── CE QUI N'EST PAS UN ACQUIESCEMENT, ET POURQUOI ──────────────────────────────
 *
 * « c'est fait ? » est une question — le courtier demande une vérification en base, elle
 * appelle une lecture. « essaie encore », « la suivante », « vas y » relancent une
 * demande précédente : le corpus réel les montre sous rechercher_entites comme sous
 * suivi_impayes. Les traiter comme des acquiescements coûterait un tour de recours à
 * chaque relance.
 */
class TrousseAucunTest extends TestCase
{
    /**
     * @dataProvider acquiescementsPurs
     */
    public function testUnAcquiescementSeulNArmeAucunOutil(string $message): void
    {
        self::assertSame(
            Trousse::AUCUN,
            $this->selecteur()->trousseDe($this->requete($this->filDe($message))),
            sprintf('« %s » n\'appelle aucune donnée : la trousse doit rester vide.', $message),
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function acquiescementsPurs(): iterable
    {
        yield 'un ok sec' => ['Ok'];
        yield 'un merci' => ['Merci'];
        yield 'un merci appuyé' => ['Merci beaucoup !'];
        yield 'une approbation' => ['Très bien, merci.'];
        yield 'un accusé de réception' => ['Bien reçu'];
        yield 'deux formules enchaînées' => ['Ok, merci beaucoup.'];
        yield 'la casse et la ponctuation ne comptent pas' => ['PARFAIT !!!'];
    }

    /**
     * ⚠ LE CAS QUI PROUVE L'ORDRE DES DÉCLENCHEURS.
     *
     * Le même « Ok » que ci-dessus, mais Ket vient de proposer d'écrire. C'est une
     * confirmation, et elle doit armer l'écriture — sinon la validation part sans l'outil
     * qui la porte, et le bouton n'arrive jamais.
     */
    public function testUnOkQuiValideUneOffreDEcritureArmeLEcriture(): void
    {
        // L'offre PRÉCÈDE la réponse de l'utilisateur : c'est tout l'objet du cas.
        $conversation = $this->fil([
            ['assistant', 'Voulez-vous que je l\'enregistre ?'],
            ['user', 'Ok'],
        ]);

        self::assertSame(
            Trousse::ECRITURE,
            $this->selecteur()->trousseDe($this->requete($conversation)),
            'Un « ok » qui répond à une proposition d\'écrire est une VALIDATION, pas un acquiescement.',
        );
    }

    /**
     * Même démonstration par l'autre bout : une saisie engagée au tour précédent.
     */
    public function testUnOkApresUnTourQuiAEcritArmeLEcriture(): void
    {
        $conversation = $this->fil([
            ['assistant', 'C\'est enregistré.', ['tool' => 'preparer_operations']],
            ['user', 'Ok'],
        ]);

        self::assertSame(
            Trousse::ECRITURE,
            $this->selecteur(outilsEcriture: ['preparer_operations'])->trousseDe($this->requete($conversation)),
            'Une saisie engagée se poursuit : le même « ok » y est une confirmation.',
        );
    }

    /**
     * @dataProvider relancesEtQuestions
     */
    public function testUneRelanceOuUneQuestionGardeSaTrousse(string $message): void
    {
        self::assertNotSame(
            Trousse::AUCUN,
            $this->selecteur()->trousseDe($this->requete($this->filDe($message))),
            sprintf(
                '« %s » n\'est pas un acquiescement : le traiter comme tel coûterait un tour de recours.',
                $message,
            ),
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function relancesEtQuestions(): iterable
    {
        // Relevés dans le corpus réel, sous plusieurs outils différents.
        yield 'une relance' => ['Essaie encore'];
        yield 'une relance polie' => ['Essaie encore stp'];
        yield 'un feu vert sur une demande en cours' => ['Vas-y'];
        yield 'le suivant d\'une série' => ['La suivante'];
        yield 'une vérification en base' => ['C\'est fait ?'];
        yield 'une relance de réponse' => ['Réponds stp'];
        yield 'un acquiescement SUIVI d\'une demande' => ['Ok, et maintenant la liste des clients.'];
        yield 'un merci suivi d\'une question' => ['Merci. Combien de polices échues ?'];
    }

    /**
     * LA TROUSSE VIDE N'EST PAS VIDE : elle porte la porte de sortie, et elle seule.
     *
     * Sans cet outil, un aiguillage trop serré produirait « je ne peux pas » sur une
     * demande légitime — la seule erreur que ce lot ne peut pas se permettre.
     */
    public function testLaTrousseAucunNeDeclareQueLaPorteDeSortie(): void
    {
        $catalogue = new TrousseCatalogue([
            new \App\Ai\Tool\RecoursOutilsTool(),
        ]);

        self::assertSame(
            ['reprendre_mes_outils'],
            $catalogue->nomsDe(Trousse::AUCUN, new AiScope(new Entreprise(), new Invite())),
        );
    }

    // ── Échafaudage, calqué sur SelecteurDeTrousseTest ──────────────────────────

    /** @param list<string> $outilsEcriture */
    private function selecteur(array $outilsEcriture = []): SelecteurDeTrousse
    {
        // Même échafaudage que SelecteurDeTrousseTest : un dépôt de programmes doublé,
        // et un VRAI TrousseCatalogue, qui est final — ce qui éprouve du même coup le
        // marqueur d'écriture porté par les doublures d'outils.
        $depot = $this->createMock(\App\Repository\AssistantProgrammeRepository::class);
        $depot->method('courantDe')->willReturn(null);

        $outils = array_map(fn (string $nom) => $this->outilDEcriture($nom), $outilsEcriture);

        return new SelecteurDeTrousse(
            new ProgrammeEnCours($depot, $this->createMock(\Doctrine\ORM\EntityManagerInterface::class)),
            new TrousseCatalogue($outils),
        );
    }

    private function outilDEcriture(string $nom): object
    {
        return new class($nom) implements \App\Ai\Tool\AiToolInterface, \App\Ai\Trousse\AiToolEcriture {
            public function __construct(private readonly string $nom)
            {
            }

            public function name(): string
            {
                return $this->nom;
            }

            public function description(): string
            {
                return 'Outil de test.';
            }

            public function aiguillage(): string
            {
                return 'jamais, c\'est un test.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return null;
            }

            public function execute(array $args, AiScope $scope): \App\Ai\Tool\AiToolResult
            {
                return \App\Ai\Tool\AiToolResult::ok([]);
            }
        };
    }

    private function filDe(string $message): AssistantConversation
    {
        return $this->fil([['user', $message]]);
    }

    /** @param list<array{0: string, 1: string, 2?: array<string, mixed>}> $tours */
    private function fil(array $tours): AssistantConversation
    {
        $conversation = new AssistantConversation();
        foreach ($tours as $tour) {
            $message = (new AssistantMessage())
                ->setRole($tour[0] === 'user' ? AssistantMessage::ROLE_USER : AssistantMessage::ROLE_ASSISTANT)
                ->setContenu($tour[1]);
            if (isset($tour[2])) {
                $message->setMeta($tour[2]);
            }
            $conversation->addMessage($message);
        }

        return $conversation;
    }

    private function requete(AssistantConversation $conversation): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Test', 'perimetre' => [], 'date' => '2026-09-26'],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite(), $conversation),
        );
    }
}
