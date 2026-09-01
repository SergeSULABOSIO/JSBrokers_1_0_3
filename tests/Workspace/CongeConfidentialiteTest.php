<?php

namespace App\Tests\Workspace;

use App\Entity\DemandeConge;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Service\Conge\DemandeCongePolicy;
use App\Services\Search\CongeVisibiliteScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LES CONGÉS SONT DES DONNÉES PERSONNELLES — scénario 20 de la recette.
 *
 * ── UN ÉCART ASSUMÉ AU MODÈLE DE L'APPLICATION ──────────────────────────────────────
 * Partout ailleurs, le droit est un interrupteur : qui a la Lecture voit tout le cabinet.
 * Ici non. Un arrêt maladie n'est pas une police, et la visibilité par défaut se limite à
 * ses propres demandes. Seul le valideur — niveau Modification — voit celles des autres.
 *
 * ── DEUX GARDES, ET LES DEUX COMPTENT ───────────────────────────────────────────────
 * Masquer une ligne de liste ne protège rien si la fiche du collègue reste ouverte à qui
 * devine son identifiant. Ce test vérifie donc la LISTE (le critère, posé en SQL et non
 * retirable) et la FICHE (la garde objet du contrôleur).
 */
class CongeConfidentialiteTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-confid-owner@test.local';
    private const ALICE_EMAIL = 'phpunit-conge-confid-alice@test.local';
    private const BOB_EMAIL = 'phpunit-conge-confid-bob@test.local';
    private const ENT = 'PHPUnit Congés Confidentialité SARL';

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
        foreach ([self::OWNER_EMAIL, self::ALICE_EMAIL, self::BOB_EMAIL] as $email) {
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
            'DELETE FROM utilisateur WHERE email IN (:a, :b, :c)',
            ['a' => self::OWNER_EMAIL, 'b' => self::ALICE_EMAIL, 'c' => self::BOB_EMAIL],
        );
        $this->em()->clear();
    }

    /**
     * @return array{entreprise: Entreprise, proprietaire: Invite, alice: Invite, bob: Invite,
     *               demandeAlice: DemandeConge, demandeBob: DemandeConge}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Patron')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $type = (new TypeAbsence())->setCode(TypeAbsence::CODE_CONGE_ANNUEL)->setLibelle('Congé annuel')
            ->setDecompte(true)->setJustificatifRequis(false)->setAutoriseDemiJournee(true)->setActif(true);
        $type->setEntreprise($ent);
        $em->persist($type);

        $invites = [];
        foreach ([['alice', 'Alice Mukendi', self::ALICE_EMAIL], ['bob', 'Bob Kabila', self::BOB_EMAIL]] as [$cle, $nom, $email]) {
            $compte = (new Utilisateur())->setEmail($email)->setNom($nom)->setVerified(true)->setPassword('x');
            $compte->setConnectedTo($ent);
            $em->persist($compte);

            $invite = (new Invite())->setNom($nom)->setProprietaire(false);
            $invite->setUtilisateur($compte)->setEntreprise($ent);
            $em->persist($invite);

            // L'accès de base : lire et poser, jamais valider.
            $roles = (new RolesEnAdministration())->setNom('Congés');
            $roles->setAccessConge([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
            $roles->setInvite($invite)->setEntreprise($ent);
            $em->persist($roles);

            $invites[$cle] = $invite;
        }

        $em->flush();
        $em->refresh($invites['alice']);
        $em->refresh($invites['bob']);

        $demandes = [];
        foreach (['alice' => '2026-11-02', 'bob' => '2026-11-09'] as $cle => $debut) {
            $demande = new DemandeConge();
            $demande->setAgent($invites[$cle])->setTypeAbsence($type);
            $demande->setDateDebut(new \DateTimeImmutable($debut));
            $demande->setDateFin((new \DateTimeImmutable($debut))->modify('+4 days'));
            $demande->setEntreprise($ent);
            $demande->setStatut(DemandeConge::STATUT_SOUMISE);
            $demande->setNbJours('5.0');
            $em->persist($demande);
            $demandes[$cle] = $demande;
        }

        $em->flush();

        return [
            'entreprise' => $ent,
            'proprietaire' => $proprietaire,
            'alice' => $invites['alice'],
            'bob' => $invites['bob'],
            'demandeAlice' => $demandes['alice'],
            'demandeBob' => $demandes['bob'],
        ];
    }

    private function compte(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    // ═══════════ La règle elle-même ═══════════

    public function testUnCollaborateurNeVoitQueSesPropresDemandes(): void
    {
        $s = $this->semer();
        $policy = static::getContainer()->get(DemandeCongePolicy::class);

        self::assertTrue($policy->peutVoir($s['alice'], $s['demandeAlice']));
        self::assertFalse(
            $policy->peutVoir($s['alice'], $s['demandeBob']),
            "Un collaborateur ne voit pas la demande d'un collègue : c'est une donnée personnelle.",
        );
    }

    public function testUnValideurVoitLesDemandesDuCabinet(): void
    {
        $s = $this->semer();
        $policy = static::getContainer()->get(DemandeCongePolicy::class);

        self::assertTrue($policy->peutVoir($s['proprietaire'], $s['demandeAlice']));
        self::assertTrue($policy->peutVoir($s['proprietaire'], $s['demandeBob']));
    }

    /**
     * LE FILTRE EST POSÉ EN SQL, pas après coup.
     *
     * Un filtrage en mémoire fausserait la pagination : « 3 sur 40 » annoncerait à
     * quelqu'un qu'il en existe quarante — et ce nombre serait déjà une fuite.
     */
    public function testLePerimetreEstUnCritereDeRequete(): void
    {
        $s = $this->semer();
        $scope = static::getContainer()->get(CongeVisibiliteScope::class);

        $critereAlice = $scope->critereFor($s['alice']);
        self::assertArrayHasKey(CongeVisibiliteScope::CHAMP_AGENT, $critereAlice);
        self::assertSame($s['alice']->getId(), $critereAlice[CongeVisibiliteScope::CHAMP_AGENT]['value']);

        self::assertSame(
            [],
            $scope->critereFor($s['proprietaire']),
            'Un valideur ne subit aucune restriction : il doit voir la file du cabinet.',
        );
    }

    /**
     * LA LISTE ELLE-MÊME EST FILTRÉE, et le filtre n'est pas retirable.
     *
     * Le critère est réinjecté par le contrôleur APRÈS la charge utile du navigateur : un
     * collaborateur qui efface le badge « Mes demandes » — ou qui poste une requête sans
     * lui — ne l'efface que pour l'affichage, jamais pour la requête.
     */
    public function testLaListeNeRendQueSesPropresDemandesMemeSansCritere(): void
    {
        $s = $this->semer();

        $this->client->loginUser($this->compte(self::ALICE_EMAIL));
        $this->client->request(
            'POST',
            sprintf(
                '/admin/demandeconge/api/dynamic-query/%d/%d',
                $s['alice']->getId(),
                $s['entreprise']->getId(),
            ),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            // AUCUN critère : c'est exactement ce qu'enverrait quelqu'un qui a effacé le
            // badge, ou qui rejoue la requête à la main.
            json_encode(['criteria' => [], 'page' => 1]),
        );

        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertStringContainsString('Alice Mukendi', $reponse['html']);
        self::assertStringNotContainsString(
            'Bob Kabila',
            $reponse['html'],
            "La demande d'un collègue ne doit pas apparaître, même sans critère envoyé.",
        );
        self::assertSame(
            1,
            $reponse['pagination']['totalItems'],
            "Le décompte lui-même doit être filtré : annoncer « 1 sur 2 » serait déjà une fuite.",
        );
    }

    /** Le valideur, lui, voit la file entière : c'est ce qu'on attend de lui. */
    public function testLaListeDuValideurPorteToutLeCabinet(): void
    {
        $s = $this->semer();

        $this->client->loginUser($this->compte(self::OWNER_EMAIL));
        $this->client->request(
            'POST',
            sprintf(
                '/admin/demandeconge/api/dynamic-query/%d/%d',
                $s['proprietaire']->getId(),
                $s['entreprise']->getId(),
            ),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => [], 'page' => 1]),
        );

        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertStringContainsString('Alice Mukendi', $reponse['html']);
        self::assertStringContainsString('Bob Kabila', $reponse['html']);
        self::assertSame(2, $reponse['pagination']['totalItems']);
    }

    // ═══════════ Scénario 20 : l'URL forgée ═══════════

    /**
     * UN IDENTIFIANT DEVINÉ NE DOIT RIEN OUVRIR. Masquer la ligne de liste ne protège rien
     * si la fiche reste accessible à qui tape une URL.
     */
    public function testUneUrlForgeeVersLaDemandeDUnCollegueEstRefusee(): void
    {
        $s = $this->semer();
        $idBob = $s['demandeBob']->getId();
        $idEntreprise = $s['entreprise']->getId();
        $idAlice = $s['alice']->getId();

        $this->client->loginUser($this->compte(self::ALICE_EMAIL));
        $this->client->request('GET', sprintf(
            '/admin/demandeconge/api/get-form/%d?idEntreprise=%d&idInvite=%d',
            $idBob,
            $idEntreprise,
            $idAlice,
        ));

        self::assertSame(
            403,
            $this->client->getResponse()->getStatusCode(),
            "Alice ne doit pas pouvoir ouvrir la demande de Bob en devinant son identifiant.",
        );
    }

    /** Et sa propre demande, elle, s'ouvre normalement : la garde protège sans gêner. */
    public function testSaPropreDemandeSOuvreNormalement(): void
    {
        $s = $this->semer();

        $this->client->loginUser($this->compte(self::ALICE_EMAIL));
        $this->client->request('GET', sprintf(
            '/admin/demandeconge/api/get-form/%d?idEntreprise=%d&idInvite=%d',
            $s['demandeAlice']->getId(),
            $s['entreprise']->getId(),
            $s['alice']->getId(),
        ));

        self::assertResponseIsSuccessful();
    }

    /** Le valideur, lui, ouvre la demande de n'importe qui : c'est son travail. */
    public function testLeValideurOuvreLaDemandeDeNImporteQui(): void
    {
        $s = $this->semer();

        $this->client->loginUser($this->compte(self::OWNER_EMAIL));
        $this->client->request('GET', sprintf(
            '/admin/demandeconge/api/get-form/%d?idEntreprise=%d&idInvite=%d',
            $s['demandeBob']->getId(),
            $s['entreprise']->getId(),
            $s['proprietaire']->getId(),
        ));

        self::assertResponseIsSuccessful();
    }

    /**
     * LA DÉCISION AUSSI EST GARDÉE : un collaborateur ne décide pas, même en tapant l'URL
     * du picker de décision d'un collègue.
     */
    public function testUnNonValideurNObtientPasLeGesteDeDecisionSurAutrui(): void
    {
        $s = $this->semer();

        $this->client->loginUser($this->compte(self::ALICE_EMAIL));
        $this->client->request('POST', sprintf(
            '/admin/demandeconge/api/decision/%d?geste=approuver&idEntreprise=%d&idInvite=%d',
            $s['demandeBob']->getId(),
            $s['entreprise']->getId(),
            $s['alice']->getId(),
        ));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
