<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\AssistantProgramme;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\AssistantProgrammeRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Le choix de la TROUSSE, décidé par le serveur sans rien demander au modèle —
 * l'interroger coûterait un troisième appel, que la règle interdit.
 *
 * L'ASYMÉTRIE QUI GOUVERNE CE CODE. Ouvrir l'écriture pour rien coûte des tokens
 * sur un seul appel. Ne pas l'ouvrir alors qu'il le fallait prive l'utilisateur
 * d'une capacité et lui fait entendre un « je ne peux pas » que le prompt interdit.
 * Les deux prix ne sont pas du même ordre : dans le doute, on élargit.
 *
 * Rejoué sur les 58 messages journalisés des 8-10 août : 0 faux négatif, au prix
 * de 43 % de faux positifs. C'est le compromis voulu, pas un accident.
 */
class SelecteurDeTrousseTest extends TestCase
{
    private function selecteur(?AssistantProgramme $programme = null, array $outilsEcriture = []): SelecteurDeTrousse
    {
        $repository = $this->createMock(AssistantProgrammeRepository::class);
        $repository->method('courantDe')->willReturn($programme);

        // TrousseCatalogue est final : on le CONSTRUIT avec de vraies doublures
        // d'outils, ce qui éprouve du même coup le marqueur AiToolEcriture.
        $outils = [];
        foreach ($outilsEcriture as $nom) {
            $outils[] = $this->outilDEcriture($nom);
        }

        return new SelecteurDeTrousse(
            new ProgrammeEnCours($repository, $this->createMock(EntityManagerInterface::class)),
            new TrousseCatalogue($outils),
        );
    }

    /** Doublure d'outil portant le marqueur d'écriture. */
    private function outilDEcriture(string $nom): AiToolInterface
    {
        return new class($nom) implements AiToolInterface, AiToolEcriture {
            public function __construct(private string $nom)
            {
            }

            public function name(): string
            {
                return $this->nom;
            }

            public function description(): string
            {
                return '';
            }

            public function aiguillage(): string
            {
                return '';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass()];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return null;
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                return AiToolResult::ok([]);
            }
        };
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function requete(array $messages, ?AssistantConversation $conversation = null): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Test', 'perimetre' => [], 'date' => '2026-08-10'],
            messages: $messages,
            scope: new AiScope(new Entreprise(), new Invite(), $conversation),
        );
    }

    /** @param list<string> $textes */
    private function bulles(array $textes): array
    {
        return array_map(static fn (string $t) => ['role' => 'user', 'content' => $t], $textes);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function demandesDeConsultation(): iterable
    {
        yield 'un compte' => ['Combien de clients avons-nous ?'];
        yield 'une ventilation' => ['Donne-moi le chiffre d’affaires par assureur.'];
        yield 'une explication' => ['Explique-moi ma trésorerie du mois dernier.'];
        yield 'une salutation' => ['salut'];
    }

    /**
     * @dataProvider demandesDeConsultation
     */
    public function testUneConsultationResteEnLecture(string $question): void
    {
        $this->assertSame(
            Trousse::LECTURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles([$question]))),
        );
    }

    /**
     * Les trois SEULS faux négatifs du corpus, corrigés par le vocabulaire métier.
     * Aucun ne contient de verbe d'action, et tous annoncent pourtant une saisie.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function demandesQuiAnnoncentUneSaisie(): iterable
    {
        yield 'une offre reçue' => ['j’ai une offre / cotation venant de SFA. Que faire?'];
        yield 'une mission à traiter' => ['réponds à cette mission'];
        yield 'un accord client' => ['Elle vient de confirmer son accord pour la proposition de SUNU.'];
        yield 'un ordre direct' => ['Enregistre-la.'];
        yield 'une continuation' => ['vas y'];
    }

    /**
     * @dataProvider demandesQuiAnnoncentUneSaisie
     */
    public function testUneDemandeDeSaisieOuvreLEcriture(string $question): void
    {
        $this->assertSame(
            Trousse::ECRITURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles([$question]))),
            'Ne pas ouvrir l’écriture ici priverait l’utilisateur d’une capacité.',
        );
    }

    /**
     * L'intention vit dans le FIL. « le taux est de 15 % » n'annonce rien seul ;
     * ce qui précède, si.
     */
    public function testLIntentionEstLueSurLesDerniersMessagesEtPasLaSeuleBulle(): void
    {
        $requete = $this->requete($this->bulles([
            'Enregistre la proposition de SUNU.',
            'Quel taux de commission appliquer ?',
            'le taux est de 15%',
        ]));

        $this->assertSame(Trousse::ECRITURE, $this->selecteur()->trousseDe($requete));
    }

    /**
     * SIGNAL STRUCTUREL : le tour précédent a écrit, la saisie se poursuit. Aucun
     * mot ne le dit — c'est la meta du message qui le sait.
     */
    public function testUneSaisieEngageeSePoursuitMemeSansVerbe(): void
    {
        $conversation = new AssistantConversation();
        $conversation->addMessage(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu('Quel est le montant ?')
                ->setMeta(['tool' => 'saisir_proposition'])
        );

        $requete = $this->requete($this->bulles(['1000']), $conversation);

        $this->assertSame(
            Trousse::ECRITURE,
            $this->selecteur(outilsEcriture: ['saisir_proposition'])->trousseDe($requete),
        );
        // Le même message, après un tour de LECTURE, n'ouvre rien.
        $this->assertSame(
            Trousse::LECTURE,
            $this->selecteur(outilsEcriture: [])->trousseDe($requete),
        );
    }

    /** Un plan attend une décision : la suite est forcément une écriture. */
    public function testUnPlanEnAttenteOuvreLEcriture(): void
    {
        $conversation = new AssistantConversation();
        $conversation->addMessage(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu('Voici le plan.')
                ->setMeta(['mutationPlan' => ['operations' => [['op' => 'create', 'entite' => 'Client']]]])
        );

        $this->assertSame(
            Trousse::ECRITURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles(['merci']), $conversation)),
        );
    }

    /** Une série en cours : chaque étape est une écriture. */
    public function testUnProgrammeEnCoursOuvreLEcriture(): void
    {
        $conversation = new AssistantConversation();
        (new \ReflectionProperty(AssistantConversation::class, 'id'))->setValue($conversation, 77);

        $this->assertSame(
            Trousse::ECRITURE,
            $this->selecteur(new AssistantProgramme())->trousseDe($this->requete($this->bulles(['ok']), $conversation)),
        );
    }
}
