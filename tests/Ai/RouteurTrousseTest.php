<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Routage\CatalogueCondense;
use App\Ai\Routage\RouteurModele;
use App\Ai\Routage\RouteurTrousse;
use App\Ai\Scope\AiScope;
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
use Psr\Log\NullLogger;

/**
 * L'AIGUILLEUR. Trois propriétés, et elles comptent toutes les trois.
 *
 * 1. Il ne paie pas ce qui est certain : un plan en attente ou un programme en cours
 *    valent écriture sans qu'on appelle personne.
 * 2. Il comprend, là où une règle lexicale échoue : rejouée hors ligne sur les
 *    31 messages journalisés, la meilleure règle déterministe atteignait 58 %
 *    d'exactitude contre 48 % pour « tout déclarer toujours ».
 * 3. Il échoue OUVERT : panne, indécision ou quota rendent la trousse complète.
 *    Se tromper vers l'écriture coûte un tour lourd ; se tromper vers la lecture
 *    priverait l'utilisateur d'une capacité, ce qui coûte bien plus cher.
 */
class RouteurTrousseTest extends TestCase
{
    /** @var list<array{instruction: string, catalogue: string, messages: array}> */
    private array $appels = [];

    private function modele(?string $reponse, int $tokens = 900): RouteurModele
    {
        return new class($reponse, $tokens, $this->appels) implements RouteurModele {
            public function __construct(
                private ?string $reponse,
                private int $tokens,
                private array &$appels,
            ) {
            }

            public function choisirTrousse(string $instruction, string $catalogue, array $messages): array
            {
                $this->appels[] = compact('instruction', 'catalogue', 'messages');

                return ['trousse' => $this->reponse, 'tokens' => $this->tokens];
            }
        };
    }

    private function routeur(RouteurModele $modele, ?AssistantProgramme $programme = null): RouteurTrousse
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $repository = $this->createMock(AssistantProgrammeRepository::class);
        $repository->method('courantDe')->willReturn($programme);

        return new RouteurTrousse(
            $modele,
            new CatalogueCondense(new TrousseCatalogue([])),
            new PlanEnAttente($em),
            new ProgrammeEnCours($repository, $em),
            new NullLogger(),
        );
    }

    private function requete(?AssistantConversation $conversation, array $messages = []): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Test', 'perimetre' => [], 'date' => '2026-08-10'],
            messages: $messages ?: [['role' => 'user', 'content' => 'Combien de clients ?']],
            scope: new AiScope(new Entreprise(), new Invite(), $conversation),
        );
    }

    /** Une conversation PERSISTÉE portant un plan encore en attente de décision. */
    private function conversationAvecPlanEnAttente(): AssistantConversation
    {
        $conversation = new AssistantConversation();
        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan.')
            ->setMeta(['mutationPlan' => ['operations' => [['op' => 'create', 'entite' => 'Client']]]]);
        $conversation->addMessage($message);

        return $conversation;
    }

    public function testUneDemandeDeConsultationVaEnLecture(): void
    {
        $decision = $this->routeur($this->modele('lecture'))->router($this->requete(null));

        $this->assertSame(Trousse::LECTURE, $decision['trousse']);
        $this->assertSame('modele', $decision['origine']);
        $this->assertSame(900, $decision['tokens']);
    }

    public function testUneDemandeDeSaisieVaEnEcriture(): void
    {
        $decision = $this->routeur($this->modele('ecriture'))->router($this->requete(null));

        $this->assertSame(Trousse::ECRITURE, $decision['trousse']);
        $this->assertSame('modele', $decision['origine']);
    }

    /** Un plan attend une décision : la suite est forcément une écriture, on ne paie pas pour le savoir. */
    public function testUnPlanEnAttenteCourtCircuiteLAppel(): void
    {
        $routeur = $this->routeur($this->modele('lecture'));

        $decision = $routeur->router($this->requete($this->conversationAvecPlanEnAttente()));

        $this->assertSame(Trousse::ECRITURE, $decision['trousse']);
        $this->assertSame('plan_en_attente', $decision['origine']);
        $this->assertSame(0, $decision['tokens'], 'Un court-circuit ne doit rien coûter.');
        $this->assertSame([], $this->appels, 'Le modèle ne doit pas être sollicité.');
    }

    /** Idem pour une série en cours : chaque étape est une écriture. */
    public function testUnProgrammeEnCoursCourtCircuiteLAppel(): void
    {
        $conversation = new AssistantConversation();
        // Un programme n'est rattaché qu'à une conversation persistée ; on simule
        // l'identifiant, sans quoi ProgrammeEnCours rend la main avant la requête.
        (new \ReflectionProperty(AssistantConversation::class, 'id'))->setValue($conversation, 77);

        $routeur = $this->routeur($this->modele('lecture'), new AssistantProgramme());
        $decision = $routeur->router($this->requete($conversation));

        $this->assertSame(Trousse::ECRITURE, $decision['trousse']);
        $this->assertSame('programme', $decision['origine']);
        $this->assertSame([], $this->appels);
    }

    /** Le fournisseur est muet ou en panne : trousse complète, jamais un refus. */
    public function testUnRoutageIndecisRetombeSurLaTrousseComplete(): void
    {
        $decision = $this->routeur($this->modele(null, 0))->router($this->requete(null));

        $this->assertSame(Trousse::ECRITURE, $decision['trousse']);
        $this->assertSame('repli', $decision['origine']);
    }

    /** Une réponse fantaisiste ne doit pas devenir une trousse inventée. */
    public function testUneReponseInvalideRetombeSurLaTrousseComplete(): void
    {
        $decision = $this->routeur($this->modele('bordereaux'))->router($this->requete(null));

        $this->assertSame(Trousse::ECRITURE, $decision['trousse']);
        $this->assertSame('repli', $decision['origine']);
    }

    /**
     * Le routeur voit la FIN du fil, pas seulement la dernière bulle : c'est ce qui
     * lui permet de lire « vas y » comme la poursuite d'une saisie — précisément ce
     * qu'une règle lexicale ne sait pas faire.
     */
    public function testLeRouteurVoitLaFinDuFilEtNonLaSeuleDerniereBulle(): void
    {
        $messages = [];
        for ($i = 1; $i <= 8; $i++) {
            $messages[] = ['role' => 'user', 'content' => 'message ' . $i];
        }
        $messages[] = ['role' => 'user', 'content' => 'vas y'];

        $this->routeur($this->modele('ecriture'))->router($this->requete(null, $messages));

        $soumis = $this->appels[0]['messages'];
        $this->assertCount(3, $soumis, 'Trois messages : assez pour lire une continuation, pas assez pour peser.');
        $this->assertSame('vas y', end($soumis)['content']);
    }

    /** L'aiguilleur reçoit le catalogue et une consigne qui lui interdit de répondre. */
    public function testLeRouteurRecoitUneConsigneDAiguillageEtLeCatalogue(): void
    {
        $this->routeur($this->modele('lecture'))->router($this->requete(null));

        $this->assertStringContainsString('AIGUILLEUR', $this->appels[0]['instruction']);
        $this->assertStringContainsString('Dans le doute', $this->appels[0]['instruction']);
        $this->assertStringContainsString('OUTILS DE LECTURE', $this->appels[0]['catalogue']);
    }
}
