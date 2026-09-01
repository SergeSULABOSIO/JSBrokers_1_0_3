<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\HistoriqueDemande;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\CongeMailContext;
use App\Service\Conge\DemandeCongeWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * LES E-MAILS PORTENT LE CIRCUIT — scénarios 13, 14 et 15 de la recette.
 *
 * Ce ne sont pas un confort : le module serait inutilisable si le valideur devait penser à
 * se connecter pour découvrir qu'une demande l'attend.
 *
 * ── CE QUE CE TEST TIENT VRAIMENT ───────────────────────────────────────────────────
 * Que les CHIFFRES du mail sont ceux de l'écran. Ils viennent du même CalculateurSolde,
 * transportés par un DTO que le template se contente de mettre en forme — le Twig ne
 * calcule rien. C'est la seule manière qu'un mail ne contredise jamais la rubrique.
 *
 * Et qu'un SMTP injoignable n'annule jamais une décision : le mailer avale et journalise
 * ses propres échecs.
 */
class CongeMailTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-mail-owner@test.local';
    private const AGENT_EMAIL = 'phpunit-conge-mail-agent@test.local';
    private const ENT = 'PHPUnit Congés Mail SARL';

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

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Patron')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $valideur = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $valideur->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($valideur);

        $compteAgent = (new Utilisateur())->setEmail(self::AGENT_EMAIL)->setNom('Alice')->setVerified(true)->setPassword('x');
        $em->persist($compteAgent);

        $agent = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $agent->setUtilisateur($compteAgent)->setEntreprise($ent);
        $em->persist($agent);

        $roles = (new RolesEnAdministration())->setNom('Congés');
        $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $roles->setInvite($agent)->setEntreprise($ent);
        $em->persist($roles);

        $ca = (new TypeAbsence())->setCode(TypeAbsence::CODE_CONGE_ANNUEL)->setLibelle('Congé annuel')
            ->setDecompte(true)->setJustificatifRequis(false)->setAutoriseDemiJournee(true)->setActif(true);
        $ca->setEntreprise($ent);
        $em->persist($ca);

        $dotation = (new MouvementConge())
            ->setAgent($agent)->setExercice(2026)->setTypeAbsence($ca)
            ->setNature(MouvementConge::NATURE_DOTATION)->setQuantite('20.0');
        $dotation->setEntreprise($ent);
        $em->persist($dotation);

        $em->flush();
        $em->refresh($agent);

        return ['entreprise' => $ent, 'valideur' => $valideur, 'agent' => $agent, 'ca' => $ca];
    }

    /** Une demande soumise, avec sa ligne d'historique. */
    private function soumettre(array $s): DemandeConge
    {
        $demande = new DemandeConge();
        $demande->setAgent($s['agent'])->setTypeAbsence($s['ca']);
        $demande->setDateDebut(new \DateTimeImmutable('2026-11-02'));
        $demande->setDateFin(new \DateTimeImmutable('2026-11-06'));
        $demande->setMotif('Vacances en famille.');
        $demande->setEntreprise($s['entreprise']);
        $this->em()->persist($demande);

        static::getContainer()->get(DemandeCongeWorkflow::class)->soumettre($demande, $s['agent']);
        $this->em()->flush();

        return $demande;
    }

    private function rendre(CongeMailContext $ctx): string
    {
        $email = (new TemplatedEmail())
            ->htmlTemplate('emails/conge_notification.html.twig')
            ->context([
                'ctx' => $ctx,
                'logoPath' => '@images/entreprises/logofav.png',
                'senderEmail' => 'contact@jsbrokers.com',
            ]);

        /** @var BodyRendererInterface $renderer */
        $renderer = static::getContainer()->get('twig.mime_body_renderer');
        $renderer->render($email);

        return (string) $email->getHtmlBody();
    }

    private function contexte(DemandeConge $demande, HistoriqueDemande $transition, array $s): CongeMailContext
    {
        $solde = static::getContainer()->get(CalculateurSolde::class)->pour($s['agent'], 2026);
        $jours = $demande->nbJoursFloat();

        return new CongeMailContext(
            demande: $demande,
            transition: $transition,
            solde: $solde,
            disponibleAvant: $solde->disponibleAvant($jours),
            disponibleApres: $solde->disponible(),
            collegues: [],
            instantaneLe: new \DateTimeImmutable('2026-09-09 10:30'),
            lienApplication: 'https://example.test/espacedetravail/1/1',
            titre: 'Demande de congé à valider',
            intro: 'Alice Mukendi demande un congé du 02/11/2026 au 06/11/2026.',
            icone: 'conge',
        );
    }

    // ═══════════ Scénario 13 : le mail dit ce que dit l'écran ═══════════

    public function testLeMailDeSoumissionSeRendEnHtmlDeMarque(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s);
        $transition = $demande->getHistoriques()->first();

        $html = $this->rendre($this->contexte($demande, $transition, $s));

        self::assertStringContainsString('JS Brokers', $html);
        self::assertStringContainsString('<svg', $html, "L'icône doit être rendue en SVG inline.");
        self::assertStringContainsString('cid:', $html, 'Le logo doit être embarqué (CID).');
        self::assertStringContainsString('Alice Mukendi', $html);
        self::assertStringContainsString('02/11/2026', $html);
        self::assertStringContainsString('Vacances en famille.', $html);
    }

    /**
     * LES CHIFFRES DU MAIL SONT CEUX DE L'ÉCRAN.
     *
     * L'agent a 20 jours, la demande en coûte 5 : disponible 15, et « avant la demande »
     * 20. Ces deux nombres doivent apparaître tels quels — le template ne recalcule rien.
     */
    public function testLesSoldesDuMailSontCeuxDuCompteur(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s);
        $transition = $demande->getHistoriques()->first();

        $solde = static::getContainer()->get(CalculateurSolde::class)->pour($s['agent'], 2026);
        self::assertSame(20.0, $solde->acquis);
        self::assertSame(5.0, $solde->engage);
        self::assertSame(15.0, $solde->disponible());

        $html = $this->rendre($this->contexte($demande, $transition, $s));

        self::assertStringContainsString('20,0', $html, "L'acquis doit figurer tel quel.");
        self::assertStringContainsString('15,0', $html, 'Le disponible doit figurer tel quel.');
        self::assertStringContainsString('Compteur 2026', $html);
    }

    /** RG-16 : les soldes sont un instantané daté, et le mail le dit. */
    public function testLeMailAnnonceQueLesSoldesSontUnInstantaneDate(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s);

        $html = $this->rendre($this->contexte($demande, $demande->getHistoriques()->first(), $s));

        self::assertStringContainsString('instantané au', $html);
        self::assertStringContainsString('09/09/2026', $html);
    }

    // ═══════════ Scénario 14 : le mail de décision ═══════════

    public function testLeMailDeDecisionPorteSonAuteurEtSonCommentaire(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s);

        static::getContainer()->get(DemandeCongeWorkflow::class)
            ->decider($demande, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER, 'Bon congé, Alice.');
        $this->em()->flush();

        $decision = $demande->getHistoriques()->last();
        $ctx = $this->contexte($demande, $decision, $s);

        $html = $this->rendre($ctx);

        self::assertStringContainsString('Le Patron', $html, "L'auteur de la décision doit être nommé.");
        self::assertStringContainsString('Bon congé, Alice.', $html);
        self::assertStringContainsString('La décision', $html);
    }

    /** L'auto-approbation se dit en toutes lettres : ce n'est pas une validation ordinaire. */
    public function testLAutoApprobationEstMentionneeDansLeMail(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s);

        $transition = $demande->getHistoriques()->first();
        $transition->setAutoApprouvee(true);
        $transition->setStatutApres(DemandeConge::STATUT_APPROUVEE);
        $this->em()->flush();

        $html = $this->rendre($this->contexte($demande, $transition, $s));

        self::assertStringContainsString('auto-approuvée', $html);
    }

    // ═══════════ Un type non décompté ne montre pas de compteur ═══════════

    public function testUnTypeNonDecompteNAnnoncePasDeCompteur(): void
    {
        $s = $this->semer();

        $maladie = (new TypeAbsence())->setCode(TypeAbsence::CODE_MALADIE)->setLibelle('Maladie')
            ->setDecompte(false)->setJustificatifRequis(false)->setAutoriseDemiJournee(false)->setActif(true);
        $maladie->setEntreprise($s['entreprise']);
        $this->em()->persist($maladie);
        $this->em()->flush();

        $demande = $this->soumettre($s);
        $demande->setTypeAbsence($maladie);
        $this->em()->flush();

        $html = $this->rendre($this->contexte($demande, $demande->getHistoriques()->first(), $s));

        self::assertStringNotContainsString('Compteur 2026', $html);
        self::assertStringContainsString('ne touche pas au compteur', $html);
    }

    // ═══════════ Scénario 15 : un SMTP injoignable n'annule rien ═══════════

    /**
     * LE MAILER AVALE SES PROPRES ÉCHECS. La décision est déjà enregistrée quand il
     * s'exécute : rien de ce qu'il fait ne doit pouvoir la remettre en cause.
     */
    public function testUnEchecDEnvoiNeRemonteJamais(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s);

        // Une trace ORPHELINE — sans demande rattachée — est le cas dégradé le plus
        // simple à provoquer : le mailer doit s'en sortir sans lever.
        $orpheline = new HistoriqueDemande();
        $orpheline->setStatutAvant(DemandeConge::STATUT_BROUILLON);
        $orpheline->setStatutApres(DemandeConge::STATUT_SOUMISE);
        $orpheline->setEntreprise($s['entreprise']);
        $this->em()->persist($orpheline);
        $this->em()->flush();

        static::getContainer()->get(\App\Service\Conge\CongeMailer::class)->notifier($orpheline);

        // On arrive ici : c'est tout ce qui compte.
        self::assertSame(
            DemandeConge::STATUT_SOUMISE,
            $demande->getStatut(),
            "La demande reste enregistrée quoi qu'il arrive à l'envoi.",
        );
    }
}
