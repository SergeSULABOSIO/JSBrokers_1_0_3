<?php

namespace App\Tests\Workspace;

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
 * LA GRILLE DES COMPTEURS S'OUVRE — ET SE REFUSE À QUI N'EST PAS VALIDEUR.
 *
 * ── CE QUE CE TEST PROTÈGE ──────────────────────────────────────────────────────────
 * La grille expose les soldes de TOUT le cabinet, et deux de ses gestes écrivent sur le
 * compteur d'autrui. Une garde qui saute ici ne fait pas planter l'écran : elle le rend
 * simplement lisible par le mauvais lecteur, sans bruit, sans trace. C'est le genre de
 * régression qu'aucune remontée d'utilisateur ne signale.
 *
 * Le contrat canevas ↔ cerveau est déjà tenu par ContratDesEntreesDeMenuTest ; ce test-ci
 * couvre l'autre moitié : le serveur rend vraiment le panneau, et les chiffres qu'il
 * porte sont ceux du compteur.
 */
class CongeCompteursEcranTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-compteurs-ecran-owner@test.local';
    private const AGENT_EMAIL = 'phpunit-compteurs-ecran-agent@test.local';
    private const ENT = 'PHPUnit Compteurs Écran SARL';

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
            'jour_ferie', 'type_absence', 'periode_blocage', 'parametres_conge',
            'roles_en_administration', 'invite',
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

    /**
     * Le propriétaire est valideur d'office ; Alice ne l'est pas — elle a Lecture et
     * Écriture, pas Modification.
     *
     * @return array{entreprise: Entreprise, valideur: Invite, agent: Invite, exercice: int}
     */
    private function semer(): array
    {
        $em = $this->em();
        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');

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
            ->setDecompte(true)->setActif(true);
        $ca->setEntreprise($ent);
        $em->persist($ca);

        $dotation = (new MouvementConge())
            ->setAgent($agent)->setExercice($exercice)->setTypeAbsence($ca)
            ->setNature(MouvementConge::NATURE_DOTATION)->setQuantite('26.0');
        $dotation->setEntreprise($ent);
        $em->persist($dotation);

        $em->flush();
        $em->refresh($agent);

        return ['entreprise' => $ent, 'valideur' => $valideur, 'agent' => $agent, 'exercice' => $exercice];
    }

    private function entantQue(Invite $qui): void
    {
        $this->client->loginUser($qui->getUtilisateur());
    }

    private function compterMouvements(Entreprise $ent): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mouvement_conge WHERE entreprise_id = :e',
            ['e' => $ent->getId()],
        );
    }

    // ═══════════════════════════ La garde ═══════════════════════════════════════════

    /**
     * UN COLLABORATEUR ORDINAIRE N'ENTRE PAS. Il a pourtant Lecture ET Écriture sur les
     * congés : c'est bien le niveau MODIFICATION qui fait le valideur, et lui seul.
     */
    public function testUnCollaborateurOrdinaireNAccedePasALaGrille(): void
    {
        $s = $this->semer();
        $this->entantQue($s['agent']);

        $this->client->request('GET', '/admin/compteurconge/api/grille');

        self::assertResponseStatusCodeSame(403, "Lecture + Écriture ne suffisent pas : la grille est aux valideurs.");
    }

    /** Et il n'écrit pas davantage sur le compteur d'un collègue. */
    public function testUnCollaborateurOrdinaireNAjustePasUnCompteur(): void
    {
        $s = $this->semer();
        $this->entantQue($s['agent']);

        $this->client->request(
            'POST',
            sprintf('/admin/compteurconge/api/ajustement/%d', $s['agent']->getId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['quantite' => 5, 'motif' => 'Je me sers.']),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->compterMouvements($s['entreprise']), 'Seule la dotation semée doit subsister.');
    }

    // ═══════════════════════════ Le panneau ═════════════════════════════════════════

    public function testLeValideurRecoitLePanneauAvecLesSoldesDeChacun(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request('GET', '/admin/compteurconge/api/grille');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('conge-compteurs', $html, 'Le contrôleur Stimulus doit être branché.');
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('Alice Mukendi', $html);
        self::assertStringContainsString('Le Patron', $html, 'La grille montre tout le monde, même à zéro.');
        self::assertStringContainsString('26,0', $html, 'Le disponible d\'Alice doit être affiché tel quel.');
    }

    /**
     * LE CHANGEMENT D'EXERCICE NE RECHARGE PAS LA BOÎTE : il en remplace le contenu.
     * Sans cela, chaque clic ferait perdre le focus et rejouerait l'animation.
     */
    public function testLeChangementDExerciceRenvoieUnFragment(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request(
            'GET',
            sprintf('/admin/compteurconge/api/grille?fragment=1&exercice=%d', $s['exercice'] - 1),
        );

        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($s['exercice'] - 1, $charge['exercice']);
        self::assertStringContainsString('Alice Mukendi', $charge['html']);
        self::assertStringNotContainsString('<html', $charge['html'], 'Un fragment, pas une page.');
    }

    public function testLeJournalExpliqueLeSoldeLigneParLigne(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request('GET', sprintf(
            '/admin/compteurconge/api/journal/%d?exercice=%d',
            $s['agent']->getId(),
            $s['exercice'],
        ));

        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertStringContainsString('Dotation', $charge['html']);
        self::assertStringContainsString('+26,0', $charge['html'], 'Le signe est porté par le nombre, pas par une couleur.');
    }

    /** Un identifiant venu d'une URL ne traverse pas les cabinets. */
    public function testUnAgentInconnuEstRefuseSansRienDivulguer(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request('GET', '/admin/compteurconge/api/journal/999999999');

        self::assertResponseStatusCodeSame(404);
    }

    // ═══════════════════════ Les gestes qui écrivent ════════════════════════════════

    /**
     * LE FORMULAIRE D'AJUSTEMENT EST RENDU PAR LE SERVEUR, avec le solde courant sous les
     * yeux. Il remplace deux invites du navigateur enchaînées, où l'on décidait combien
     * retirer sans plus voir ce qui restait.
     */
    public function testLeFormulaireDAjustementPorteLeSoldeEtExigeUnMotif(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request('GET', sprintf(
            '/admin/compteurconge/api/ajustement/%d?exercice=%d',
            $s['agent']->getId(),
            $s['exercice'],
        ));

        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertStringContainsString('Ajuster le compteur', $charge['html']);
        self::assertStringContainsString('26,0', $charge['html'], 'Le solde courant reste sous les yeux.');
        self::assertStringContainsString('for="cpt-motif"', $charge['html'], 'Le motif est un champ étiqueté (WCAG 3.3.2).');
        self::assertStringContainsString('aria-required="true"', $charge['html']);
        self::assertSame(1, $this->compterMouvements($s['entreprise']), "Ouvrir le formulaire n'écrit rien.");
    }

    /** Le motif manquant est refusé par le SERVEUR, pas seulement par le navigateur. */
    public function testUnAjustementSansMotifEstRefuseParLeServeur(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request(
            'POST',
            sprintf('/admin/compteurconge/api/ajustement/%d', $s['agent']->getId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['quantite' => 3, 'motif' => '  ', 'exercice' => $s['exercice']]),
        );

        self::assertResponseStatusCodeSame(422);
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($charge['success']);
        self::assertStringContainsStringIgnoringCase('motif', $charge['message']);
        self::assertSame(1, $this->compterMouvements($s['entreprise']), "Rien n'a été écrit.");
    }

    public function testUnAjustementMotiveEntreAuJournal(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request(
            'POST',
            sprintf('/admin/compteurconge/api/ajustement/%d', $s['agent']->getId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'quantite' => -2.5,
                'motif' => 'Reprise de jours accordés par erreur.',
                'exercice' => $s['exercice'],
            ]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->compterMouvements($s['entreprise']));

        $this->em()->clear();
        $agent = $this->em()->getRepository(Invite::class)->find($s['agent']->getId());
        self::assertSame(
            23.5,
            static::getContainer()->get(\App\Service\Conge\CalculateurSolde::class)->pour($agent, $s['exercice'])->disponible(),
        );
    }

    /**
     * L'APERÇU DE SORTIE NE SOLDE RIEN. On regarde avant : sans cela, le simple fait
     * d'ouvrir l'écran deviendrait un acte de gestion.
     */
    public function testLApercuDeSortieNEcritRien(): void
    {
        $s = $this->semer();
        $this->entantQue($s['valideur']);

        $this->client->request('GET', sprintf(
            '/admin/compteurconge/api/sortie/%d?dateFin=%d-06-30',
            $s['agent']->getId(),
            $s['exercice'],
        ));

        self::assertResponseIsSuccessful();
        $charge = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertStringContainsString('Décompte de sortie', $charge['html']);
        self::assertStringContainsString('Enregistrer le décompte', $charge['html'], "L'aperçu propose d'écrire, il n'écrit pas.");
        self::assertSame(1, $this->compterMouvements($s['entreprise']));
    }
}
