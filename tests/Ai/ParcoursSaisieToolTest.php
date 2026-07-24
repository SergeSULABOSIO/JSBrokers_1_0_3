<?php

namespace App\Tests\Ai;

use App\Ai\Parcours\ParcoursCatalogue;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ParcoursSaisieTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le PARCOURS DE SAISIE : ce que Ket présente à l'utilisateur AVANT de préparer
 * quoi que ce soit — le chemin complet d'un objet métier, étape par étape, pour
 * qu'il dise en UNE fois jusqu'où il veut aller.
 *
 * Ce qui est vérifié ici : l'ordre et le rôle des étapes, leur ancrage dans le
 * RÉEL (champs obligatoires issus des métadonnées, collections réellement
 * éditables, référentiels de l'entreprise), les gabarits recopiables, le
 * chaînage par référence, et le fail-closed sur les droits.
 */
class ParcoursSaisieToolTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetParcours';
    private const OWNER = 'phpunit-ketparcours-owner@test.local';
    private const COLLAB = 'phpunit-ketparcours-collab@test.local';

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;
    private ParcoursSaisieTool $tool;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tool = static::getContainer()->get(ParcoursSaisieTool::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:o, :c)', ['o' => self::OWNER, 'c' => self::COLLAB]);
        $conn->executeStatement('DELETE ch FROM chargement ch JOIN entreprise e ON ch.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:o, :c)', ['o' => self::OWNER, 'c' => self::COLLAB]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur} */
    private function seedWorkspace(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);

        $inv = (new Invite())->setNom('Testeur')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $owner->setConnectedTo($ent);
        $this->em->flush();
        // Les champs autocomplete des FormType exigent un utilisateur authentifié
        // (ils scopent sur son entreprise active) — sans quoi l'arbre des
        // formulaires ne se construit pas et aucune collection n'est découverte.
        $this->client->loginUser($owner);

        return [$ent, $inv, $owner];
    }

    /** @return array<string, array> étapes indexées par leur clé */
    private function etapes(array $data): array
    {
        return array_column($data['etapes'], null, 'cle');
    }

    public function testParcoursPropositionDerouleLeCycleMetierComplet(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $result = $this->tool->execute(['sujet' => 'proposition'], new AiScope($ent, $inv));

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Cotation', $result->data['socle']);
        $this->assertSame(
            ['cotation', 'composition-prime', 'echeancier', 'revenu-courtier', 'contrat', 'suivi'],
            array_column($result->data['etapes'], 'cle'),
            'Le parcours suit l’ordre du métier : la cotation, sa prime, son échéancier, la rémunération, le contrat.',
        );

        $etapes = $this->etapes($result->data);
        $this->assertSame(ParcoursCatalogue::ROLE_SOCLE, $etapes['cotation']['role']);
        $this->assertNotSame(ParcoursCatalogue::ROLE_SOCLE, $etapes['contrat']['role'], 'Le contrat reste facultatif.');
        $this->assertNotEmpty($etapes['composition-prime']['informations'], 'Chaque étape dit ce qu’il faut demander.');
    }

    /** Une étape de collection est typée d'après le FORMULAIRE, jamais d'après la trame. */
    public function testEtapeDeCollectionEstAncreeDansLeFormulaireReel(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $data = $this->tool->execute(['sujet' => 'Cotation'], new AiScope($ent, $inv))->data;
        $etapes = $this->etapes($data);

        $this->assertSame('ChargementPourPrime', $etapes['composition-prime']['entite']);
        $this->assertSame('collection:chargements', $etapes['composition-prime']['rattachement']);
        $this->assertSame('chargements', $etapes['composition-prime']['gabarit']['fragment']['collection']);
        $this->assertSame('Tranche', $etapes['echeancier']['entite']);
        $this->assertSame('RevenuPourCourtier', $etapes['revenu-courtier']['entite']);
    }

    /** L'entité de départ peut être désignée par son nom court (« Cotation » => parcours proposition). */
    public function testSujetAccepteLeNomDeLEntiteDeDepart(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $parEntite = $this->tool->execute(['sujet' => 'Cotation'], new AiScope($ent, $inv))->data;
        $parSlug = $this->tool->execute(['sujet' => 'proposition'], new AiScope($ent, $inv))->data;

        $this->assertSame(array_column($parSlug['etapes'], 'cle'), array_column($parEntite['etapes'], 'cle'));
    }

    /**
     * Le gabarit d'une étape chaînée porte le renvoi « @socle » : c'est ce qui
     * permet de créer le client ET sa piste dans le MÊME plan, en UNE validation.
     */
    public function testEtapeChaineeDonneLeGabaritAvecRenvoi(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $etapes = $this->etapes($this->tool->execute(['sujet' => 'client'], new AiScope($ent, $inv))->data);

        $this->assertSame('reference:client', $etapes['opportunite']['rattachement']);
        $this->assertSame('Piste', $etapes['opportunite']['entite']);
        $this->assertSame('@socle', $etapes['opportunite']['gabarit']['fragment']['champs']['client']);
        $this->assertSame('socle', $etapes['client']['gabarit']['fragment']['ref'], 'L’étape socle pose l’étiquette.');
    }

    /** Les champs obligatoires viennent des métadonnées réelles, pas d'une liste écrite à la main. */
    public function testEtapeSocleRestitueLesChampsObligatoiresReels(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $etapes = $this->etapes($this->tool->execute(['sujet' => 'client'], new AiScope($ent, $inv))->data);
        $obligatoires = array_column($etapes['client']['obligatoires'], 'champ');

        $this->assertContains('nom', $obligatoires);
        $this->assertContains('exonere', $obligatoires, 'Le champ non-nullable sans défaut est bien réclamé.');
        $this->assertContains('entreprise', array_column($etapes['client']['auto'], 'champ'), 'L’entreprise est remplie par Ket.');
    }

    /** Le référentiel d'une étape est chargé, scopé à l'entreprise (évite le chargement sans type). */
    public function testEtapeAReferentielRestitueLesValeursDeLEntreprise(): void
    {
        [$ent, $inv] = $this->seedWorkspace();
        $chargement = (new \App\Entity\Chargement())->setNom('Prime nette');
        $chargement->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($chargement);
        $this->em->flush();

        $etapes = $this->etapes($this->tool->execute(['sujet' => 'proposition'], new AiScope($ent, $inv))->data);
        $ref = $etapes['composition-prime']['valeursReferentiel'] ?? null;

        $this->assertNotNull($ref, 'Les types de chargement disponibles sont fournis à Ket.');
        $this->assertSame('Chargement', $ref['entite']);
        $this->assertContains('Prime nette', array_column($ref['valeurs'], 'nom'));
    }

    /** Une entité sans trame rédigée reçoit un parcours DÉRIVÉ de son formulaire. */
    public function testEntiteSansTrameRecoitUnParcoursGenerique(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $data = $this->tool->execute(['sujet' => 'Piste'], new AiScope($ent, $inv))->data;

        $this->assertSame('Piste', $data['socle']);
        $cles = array_column($data['etapes'], 'cle');
        $this->assertSame('piste', $cles[0], 'L’entité elle-même ouvre le parcours.');
        $this->assertContains('cotations', $cles, 'Ses collections éditables deviennent des étapes facultatives.');
    }

    /** FAIL-CLOSED : hors du périmètre mutable, aucun parcours n'est proposé. */
    public function testSujetHorsPerimetreNeProduitAucunParcours(): void
    {
        [$ent, $inv] = $this->seedWorkspace();

        $this->assertSame(
            AiToolResult::STATUS_HORS_PERIMETRE,
            $this->tool->execute(['sujet' => 'Invite'], new AiScope($ent, $inv))->status,
            'La gestion des invités n’est jamais un parcours de saisie.',
        );
        $this->assertSame(
            AiToolResult::STATUS_INTROUVABLE,
            $this->tool->execute(['sujet' => ''], new AiScope($ent, $inv))->status,
        );
    }

    /** FAIL-CLOSED : un invité sans droit d'écriture sur le socle n'obtient pas le parcours. */
    public function testInviteSansDroitDEcritureNObtientPasLeParcours(): void
    {
        // Collaborateur (distinct du propriétaire) à qui aucun niveau n'a été
        // accordé sur les propositions : fail-closed, il n'a même pas le chemin.
        [$ent] = $this->seedWorkspace();
        $collab = (new Utilisateur())->setEmail(self::COLLAB)->setNom('Collaborateur')->setVerified(true);
        $collab->setPassword('x');
        $this->em->persist($collab);
        $inv = (new Invite())->setNom('Collab')->setUtilisateur($collab)->setEntreprise($ent)->setProprietaire(false);
        $this->em->persist($inv);
        $this->em->flush();

        $this->assertSame(
            AiToolResult::STATUS_HORS_PERIMETRE,
            $this->tool->execute(['sujet' => 'proposition'], new AiScope($ent, $inv))->status,
        );
    }
}
