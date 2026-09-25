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

        // ── LES PIÈGES DE SOUS-CHAÎNE, MESURÉS LE 2026-09-23 ────────────────────
        //
        // Ces dix questions sont de pures consultations, et toutes armaient la
        // trousse d'ÉCRITURE : le filet lexical lisait un verbe d'action à
        // l'INTÉRIEUR d'un mot du métier. « commission » contient « mission »,
        // « crédit » contient « édit », « échange » contient « change »,
        // « traitement » contient « traite », « accordée » contient « accord »,
        // « remarque » contient « marque ».
        //
        // Sur 241 messages journalisés, 202 (84 %) partaient ainsi en écriture sans
        // jamais écrire, pour 27,7 % des jetons d'entrée de la période. Le mot
        // « commission » étant le plus fréquent du courtage, il pesait à lui seul
        // l'essentiel du gâchis.
        yield 'commission d’une police' => ['Quelle est la commission sur la police de Marlette ?'];
        yield 'commissions du mois' => ['Montre-moi les commissions du mois'];
        yield 'total des commissions' => ['Quel est le total des commissions perçues cette année ?'];
        yield 'rétrocommissions dues' => ['Quelles sont les retrocommissions dues à Olea ?'];
        yield 'un crédit' => ['Quel est le crédit restant sur ce compte ?'];
        yield 'un échange' => ['Montre l’échange avec SUNU'];
        yield 'des changements' => ['Y a-t-il des changements sur ce dossier ?'];
        yield 'un traitement fiscal' => ['Quel est le traitement fiscal de cette prime ?'];
        yield 'une commission accordée' => ['Quelle commission m’a été accordée sur ce contrat ?'];
        yield 'une remarque' => ['Je remarque une erreur dans ce tableau'];
    }

    /**
     * CHAQUE AIGUILLAGE DIT CE QUI L'A DÉCIDÉ — sans quoi on ne peut pas le resserrer.
     *
     * La trousse d'ÉCRITURE coûte cinquante-deux déclarations d'outils au lieu de
     * trente-trois, plus vingt-sept kilo-octets de protocoles : plus de la moitié du
     * payload d'un tour. Six déclencheurs peuvent la réclamer, et le journal ne disait
     * pas lequel. Resserrer sans cela, c'est parier.
     *
     * Le nom du déclencheur part désormais dans `assistant_tokens*.log`, à côté de
     * « une écriture a-t-elle eu lieu » — les deux moitiés du diagnostic.
     */
    public function testChaqueAiguillageNommeSonDeclencheur(): void
    {
        $selecteur = $this->selecteur();

        // Avant tout aiguillage, rien à raconter.
        self::assertSame('aucun', $selecteur->dernierDeclencheur());

        // Une consultation : aucun déclencheur d'écriture ne s'est armé.
        $selecteur->trousseDe($this->requete($this->bulles(['combien de clients ?'])));
        self::assertSame('aucun', $selecteur->dernierDeclencheur());

        // Une demande de saisie : c'est le filet lexical qui a tranché, et il le dit.
        $selecteur->trousseDe($this->requete($this->bulles(['enregistre ce paiement'])));
        self::assertSame('verbe-action', $selecteur->dernierDeclencheur());
    }

    /**
     * ET IL DIT AUSSI QUEL MOT A MORDU — le nom du signal ne suffisait pas.
     *
     * Mesuré au 2026-09-25 sur les trente messages qui portaient déjà le déclencheur :
     * `verbe-action` arme l'écriture vingt fois sur vingt et une, et UNE SEULE de ces
     * vingt écrit. Le coupable est donc la liste de verbes — mais elle compte des
     * dizaines d'alternatives, et savoir seulement que « l'une d'elles » a mordu ne
     * permet d'en retirer aucune. Le mot capturé, lui, se compte et se juge.
     */
    public function testLAiguillageNommeLeMotQuiAArmeLEcriture(): void
    {
        $selecteur = $this->selecteur();

        // Aucun armement lexical : rien à nommer.
        $selecteur->trousseDe($this->requete($this->bulles(['combien de clients ?'])));
        self::assertSame('', $selecteur->dernierMotArmeur());

        $selecteur->trousseDe($this->requete($this->bulles(['enregistre ce paiement'])));
        self::assertSame('enregistr', $selecteur->dernierMotArmeur());

        // Le vocabulaire du métier arme aussi, et se nomme comme le reste : c'est
        // précisément ce genre d'alternative qu'on voudra peut-être retirer.
        $selecteur->trousseDe($this->requete($this->bulles(['j\'ai une offre venant de SFA'])));
        self::assertSame('offre', $selecteur->dernierMotArmeur());

        // ⚠ ET IL SE REMET À VIDE quand ce n'est plus lui qui décide : un mot laissé
        // là par l'aiguillage précédent ferait accuser un innocent dans le journal.
        $selecteur->trousseDe($this->requete($this->bulles(['combien de clients ?'])));
        self::assertSame('', $selecteur->dernierMotArmeur());
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
        // INCIDENT DU 2026-08-10. La tournure exacte « ouvre le formulaire » était
        // couverte ; ces deux-ci ne l'étaient pas d'un cheveu. Résultat : trousse de
        // lecture, ouvrir_dialogue absent, et Ket a ouvert la RUBRIQUE des partenaires
        // — une liste, là où l'utilisateur demandait un formulaire.
        yield 'un formulaire demandé de biais' => ['Ouvre moi par exemple le formulaire d’édition pour Olea'];
        yield 'un formulaire par pronom' => ['ouvre son formulaire d’édition'];

        // ── LE REVERS DE L'ANCRAGE À DROITE ─────────────────────────────────────
        //
        // « chang » et « trait » sont désormais fermés à droite, pour que
        // « changement » et « traitement » cessent d'armer l'écriture. Ces cas
        // vérifient que les formes VERBALES, elles, passent toujours : c'est
        // exactement ce qu'un ancrage trop zélé casserait, et un faux négatif coûte
        // bien plus cher qu'un faux positif — il prive l'utilisateur d'une capacité.
        yield 'changer un montant' => ['change le montant de la prime'];
        yield 'changer à l’infinitif' => ['changer le bénéficiaire de cette police'];
        yield 'traiter un dossier' => ['traite ce dossier'];
        yield 'traiter à l’infinitif' => ['peux-tu traiter ce sinistre ?'];
        yield 'un accord donné' => ['je suis d’accord, fais-le'];
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

    /**
     * ⚠ KET NE S'ARME PLUS ELLE-MÊME — le défaut le plus cher de l'aiguillage.
     *
     * Le filet lexical relisait les trois derniers messages du fil TOUS RÔLES
     * CONFONDUS, donc les réponses de Ket. Or le prompt lui ORDONNE de parler
     * d'enregistrer, de modifier, d'ouvrir un formulaire — sans quoi elle répondrait
     * « je ne peux pas », ce qui est faux. Elle armait donc sa propre trousse en
     * parlant, et le gardait trois tours.
     *
     * MESURÉ le 2026-09-25 sur les 39 conversations réelles (821 tours) : 80,6 % des
     * tours armaient l'écriture, contre 53,8 % en ne lisant que l'utilisateur — 426
     * tours armés par la seule présence de Ket dans la fenêtre, pour un taux
     * d'écriture réellement constatée de 5 %.
     *
     * Le message de Ket ci-dessous porte « formulaire », une alternative de la liste,
     * mais AUCUNE de ses tournures d'offre : les deux signaux structurels restent
     * donc muets, et seule la fenêtre pouvait armer. C'est exactement le cas à fermer.
     */
    public function testLaProseDeKetNArmePlusLEcriture(): void
    {
        $conversation = new AssistantConversation();
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('combien de clients ?')
        );
        $conversation->addMessage(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu('Vous avez 12 clients. Le formulaire d’édition est accessible depuis chaque fiche.')
        );
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('et combien de polices ?')
        );

        $this->assertSame(
            Trousse::LECTURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles(['et combien de polices ?']), $conversation)),
            'Deux consultations encadrant une phrase de Ket ne sont pas une demande d’écriture.',
        );
    }

    /**
     * ET L'UTILISATEUR, LUI, EST TOUJOURS LU SUR TROIS MESSAGES.
     *
     * Ne garder que son dernier message descendrait l'armement à 28,7 %, mais
     * retirerait une capacité : une saisie s'étale, et la réponse à une question
     * (« le taux est de 15 % ») ne contient aucun verbe. Les signaux structurels la
     * rattrapent souvent, pas toujours — Ket peut poser une question sans appeler
     * d'outil ni employer l'une de ses tournures d'offre. Un faux négatif prive
     * l'utilisateur d'une capacité ; un faux positif ne coûte que des jetons.
     */
    public function testUneSaisieEtaleeResteArmeeParLesMessagesDeLUtilisateur(): void
    {
        $conversation = new AssistantConversation();
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('Enregistre la proposition de SUNU.')
        );
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_ASSISTANT)->setContenu('Il me manque le taux.')
        );
        $conversation->addMessage(
            (new AssistantMessage())->setRole(AssistantMessage::ROLE_USER)->setContenu('le taux est de 15%')
        );

        $this->assertSame(
            Trousse::ECRITURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles(['le taux est de 15%']), $conversation)),
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

    /**
     * LA BOUCLE FERMÉE, corrigée le 2026-08-11 — le seul cas où l'aiguillage pouvait
     * s'enfermer sur lui-même.
     *
     * En trousse de lecture, le prompt ORDONNE à Ket de proposer l'écriture
     * (« préférez-vous que je m'en charge ? »), faute de quoi elle répondrait « je ne
     * peux pas », ce qui est faux. Mais la réponse à cette question est un simple
     * « oui », « allez-y », « option A » : aucun verbe d'action, aucun vocabulaire
     * métier. Sans ce signal, la lecture repart, Ket propose une deuxième fois, et
     * l'utilisateur tourne en rond sur une capacité qu'on venait de lui promettre.
     *
     * On reconnaît ici NOS propres tournures, pas l'intention de l'utilisateur :
     * c'est ce qui rend le test fiable.
     *
     * @dataProvider reponsesAUneOffreDEcriture
     */
    public function testUneOffreDEcritureAuTourPrecedentOuvreLEcriture(string $reponse): void
    {
        $conversation = new AssistantConversation();
        $conversation->addMessage(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                // Prose RÉELLE de l'incident : Ket vient d'exposer les procédures A/B.
                ->setContenu(
                    'Pour enregistrer une dépense, deux procédures s’offrent à vous : (A) je m’en charge '
                    . 'entièrement, ou (B) je vous ouvre le formulaire. Préférez-vous que je m’en charge ?'
                )
                // Aucun outil n'a tourné : le signal « le dernier tour a écrit » ne
                // peut rien voir ici. C'est bien la PROSE qui engage Ket.
                ->setMeta(['engine' => 'gemini'])
        );

        $this->assertSame(
            Trousse::ECRITURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles([$reponse]), $conversation)),
            'Une offre d’écriture faite au tour précédent doit pouvoir être tenue au tour suivant.',
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function reponsesAUneOffreDEcriture(): iterable
    {
        yield 'la réponse de l’incident' => ['Je veux que tu t’en charge'];
        yield 'un acquiescement nu' => ['oui'];
        yield 'un choix de procédure' => ['A'];
        yield 'une invitation brève' => ['allez-y'];
    }

    /** Sans offre d'écriture au tour précédent, un « oui » nu reste une consultation. */
    public function testUnAcquiescementSansOffreResteEnLecture(): void
    {
        $conversation = new AssistantConversation();
        $conversation->addMessage(
            (new AssistantMessage())
                ->setRole(AssistantMessage::ROLE_ASSISTANT)
                ->setContenu('Votre portefeuille compte 42 clients.')
                ->setMeta(['engine' => 'gemini'])
        );

        $this->assertSame(
            Trousse::LECTURE,
            $this->selecteur()->trousseDe($this->requete($this->bulles(['oui']), $conversation)),
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
