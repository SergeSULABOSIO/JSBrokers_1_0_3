<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Service\Conge\CalculateurSolde;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LA BOÎTE DE DÉCISION S'OUVRE, ET DIT CE QU'ELLE VA FAIRE.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────
 * Une action de barre d'outils traverse six branchements : la déclaration du canevas,
 * le `case` du cerveau, le contrôleur Stimulus, le gabarit, la route d'ouverture et la
 * route d'exécution. Un seul manquant et le clic ne produit RIEN — sans erreur, sans
 * trace : l'utilisateur clique deux fois, puis renonce.
 *
 * Le contrat entre le canevas et le cerveau est déjà tenu par
 * ContratDesEntreesDeMenuTest. Ce test-ci couvre l'autre moitié : le serveur rend
 * vraiment la boîte, et les chiffres qu'elle affiche sont ceux du compteur.
 *
 * ── ELLE S'OUVRE MÊME QUAND LE GESTE EST IMPOSSIBLE ─────────────────────────────────
 * Et elle en dit alors la raison. Un bouton grisé sans explication laisse chercher.
 */
class CongeDecisionPickerTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-picker-owner@test.local';
    private const AGENT_EMAIL = 'phpunit-conge-picker-agent@test.local';
    private const ENT = 'PHPUnit Congés Picker SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
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

    /** @return array{entreprise: Entreprise, valideur: Invite, agent: Invite, demande: DemandeConge} */
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
        $compteAgent->setConnectedTo($ent);
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

        $exercice = (int) (new \DateTimeImmutable('+30 days'))->format('Y');
        $dotation = (new MouvementConge())
            ->setAgent($agent)->setExercice($exercice)->setTypeAbsence($ca)
            ->setNature(MouvementConge::NATURE_DOTATION)->setQuantite('20.0');
        $dotation->setEntreprise($ent);
        $em->persist($dotation);

        $demande = new DemandeConge();
        $demande->setAgent($agent)->setTypeAbsence($ca);
        $demande->setDateDebut(new \DateTimeImmutable('+30 days'));
        $demande->setDateFin(new \DateTimeImmutable('+34 days'));
        $demande->setMotif('Vacances en famille.');
        $demande->setEntreprise($ent);
        $demande->setStatut(DemandeConge::STATUT_SOUMISE);
        $demande->setNbJours('3.0');
        $em->persist($demande);

        $em->flush();
        $em->refresh($agent);

        return ['entreprise' => $ent, 'valideur' => $valideur, 'agent' => $agent, 'demande' => $demande];
    }

    private function compte(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function ouvrir(array $s, Invite $qui, string $geste): string
    {
        $this->client->loginUser($qui->getUtilisateur());
        $this->client->request('GET', sprintf(
            '/admin/demandeconge/api/decision-picker/%d?geste=%s&idEntreprise=%d&idInvite=%d',
            $s['demande']->getId(),
            $geste,
            $s['entreprise']->getId(),
            $qui->getId(),
        ));

        return (string) $this->client->getResponse()->getContent();
    }

    // ═══════════ La boîte se rend, et porte le contexte ═══════════

    public function testLaBoiteDApprobationSeRendPourLeValideur(): void
    {
        $s = $this->semer();
        $html = $this->ouvrir($s, $s['valideur'], 'approuver');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('conge-decision-picker', $html, 'Le contrôleur Stimulus doit être branché.');
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('Approuver le congé', $html);
        self::assertStringContainsString('Alice Mukendi', $html);
        self::assertStringContainsString('Vacances en famille.', $html, 'Le motif du collaborateur doit être lu par le valideur.');
    }

    /**
     * LE COMPTEUR EST DANS LA BOÎTE. Approuver dix jours à quelqu'un qui n'en a plus que
     * trois se répare mal : le valideur doit le voir AU MOMENT où il décide.
     */
    public function testLaBoitePorteLeCompteurDuCollaborateur(): void
    {
        $s = $this->semer();
        $html = $this->ouvrir($s, $s['valideur'], 'approuver');

        $solde = static::getContainer()->get(CalculateurSolde::class)
            ->pour($s['agent'], $s['demande']->getExercice());

        self::assertSame(20.0, $solde->acquis);
        self::assertSame(3.0, $solde->engage);
        self::assertSame(17.0, $solde->disponible());

        self::assertStringContainsString('Compteur', $html);
        self::assertStringContainsString('20,0', $html, "L'acquis doit être affiché tel quel.");
        self::assertStringContainsString('17,0', $html, 'Le disponible doit être affiché tel quel.');
        self::assertStringContainsString('Instantané au', $html, 'Le compteur affiché est daté, et le dit.');
    }

    /**
     * LE DÉCOMPTE SUIT LA PÉRIODE — deux jours du 2 au 3, et non un.
     *
     * ── L'INCIDENT ──────────────────────────────────────────────────────────────────
     * Une demande posée du 2 au 2 septembre coûte un jour. Corrigée du 2 au 3, elle en
     * coûte deux — mais la liste continuait d'annoncer « 1 j » à côté de la nouvelle
     * période, parce que le décompte n'était figé qu'à la SOUMISSION. Deux chiffres qui
     * se contredisent sur la même ligne : on ne sait plus lequel croire, et le contrôle
     * de solde se prononce sur le mauvais.
     */
    public function testCorrigerLaPeriodeRecalculeLeDecompte(): void
    {
        $s = $this->semer();
        $demande = $s['demande'];

        // Une journée, un mercredi : rien de férié, aucun régime particulier.
        $demande->setDateDebut(new \DateTimeImmutable('2026-09-02'));
        $demande->setDateFin(new \DateTimeImmutable('2026-09-02'));
        $demande->setNbJours('1.0');
        $this->em()->flush();

        // Le propriétaire : modifier une fiche existante exige le niveau MODIFICATION,
        // que l'agent n'a pas (Lecture + Écriture d'office).
        $this->client->loginUser($s['valideur']->getUtilisateur());
        $this->client->request('POST', '/admin/demandeconge/api/submit', [
            'id' => (string) $demande->getId(),
            'idEntreprise' => (string) $s['entreprise']->getId(),
            'idInvite' => (string) $s['valideur']->getId(),
            'agent' => (string) $s['agent']->getId(),
            'typeAbsence' => (string) $demande->getTypeAbsence()->getId(),
            'dateDebut' => '2026-09-02',
            'dateFin' => '2026-09-03',
            'motif' => 'Vacances en famille.',
        ]);

        self::assertResponseIsSuccessful();

        // LA RÉPONSE ELLE-MÊME porte déjà le bon chiffre. C'est ce que le crochet
        // `beforePersist` garantit : sans lui, le filet de fin de requête corrigerait bien
        // la base, mais l'écran qui vient d'enregistrer afficherait une dernière fois
        // l'ancienne valeur — et l'on douterait de ce qu'on vient de saisir.
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('2.0', (string) ($charge['entity']['nbJours'] ?? ''), 'La réponse doit déjà porter 2 jours.');

        $this->em()->clear();
        $relue = $this->em()->getRepository(DemandeConge::class)->find($demande->getId());

        self::assertSame('2026-09-03', $relue->getDateFin()->format('Y-m-d'));
        self::assertSame(
            2.0,
            (float) $relue->getNbJours(),
            'Du mercredi 2 au jeudi 3 septembre, il y a DEUX jours ouvrables. Un décompte '
            . 'qui reste à 1 contredit la période affichée sur la même ligne.',
        );
    }

    /**
     * DIRE CE QUI BLOQUE NE SUFFIT PAS : IL FAUT POUVOIR Y ALLER.
     *
     * Lire « le type est plafonné à 10 jours, celle-ci en compte 46 » puis devoir fermer
     * la fenêtre, retrouver la ligne, l'ouvrir, corriger, refermer, resélectionner et
     * relancer le geste — c'est sept manœuvres pour changer un chiffre qu'on avait sous
     * les yeux. Le bouton ouvre la fiche, et la boîte revient d'elle-même.
     */
    public function testUneBoiteBloqueeOffreDAllerCorrigerLaDemande(): void
    {
        $s = $this->semer();

        // On rend le geste impossible : l'agent ne peut pas approuver sa propre demande.
        $html = $this->ouvrir($s, $s['agent'], 'approuver');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Ce geste n'est pas possible pour l'instant", $html);
        self::assertStringContainsString(
            sprintf('data-picker-modifier="%d"', $s['demande']->getId()),
            $html,
            "La boîte doit offrir d'aller corriger la demande, pas seulement constater le blocage.",
        );
        self::assertStringContainsString('Modifier la demande', $html);
    }

    /** Quand rien ne bloque, il n'y a rien à corriger : le bouton ne paraît pas. */
    public function testUneBoitePreteNOffrePasDeCorrection(): void
    {
        $s = $this->semer();
        $html = $this->ouvrir($s, $s['valideur'], 'approuver');

        self::assertStringNotContainsString('data-picker-modifier', $html);
    }

    /**
     * ELLE S'OUVRE MÊME QUAND LE GESTE EST IMPOSSIBLE, et en dit la raison.
     *
     * Ici l'agent tente d'approuver sa propre demande : la boîte s'ouvre, explique, et son
     * bouton reste fermé.
     */
    public function testLaBoiteExpliquePourquoiLeGesteEstImpossible(): void
    {
        $s = $this->semer();
        $html = $this->ouvrir($s, $s['agent'], 'approuver');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Ce geste n'est pas possible", $html);
        self::assertStringContainsString('votre propre demande', $html);
        self::assertStringContainsString('data-bloque-par-le-serveur="1"', $html);
        self::assertStringContainsString('disabled', $html);
    }

    /** Un geste inconnu n'ouvre rien : on ne devine pas ce que l'utilisateur voulait. */
    public function testUnGesteInconnuEstRefuse(): void
    {
        $s = $this->semer();
        $this->ouvrir($s, $s['valideur'], 'saboter');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ═══════════ Le geste s'exécute, de bout en bout ═══════════

    /**
     * DE BOUT EN BOUT : la route d'exécution enregistre la décision, écrit le mouvement et
     * fait tomber le solde. C'est le parcours réel du clic.
     */
    public function testLApprobationSEnregistreEtDecompteLeSolde(): void
    {
        $s = $this->semer();

        $this->client->loginUser($this->compte(self::OWNER_EMAIL));
        $this->client->request(
            'POST',
            sprintf(
                '/admin/demandeconge/api/decision/%d?geste=approuver&idEntreprise=%d&idInvite=%d',
                $s['demande']->getId(),
                $s['entreprise']->getId(),
                $s['valideur']->getId(),
            ),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['commentaire' => 'Bon congé, Alice.']),
        );

        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($reponse['success']);
        self::assertStringContainsString('approuvé', $reponse['message']);

        $this->em()->clear();
        $demande = $this->em()->getRepository(DemandeConge::class)->find($s['demande']->getId());

        self::assertSame(DemandeConge::STATUT_APPROUVEE, $demande->getStatut());
        self::assertSame('Bon congé, Alice.', $demande->getCommentaireDecision());
        self::assertSame($s['valideur']->getId(), $demande->getValideur()?->getId());

        $mouvements = $this->em()->getRepository(MouvementConge::class)
            ->findBy(['demande' => $demande, 'nature' => MouvementConge::NATURE_PRISE]);
        self::assertCount(1, $mouvements, 'Le compteur doit avoir bougé du même geste.');
        self::assertSame(-3.0, $mouvements[0]->quantiteFloat());
    }

    /** Un geste refusé rend un 422 nommant la raison, jamais un 500. */
    public function testUnGesteRefuseRend422AvecSaRaison(): void
    {
        $s = $this->semer();

        // Le valideur tente d'annuler une demande qui n'a pas commencé : c'est permis.
        // On lui fait plutôt approuver DEUX FOIS : le second geste n'a plus lieu d'être.
        $url = sprintf(
            '/admin/demandeconge/api/decision/%d?geste=approuver&idEntreprise=%d&idInvite=%d',
            $s['demande']->getId(),
            $s['entreprise']->getId(),
            $s['valideur']->getId(),
        );

        $this->client->loginUser($this->compte(self::OWNER_EMAIL));
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($reponse['success']);
        self::assertStringContainsString("plus de décision à rendre", $reponse['message']);
    }
}
