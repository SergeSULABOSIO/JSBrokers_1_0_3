<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\CongesTool;
use App\Ai\Tool\PreparerDecisionCongeTool;
use App\Ai\Tool\PreparerDemandeCongeTool;
use App\Ai\Tool\SimulerCongeTool;
use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CE QUE L'ASSISTANT REFUSE, ET COMMENT IL LE DIT — scénarios 17, 18 et 19.
 *
 * ── LES RÈGLES NE SE CONTOURNENT PAS PAR LE CHAT ────────────────────────────────────
 * Un agent qui demande à Ket d'approuver sa propre demande se heurte à RG-01 comme
 * partout ailleurs, avec la même phrase. Ce n'est pas une politesse du prompt : l'outil
 * appelle DemandeCongeWorkflow, le même service que le picker de l'écran.
 *
 * ── ET CE QUI N'EST PAS COMPRIS EST DEMANDÉ, JAMAIS DEVINÉ ──────────────────────────
 * Une période que le résolveur ne comprend pas ne devient pas une date au hasard : elle
 * devient une question. Un congé posé sur de mauvaises dates se répare mal.
 */
class KetCongesRefusTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-ket-conge-refus@test.local';
    private const AGENT_EMAIL = 'phpunit-ket-conge-refus-agent@test.local';
    private const ENT = 'PHPUnit Ket Congés Refus SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // WebTestCase, et non KernelTestCase : le dry-run d'un plan valide par le
        // FormType RÉEL, dont les listes de choix sont scopées sur l'utilisateur
        // connecté (fail-closed sans session). Sans authentification, toute relation
        // serait refusée — et le test mesurerait son propre montage.
        $this->client = static::createClient();
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
        foreach ([self::OWNER_EMAIL, self::AGENT_EMAIL] as $email) {
            $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => $email]);
        }
        foreach ([
            'mouvement_conge', 'historique_demande', 'demande_conge', 'regime_travail',
            'jour_ferie', 'type_absence', 'roles_en_administration', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:a, :b)',
            ['a' => self::OWNER_EMAIL, 'b' => self::AGENT_EMAIL],
        );
        $this->em()->clear();
    }

    /** @return array{entreprise: Entreprise, valideur: Invite, agent: Invite, ca: TypeAbsence} */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Refus')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $valideur = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $valideur->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($valideur);

        // L'agent est LUI-MÊME valideur : c'est ce qui rend le scénario 17 non trivial —
        // il a le droit de décider, mais jamais de sa propre demande.
        $compteAgent = (new Utilisateur())->setEmail(self::AGENT_EMAIL)->setNom('Alice')->setVerified(true)->setPassword('x');
        $compteAgent->setConnectedTo($ent);
        $em->persist($compteAgent);

        $agent = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $agent->setUtilisateur($compteAgent)->setEntreprise($ent);
        $em->persist($agent);

        $roles = (new RolesEnAdministration())->setNom('Congés');
        $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION]);
        $roles->setInvite($agent)->setEntreprise($ent);
        $em->persist($roles);

        $ca = (new TypeAbsence())->setCode(TypeAbsence::CODE_CONGE_ANNUEL)->setLibelle('Congé annuel')
            ->setDecompte(true)->setJustificatifRequis(false)->setAutoriseDemiJournee(true)->setActif(true);
        $ca->setEntreprise($ent);
        $em->persist($ca);

        $dotation = (new MouvementConge())
            ->setAgent($agent)->setExercice((int) (new \DateTimeImmutable('now'))->format('Y'))
            ->setTypeAbsence($ca)->setNature(MouvementConge::NATURE_DOTATION)->setQuantite('40.0');
        $dotation->setEntreprise($ent);
        $em->persist($dotation);

        $em->flush();
        $em->refresh($agent);

        return ['entreprise' => $ent, 'valideur' => $valideur, 'agent' => $agent, 'ca' => $ca];
    }

    /**
     * Le scope de l'assistant ET la session qui va avec.
     *
     * Dans une vraie requête, les deux sont toujours cohérents : l'outil s'exécute sous
     * l'identité de l'utilisateur connecté. Le montage doit donc l'être aussi, sinon les
     * listes de choix des formulaires se retrouvent vides.
     */
    private function scope(array $s, ?Invite $qui = null): AiScope
    {
        $invite = $qui ?? $s['agent'];
        $compte = $invite->getUtilisateur();
        if ($compte !== null) {
            $this->client->loginUser($compte);
        }

        return new AiScope($s['entreprise'], $invite, null);
    }

    private function demandeDe(array $s, Invite $agent): DemandeConge
    {
        $demande = new DemandeConge();
        $demande->setAgent($agent)->setTypeAbsence($s['ca']);
        $demande->setDateDebut(new \DateTimeImmutable('+30 days'));
        $demande->setDateFin(new \DateTimeImmutable('+34 days'));
        $demande->setEntreprise($s['entreprise']);
        $demande->setStatut(DemandeConge::STATUT_SOUMISE);
        $demande->setNbJours('3.0');
        $this->em()->persist($demande);
        $this->em()->flush();

        return $demande;
    }

    // ═══════════ Scénario 17 : nul ne valide sa propre demande, même par Ket ═══════════

    public function testUnAgentNePeutPasApprouverSaProprePropreDemandeParLAssistant(): void
    {
        $s = $this->semer();
        $demande = $this->demandeDe($s, $s['agent']);

        $resultat = static::getContainer()->get(PreparerDecisionCongeTool::class)->execute([
            'geste' => 'approuver',
            'demandeId' => $demande->getId(),
        ], $this->scope($s));

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertFalse($resultat->data['pret'] ?? true, 'Aucun plan ne doit naître de ce geste.');
        self::assertStringContainsString(
            'votre propre demande',
            (string) ($resultat->data['bloquant'] ?? ''),
            "Le refus doit être celui de l'écran, mot pour mot.",
        );
        self::assertNull($resultat->uiAction, 'Aucune barre « Valider et exécuter » ne doit apparaître.');
    }

    /** Le valideur, lui, obtient bien un plan sur la demande d'un tiers. */
    public function testUnValideurObtientUnPlanSurLaDemandeDUnTiers(): void
    {
        $s = $this->semer();
        $demande = $this->demandeDe($s, $s['agent']);

        $resultat = static::getContainer()->get(PreparerDecisionCongeTool::class)->execute([
            'geste' => 'approuver',
            'demandeId' => $demande->getId(),
        ], $this->scope($s, $s['valideur']));

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false, 'Le valideur doit obtenir un plan à valider.');
        self::assertArrayHasKey('recapitulatif', $resultat->data);
        self::assertSame('approuver', $resultat->data['recapitulatif']['geste']);
    }

    // ═══════════ Scénario 18 : les dates résolues AVANT toute écriture ═══════════

    public function testLeRecapitulatifAfficheLesDatesResoluesAvantDEcrire(): void
    {
        $s = $this->semer();

        $resultat = static::getContainer()->get(PreparerDemandeCongeTool::class)->execute([
            'periode' => 'la semaine prochaine',
        ], $this->scope($s));

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false);

        $recap = $resultat->data['recapitulatif'] ?? [];
        self::assertNotSame([], $recap);
        self::assertStringContainsString('semaine prochaine', $recap['interpretationDeLaPeriode']);
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $recap['du']);
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $recap['au']);
        self::assertGreaterThan(0.0, $recap['joursOuvrablesDecomptes'], 'Le décompte doit être annoncé.');

        // RIEN N'EST ÉCRIT tant que l'utilisateur n'a pas validé.
        self::assertCount(
            0,
            $this->em()->getRepository(DemandeConge::class)->findBy(['entreprise' => $s['entreprise']]),
            "Le plan ne doit avoir créé aucune demande : c'est le clic qui écrit.",
        );
    }

    /** Une période incomprise devient une QUESTION, jamais une date au hasard. */
    public function testUnePeriodeIncomprisEstDemandeeEtNonDevinee(): void
    {
        $s = $this->semer();

        $resultat = static::getContainer()->get(PreparerDemandeCongeTool::class)->execute([
            'periode' => 'quand ça m\'arrangera',
        ], $this->scope($s));

        self::assertFalse($resultat->data['pret'] ?? true);
        self::assertSame('periode', $resultat->data['aDemander'][0]['champ'] ?? null);
        self::assertNull($resultat->uiAction, "Pas de bouton tant qu'on ne sait pas de quelles dates on parle.");
    }

    // ═══════════ Scénario 19 : origine KET, auteur humain ═══════════

    public function testLePlanDeCreationPorteLOrigineKet(): void
    {
        $s = $this->semer();

        $resultat = static::getContainer()->get(PreparerDemandeCongeTool::class)->execute([
            'periode' => 'la semaine prochaine',
        ], $this->scope($s));

        // `data['plan']` est l'APERÇU (les noms de champs, pour l'affichage) ; les
        // valeurs réellement soumises voyagent dans la charge utile de la barre de
        // décision — c'est elle que le clic exécutera, donc elle qu'il faut vérifier.
        $champs = $resultat->uiAction['plan'][0]['fields'] ?? [];

        self::assertSame(
            DemandeConge::ORIGINE_KET,
            $champs['origine'] ?? null,
            "Le canal doit être tracé : l'historique doit pouvoir dire « via Ket ».",
        );
        self::assertSame(
            (string) $s['agent']->getId(),
            (string) ($champs['agent'] ?? ''),
            "L'agent est celui du scope, jamais un identifiant venu du modèle.",
        );
    }

    // ═══════════ La simulation n'écrit rien, et le dit ═══════════

    public function testLaSimulationNeProduitAucunPlan(): void
    {
        $s = $this->semer();

        $resultat = static::getContainer()->get(SimulerCongeTool::class)->execute([
            'periode' => 'la semaine prochaine',
        ], $this->scope($s));

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertTrue($resultat->data['pret'] ?? false);
        self::assertNull($resultat->uiAction, "Une question ne doit pas faire apparaître un bouton d'écriture.");
        self::assertGreaterThan(0.0, $resultat->data['joursOuvrablesDecomptes']);
        self::assertStringContainsString("RIEN N'A ÉTÉ ÉCRIT", $resultat->data['note']);
    }

    // ═══════════ La photo d'ensemble respecte le périmètre ═══════════

    /**
     * Un collaborateur ORDINAIRE ne lit pas le solde d'un collègue par le chat : les
     * congés sont des données personnelles, et l'assistant n'est pas une porte dérobée.
     */
    public function testUnNonValideurNObtientPasLeSoldeDUnCollegue(): void
    {
        $s = $this->semer();

        $ordinaire = (new Invite())->setNom('Bob Kabila')->setProprietaire(false);
        $ordinaire->setEntreprise($s['entreprise']);
        $roles = (new RolesEnAdministration())->setNom('Congés');
        $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $roles->setInvite($ordinaire)->setEntreprise($s['entreprise']);
        $this->em()->persist($ordinaire);
        $this->em()->persist($roles);
        $this->em()->flush();
        $this->em()->refresh($ordinaire);

        $resultat = static::getContainer()->get(CongesTool::class)
            ->execute(['agent' => 'Alice Mukendi'], $this->scope($s, $ordinaire));

        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $resultat->status);
    }

    /** Et son propre solde, lui, revient sans la moindre question. */
    public function testChacunLitSonProprSoldeSansRienDemander(): void
    {
        $s = $this->semer();

        $resultat = static::getContainer()->get(CongesTool::class)->execute([], $this->scope($s));

        self::assertSame(AiToolResult::STATUS_OK, $resultat->status);
        self::assertSame('Alice Mukendi', $resultat->data['agent']);
        self::assertSame(40.0, $resultat->data['solde']['acquis']);
        self::assertSame(40.0, $resultat->data['solde']['disponible']);
    }

    /** Un nom inconnu est un refus explicite, jamais un solde inventé. */
    public function testUnCollaborateurInconnuEstUnRefusExplicite(): void
    {
        $s = $this->semer();

        $resultat = static::getContainer()->get(CongesTool::class)
            ->execute(['agent' => 'Personne De Ce Nom'], $this->scope($s, $s['valideur']));

        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
    }
}
