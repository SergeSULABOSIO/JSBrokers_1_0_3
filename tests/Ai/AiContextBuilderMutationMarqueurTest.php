<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'historique transmis au moteur doit RÉVÉLER le sort réel d'un plan d'écriture :
 * un message assistant qui présentait un plan « cliquez sur Valider » mais dont la
 * meta indique qu'il a été EXÉCUTÉ (ou ANNULÉ) est annoté, sinon le moteur croit le
 * plan encore en attente et le re-prépare (ou nie à tort l'enregistrement).
 */
class AiContextBuilderMutationMarqueurTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ctxmut-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CtxMut SARL';

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{0:Entreprise,1:Invite} */
    private function seed(): array
    {
        $em = $this->em();
        $user = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('PHPUnit')->setVerified(true);
        $user->setPassword('x');
        $em->persist($user);
        $ent = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('L')->setAdresse('1 rue')->setTelephone('+243')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($user);
        $em->persist($ent);
        $inv = (new Invite())->setNom('Owner')->setUtilisateur($user)->setEntreprise($ent)->setProprietaire(true);
        $em->persist($inv);
        $em->flush();

        return [$ent, $inv];
    }

    private function build(Entreprise $ent, Invite $inv, AssistantConversation $conv): array
    {
        return static::getContainer()->get(AiContextBuilder::class)->build($ent, $inv, $conv)->messages;
    }

    private function conversationAvecPlan(Entreprise $ent, Invite $inv, array $meta): AssistantConversation
    {
        $conv = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $conv->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan préparé. Cliquez sur « Valider et exécuter ».')
            ->setMeta($meta));
        $this->em()->persist($conv);
        $this->em()->flush();

        return $conv;
    }

    public function testPlanExecuteEstAnnoteDansLHistorique(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, ['mutationPlan' => ['plan' => []], 'mutationPlanExecuted' => true]);

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringContainsString('VALIDÉ et EXÉCUTÉ', $messages[0]['content']);
    }

    public function testPlanAnnuleEstAnnoteDansLHistorique(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, ['mutationPlan' => ['plan' => []], 'mutationPlanCancelled' => true]);

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringContainsString('ANNULÉ', $messages[0]['content']);
    }

    public function testMessageAssistantOrdinaireNonAnnote(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, []); // pas de plan

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringNotContainsString('[SYSTÈME —', $messages[0]['content']);
    }

    public function testPlanEnAttenteEstAnnoteDansLHistorique(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, ['mutationPlan' => ['plan' => []]]);

        $messages = $this->build($ent, $inv, $conv);
        $this->assertStringContainsString('ATTEND ENCORE la décision', $messages[0]['content']);
        $this->assertStringContainsString('remplacerPlanEnAttente', $messages[0]['content']);
    }

    /** Un plan en attente, portant les valeurs déjà dictées par l'utilisateur. */
    private function planAvecValeurs(array $enPlus = []): array
    {
        return $enPlus + ['mutationPlan' => ['plan' => [[
            'op'     => 'create',
            'entite' => 'Contact',
            'fields' => ['nom' => 'Joëlle Fama Sona', 'telephone' => '+243828727706'],
        ]]]];
    }

    /**
     * CE QUE L'UTILISATEUR A DÉJÀ DONNÉ NE SE REDEMANDE PAS.
     *
     * Le plan en attente porte les valeurs dictées, mais le marqueur ne parlait que
     * de la barre et du remplacement : le modèle ne les voyait nulle part. À chaque
     * tour il recollectait donc le nom, puis le téléphone, puis l'e-mail — et le
     * 2026-09-14 le courtier a redonné trois fois les mêmes informations pour un
     * enregistrement qui n'a jamais eu lieu.
     */
    public function testUnPlanEnAttenteRappelleLesValeursDejaDictees(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, $this->planAvecValeurs());

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];

        $this->assertStringContainsString('Joëlle Fama Sona', $contenu, 'Les valeurs déjà dictées doivent '
            . 'être rappelées au modèle : sans elles, il les redemande à chaque tour.');
        $this->assertStringContainsString('+243828727706', $contenu);
    }

    /**
     * LA FRONTIÈRE, ET ELLE NE BOUGE PAS. Un refus EXPLICITE ne se recycle pas :
     * réinjecter les valeurs d'un plan que l'utilisateur vient d'écarter ferait
     * ressusciter par Ket ce qu'il venait de refuser.
     */
    public function testUnPlanRefuseParLUtilisateurNeRessuscitePasSesValeurs(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, $this->planAvecValeurs([
            'mutationPlanCancelled' => true,
            'mutationPlanFin'       => 'utilisateur',
        ]));

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];

        $this->assertStringNotContainsString('Joëlle Fama Sona', $contenu, 'Un plan que l’utilisateur a '
            . 'REFUSÉ ne doit pas voir ses valeurs remises devant le modèle.');
    }

    /**
     * UN PLAN PÉRIMÉ N'A ÉTÉ REFUSÉ PAR PERSONNE. Ses valeurs restent acquises —
     * l'utilisateur les a bel et bien dictées — et le marqueur ne doit pas lui
     * attribuer une décision qu'il n'a pas prise.
     */
    public function testUnPlanPerimeGardeSesValeursEtNAccusePersonne(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, $this->planAvecValeurs([
            'mutationPlanCancelled' => true,
            'mutationPlanFin'       => 'perime',
        ]));

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];

        $this->assertStringContainsString('Joëlle Fama Sona', $contenu);
        $this->assertStringNotContainsString('ANNULÉ par l\'utilisateur', $contenu, 'Personne n’a annulé '
            . 'ce plan : le dire au modèle lui ferait raconter au courtier une décision inexistante.');
    }

    /** Un plan REMPLACÉ non plus : c'est Ket qui l'a chassé, pas l'utilisateur. */
    public function testUnPlanRemplaceNEstPasPresenteCommeUnRefus(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, $this->planAvecValeurs([
            'mutationPlanCancelled' => true,
            'mutationPlanFin'       => 'remplace',
        ]));

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];

        $this->assertStringNotContainsString('ANNULÉ par l\'utilisateur', $contenu);
    }

    /**
     * L'INTERDICTION DE RECOPIER PORTE SUR NOS ARTEFACTS, PAS SUR LA PAROLE DE
     * L'UTILISATEUR. Le tableau et le budget périment avec le plan ; les valeurs
     * qu'il a dictées, jamais. Sans cette délimitation, la consigne qui empêche le
     * plan fantôme empêche aussi la reprise légitime.
     */
    public function testLInterdictionDeRecopierDistingueLesArtefactsDesValeurs(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, $this->planAvecValeurs([
            'mutationPlanExecuted' => true,
        ]));

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];

        $this->assertStringContainsString('NE RECOPIE JAMAIS le tableau', $contenu);
        $this->assertMatchesRegularExpression(
            '/jamais sur les VALEURS|jamais sur la parole|valeurs que l’utilisateur/u',
            $contenu,
            'L’interdiction doit dire ce qu’elle NE couvre pas, sinon elle interdit aussi de reprendre '
            . 'ce que l’utilisateur vient de donner.',
        );
    }

    /**
     * Le garde-fou contre l'affirmation de complaisance : après exécution, le
     * moteur ne reçoit PAS un simple « succès » (dont il pourrait déduire que tout
     * ce qui avait été évoqué est enregistré) mais la liste EXACTE de ce qui a été
     * écrit. Le cas vécu : une cotation créée sans son revenu de courtage, et Ket
     * qui affirmait que le revenu avait été « généré automatiquement ».
     */
    public function testJournalDExecutionEstInjecteLigneParLigne(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, [
            'mutationPlan'         => ['plan' => []],
            'mutationPlanExecuted' => true,
            'mutationPlanJournal'  => [
                ['op' => 'create', 'entite' => 'Cotation', 'libelle' => 'Propositions', 'cible' => 'Offre SUNU', 'statut' => 'ok', 'niveau' => 0],
                ['op' => 'create', 'entite' => 'ChargementPourPrime', 'libelle' => 'Composantes', 'cible' => 'Prime nette', 'statut' => 'ok', 'niveau' => 1],
            ],
        ]);

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];

        $this->assertStringContainsString('Propositions : créé (« Offre SUNU »)', $contenu);
        $this->assertStringContainsString('Composantes : créé (« Prime nette »)', $contenu);
        $this->assertStringContainsString('rien d\'autre n\'a été enregistré', $contenu);
        // L'échappatoire du modèle — « le moteur l'a calculé automatiquement » —
        // est explicitement fermée.
        $this->assertStringContainsString('automatique', $contenu);
    }

    /** Journal absent (plan exécuté avant cette garantie) : ne rien présumer. */
    public function testSansJournalLeMoteurEstInviteAVerifier(): void
    {
        [$ent, $inv] = $this->seed();
        $conv = $this->conversationAvecPlan($ent, $inv, ['mutationPlan' => ['plan' => []], 'mutationPlanExecuted' => true]);

        $contenu = $this->build($ent, $inv, $conv)[0]['content'];
        $this->assertStringContainsString('journal indisponible', $contenu);
        $this->assertStringContainsString('rechercher_entites', $contenu);
    }
}
