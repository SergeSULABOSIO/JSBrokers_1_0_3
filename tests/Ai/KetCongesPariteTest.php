<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\CongesTool;
use App\Ai\Tool\PreparerDecisionCongeTool;
use App\Ai\Tool\PreparerDemandeCongeTool;
use App\Ai\Tool\SimulerCongeTool;
use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\HistoriqueDemande;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\EventListener\CongeTransitionListener;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongeWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE GARDE-FOU DE LA PARITÉ — scénario 16 de la recette.
 *
 * ── LE RISQUE QU'IL COUVRE ──────────────────────────────────────────────────────────
 * Le circuit de validation a deux portes. L'écran passe par DemandeCongeWorkflow, qui
 * écrit la ligne d'historique et le mouvement de compteur du même geste. L'assistant, lui,
 * n'y passe pas : son plan d'écriture est exécuté par le moteur générique, qui ne connaît
 * que des champs et ignore tout des conséquences d'un statut.
 *
 * Sans CongeTransitionListener, un congé approuvé via Ket n'aurait donc ni trace, ni
 * mouvement, ni e-mail — et rien ne le signalerait avant que quelqu'un ne s'aperçoive que
 * son solde n'a pas bougé. C'est exactement ce que ce test empêche de revenir.
 *
 * ── CE QU'IL COMPARE ────────────────────────────────────────────────────────────────
 * Deux demandes identiques, décidées l'une par le workflow (chemin écran), l'autre par
 * une écriture générique du statut suivie de l'abonné (chemin assistant). Les lignes
 * produites doivent être les mêmes, au canal près.
 */
class KetCongesPariteTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-ket-conge-parite@test.local';
    private const ENT = 'PHPUnit Ket Congés Parité SARL';

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
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /** @return array{entreprise: Entreprise, valideur: Invite, agent: Invite, ca: TypeAbsence} */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Parité')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $valideur = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $valideur->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($valideur);

        $agent = (new Invite())->setNom('Alice Mukendi')->setProprietaire(false);
        $agent->setEntreprise($ent);
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
            ->setNature(MouvementConge::NATURE_DOTATION)->setQuantite('40.0');
        $dotation->setEntreprise($ent);
        $em->persist($dotation);

        $em->flush();
        $em->refresh($agent);

        return ['entreprise' => $ent, 'valideur' => $valideur, 'agent' => $agent, 'ca' => $ca];
    }

    private function soumettre(array $s, string $debut, string $fin): DemandeConge
    {
        $demande = new DemandeConge();
        $demande->setAgent($s['agent'])->setTypeAbsence($s['ca']);
        $demande->setDateDebut(new \DateTimeImmutable($debut));
        $demande->setDateFin(new \DateTimeImmutable($fin));
        $demande->setEntreprise($s['entreprise']);
        $demande->setStatut(DemandeConge::STATUT_SOUMISE);
        $demande->setNbJours('5.0');
        $this->em()->persist($demande);
        $this->em()->flush();

        // La trace de la soumission, pour partir du même état des deux côtés.
        static::getContainer()->get(CongeTransitionListener::class)->completerLesEnAttente();
        $this->em()->flush();

        return $demande;
    }

    /**
     * Applique à la demande EXACTEMENT les champs que produit l'outil de décision de Ket.
     *
     * On les lui demande par réflexion plutôt que de les recopier : recopiés, ils
     * dériveraient dès la première modification de l'outil, et le test continuerait de
     * passer en mesurant quelque chose qui n'existe plus.
     */
    private function appliquerLesChampsDeLOutil(
        DemandeConge $demande,
        array $s,
        string $geste,
        string $commentaire = '',
    ): void {
        $outil = static::getContainer()->get(\App\Ai\Tool\PreparerDecisionCongeTool::class);
        $methode = new \ReflectionMethod($outil, 'champsDuGeste');
        $methode->setAccessible(true);

        /** @var array<string, string> $champs */
        $champs = $methode->invoke(
            $outil,
            $geste,
            $commentaire,
            new \App\Ai\Scope\AiScope($s['entreprise'], $s['valideur'], null),
        );

        // Le moteur générique hydrate par le FormType ; ici on applique les mêmes
        // valeurs directement, ce qui revient au même une fois le plan exécuté.
        foreach ($champs as $champ => $valeur) {
            match ($champ) {
                'statut' => $demande->setStatut($valeur),
                'origine' => $demande->setOrigine($valeur),
                'commentaireDecision' => $demande->setCommentaireDecision($valeur),
                'valideur' => $demande->setValideur(
                    $this->em()->getRepository(\App\Entity\Invite::class)->find((int) $valeur),
                ),
                'dateDecision' => $demande->setDateDecision(new \DateTimeImmutable($valeur)),
                default => null,
            };
        }
    }

    /**
     * @return array{historiques: HistoriqueDemande[], mouvements: MouvementConge[]}
     */
    private function lignesDe(DemandeConge $demande): array
    {
        return [
            'historiques' => $this->em()->getRepository(HistoriqueDemande::class)
                ->findBy(['demande' => $demande], ['id' => 'ASC']),
            'mouvements' => $this->em()->getRepository(MouvementConge::class)
                ->findBy(['demande' => $demande], ['id' => 'ASC']),
        ];
    }

    // ═══════════ LA PARITÉ ELLE-MÊME ═══════════

    /**
     * DEUX CHEMINS, LE MÊME ÉTAT EN BASE.
     *
     * À gauche l'écran (le workflow écrit tout), à droite l'assistant (le moteur générique
     * n'écrit que le statut, et l'abonné complète). Les lignes doivent coïncider.
     */
    public function testUneApprobationParLAssistantProduitLesMemesLignesQueParLEcran(): void
    {
        $s = $this->semer();
        $workflow = static::getContainer()->get(DemandeCongeWorkflow::class);
        $listener = static::getContainer()->get(CongeTransitionListener::class);

        // ── Chemin ÉCRAN ────────────────────────────────────────────────────────────
        $parEcran = $this->soumettre($s, '2026-11-02', '2026-11-06');
        $workflow->decider($parEcran, $s['valideur'], DemandeCongeWorkflow::DECISION_APPROUVER, 'Bon congé.');
        $this->em()->flush();

        // ── Chemin ASSISTANT ────────────────────────────────────────────────────────
        // ON N'AIDE PAS CE CHEMIN. Les champs appliqués sont EXACTEMENT ceux que l'outil
        // produit — pas un de plus. Poser `valideur` à la main ici reviendrait à mesurer
        // le montage du test plutôt que l'outil, et c'est ainsi qu'un défaut réel est
        // resté invisible : l'outil ne l'écrivait pas, et l'abonné enregistrait l'agent
        // comme auteur de sa propre approbation.
        $parKet = $this->soumettre($s, '2026-12-07', '2026-12-11');
        $this->appliquerLesChampsDeLOutil($parKet, $s, 'approuver', 'Bon congé.');
        $this->em()->flush();

        $listener->completerLesEnAttente();
        $this->em()->flush();

        $ecran = $this->lignesDe($parEcran);
        $ket = $this->lignesDe($parKet);

        // Même nombre de lignes : soumission + approbation.
        self::assertCount(2, $ecran['historiques']);
        self::assertCount(
            2,
            $ket['historiques'],
            "Une approbation via l'assistant doit laisser exactement la même trace qu'à l'écran.",
        );

        // Même transition tracée.
        self::assertSame(DemandeConge::STATUT_SOUMISE, $ecran['historiques'][1]->getStatutAvant());
        self::assertSame(DemandeConge::STATUT_SOUMISE, $ket['historiques'][1]->getStatutAvant());
        self::assertSame(DemandeConge::STATUT_APPROUVEE, $ecran['historiques'][1]->getStatutApres());
        self::assertSame(DemandeConge::STATUT_APPROUVEE, $ket['historiques'][1]->getStatutApres());

        // Même mouvement de compteur, au signe et au montant près.
        self::assertCount(1, $ecran['mouvements']);
        self::assertCount(1, $ket['mouvements'], "Sans mouvement, le solde de l'agent resterait faux.");
        self::assertSame(MouvementConge::NATURE_PRISE, $ecran['mouvements'][0]->getNature());
        self::assertSame(MouvementConge::NATURE_PRISE, $ket['mouvements'][0]->getNature());
        self::assertSame(-5.0, $ecran['mouvements'][0]->quantiteFloat());
        self::assertSame(-5.0, $ket['mouvements'][0]->quantiteFloat());
    }

    /**
     * RG-22 : le CANAL est tracé, l'AUTEUR reste l'humain.
     *
     * L'historique doit pouvoir dire « approuvée par X via Ket » — jamais « approuvée par
     * l'assistant », qui ne décide rien.
     */
    public function testLOrigineDistingueLeCanalSansChangerLAuteur(): void
    {
        $s = $this->semer();
        $listener = static::getContainer()->get(CongeTransitionListener::class);

        $demande = $this->soumettre($s, '2026-11-09', '2026-11-13');
        $this->appliquerLesChampsDeLOutil($demande, $s, 'approuver');
        $this->em()->flush();

        $listener->completerLesEnAttente();
        $this->em()->flush();

        $lignes = $this->lignesDe($demande);
        $decision = end($lignes['historiques']);

        self::assertSame(DemandeConge::ORIGINE_KET, $decision->getOrigine());
        self::assertSame(
            $s['valideur']->getId(),
            $decision->getAuteur()?->getId(),
            "L'auteur enregistré est le valideur humain, jamais l'assistant.",
        );
    }

    /** L'annulation par l'assistant recrédite comme celle de l'écran. */
    public function testUneAnnulationParLAssistantRecrediteLeSolde(): void
    {
        $s = $this->semer();
        $listener = static::getContainer()->get(CongeTransitionListener::class);
        $soldes = static::getContainer()->get(CalculateurSolde::class);

        $demande = $this->soumettre($s, '2026-11-16', '2026-11-20');
        $this->appliquerLesChampsDeLOutil($demande, $s, 'approuver');
        $this->em()->flush();
        $listener->completerLesEnAttente();
        $this->em()->flush();

        self::assertSame(35.0, $soldes->pour($s['agent'], 2026)->disponible());

        $this->appliquerLesChampsDeLOutil($demande, $s, 'annuler', 'Le client a avancé la réunion.');
        $this->em()->flush();
        $listener->completerLesEnAttente();
        $this->em()->flush();

        self::assertSame(
            40.0,
            $soldes->pour($s['agent'], 2026)->disponible(),
            "L'annulation recrédite, quel que soit le canal qui l'a écrite.",
        );
    }

    /**
     * L'ABONNÉ EST IDEMPOTENT. Rejoué — deux flushes d'un même plan, un message rejoué —
     * il ne doit pas doubler la trace ni le décompte.
     */
    public function testLAbonneRejoueNeDoublePasLesLignes(): void
    {
        $s = $this->semer();
        $listener = static::getContainer()->get(CongeTransitionListener::class);

        $demande = $this->soumettre($s, '2026-11-23', '2026-11-27');
        $this->appliquerLesChampsDeLOutil($demande, $s, 'approuver');
        $this->em()->flush();

        $listener->completerLesEnAttente();
        $this->em()->flush();
        $listener->completerLesEnAttente();
        $this->em()->flush();

        $lignes = $this->lignesDe($demande);

        self::assertCount(2, $lignes['historiques'], 'Soumission + approbation, et rien de plus.');
        self::assertCount(1, $lignes['mouvements'], 'Un seul décompte, quoi qu\'il arrive.');
    }

    /**
     * UNE DÉCISION ENGAGE CELUI QUI LA PREND — y compris par l'assistant.
     *
     * L'outil omettait `valideur` et `dateDecision` : la colonne « Décidé par » serait
     * restée vide, et l'abonné de transition, faute de valideur, aurait enregistré
     * l'AGENT comme auteur de sa propre approbation. Ce test tient les deux champs.
     */
    public function testLOutilDeDecisionPoseLeValideurEtLHorodatage(): void
    {
        $s = $this->semer();
        $demande = $this->soumettre($s, '2026-12-14', '2026-12-18');

        $this->appliquerLesChampsDeLOutil($demande, $s, 'approuver', 'Bon congé.');
        $this->em()->flush();

        self::assertSame(
            $s['valideur']->getId(),
            $demande->getValideur()?->getId(),
            "Sans valideur, la fiche n'aurait dit à personne qui a décidé.",
        );
        self::assertNotNull($demande->getDateDecision());

        static::getContainer()->get(CongeTransitionListener::class)->completerLesEnAttente();
        $this->em()->flush();

        $lignes = $this->lignesDe($demande);
        $decision = end($lignes['historiques']);

        self::assertSame(
            $s['valideur']->getId(),
            $decision->getAuteur()?->getId(),
            "L'auteur tracé doit être le valideur, jamais l'agent qui subit la décision.",
        );
    }

    // ═══════════ LES OUTILS SONT DÉCLARÉS ET GARDÉS ═══════════

    /** Les quatre outils du lot existent et portent leur nom de contrat. */
    public function testLesQuatreOutilsSontDeclares(): void
    {
        $noms = [
            CongesTool::class => 'conges',
            SimulerCongeTool::class => 'simuler_conge',
            PreparerDemandeCongeTool::class => 'preparer_demande_conge',
            PreparerDecisionCongeTool::class => 'preparer_decision_conge',
        ];

        foreach ($noms as $classe => $nom) {
            $outil = static::getContainer()->get($classe);
            self::assertSame($nom, $outil->name());
        }
    }

    /**
     * RG-20 : les outils s'exécutent sous les seules habilitations de l'utilisateur.
     * Un invité SANS le droit « Congés » n'obtient rien — pas même l'existence des données.
     */
    public function testUnInviteSansDroitNObtientRienDeLAssistant(): void
    {
        $s = $this->semer();

        $sansDroit = (new Invite())->setNom('Sans Droit')->setProprietaire(false);
        $sansDroit->setEntreprise($s['entreprise']);
        $this->em()->persist($sansDroit);
        $this->em()->flush();

        $scope = new AiScope($s['entreprise'], $sansDroit, null);

        $lecture = static::getContainer()->get(CongesTool::class)->execute([], $scope);
        self::assertSame(\App\Ai\Tool\AiToolResult::STATUS_HORS_PERIMETRE, $lecture->status);

        $ecriture = static::getContainer()->get(PreparerDemandeCongeTool::class)
            ->execute(['periode' => 'la semaine prochaine'], $scope);
        self::assertSame(\App\Ai\Tool\AiToolResult::STATUS_HORS_PERIMETRE, $ecriture->status);
    }

    /**
     * `estDisponible()` doit être le MIROIR EXACT de la garde d'`execute()` : sans quoi le
     * modèle se voit présenter un outil qu'on lui refusera ensuite.
     */
    public function testLaDeclarationConditionnelleRefleteLaGarde(): void
    {
        $s = $this->semer();

        $sansDroit = (new Invite())->setNom('Sans Droit')->setProprietaire(false);
        $sansDroit->setEntreprise($s['entreprise']);
        $this->em()->persist($sansDroit);
        $this->em()->flush();

        foreach ([PreparerDemandeCongeTool::class, PreparerDecisionCongeTool::class] as $classe) {
            $outil = static::getContainer()->get($classe);

            self::assertFalse(
                $outil->estDisponible(new AiScope($s['entreprise'], $sansDroit, null)),
                sprintf('%s ne doit pas être déclaré à qui ne peut pas l\'exécuter.', $classe),
            );
            self::assertTrue(
                $outil->estDisponible(new AiScope($s['entreprise'], $s['agent'], null)),
                sprintf('%s doit être déclaré à qui a l\'écriture.', $classe),
            );
        }
    }
}
