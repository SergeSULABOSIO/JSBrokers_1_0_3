<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ChronologieTool;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Outil « chronologie » sur la VRAIE base — et c'est ici que se joue l'essentiel.
 *
 * CE QUE LES TESTS UNITAIRES NE PEUVENT PAS PROUVER. La chronologie repose sur des
 * chemins de relations ÉCRITS À LA MAIN, parce que le graphe générique
 * (CheminsDeRelation) ne convenait pas : sa profondeur est bornée à 3 segments, or
 * PaiementPrime rejoint le Client en QUATRE (tranche.cotation.piste.client). Un chemin
 * écrit à la main est un chemin qui peut être FAUX — et un chemin faux ne se voit pas :
 * il ne lève aucune erreur, il rend simplement moins de lignes. Seule une vraie requête
 * SQL sur un vrai schéma le démontre.
 *
 * C'est exactement la classe de défaut que ce chantier corrige depuis le début : une
 * donnée qui existe, que l'assistant ne reçoit pas, et dont il conclut qu'elle n'existe
 * pas — « la date exacte de création du compte n'est pas renseignée dans le système ».
 */
class ChronologieToolIntegrationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-chronologie-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Chronologie SARL';

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

    private function tool(): ChronologieTool
    {
        return static::getContainer()->get(ChronologieTool::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();

        // Enfants avant parents : `avenant` précède `cotation` (FK cotation_id),
        // `assureur` la suit (FK assureur_id portée par la cotation).
        foreach (['paiement_prime', 'tranche', 'chargement_pour_prime', 'avenant', 'cotation', 'assureur', 'piste', 'client', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    /**
     * Un dossier complet, avec des dates de SAISIE volontairement DÉCALÉES des dates
     * métier : la police est saisie le 28/02 mais prend effet le 01/03, la prime est
     * réglée le 15/03 mais signalée le 17/03. C'est ce décalage qui rend le test
     * probant — un outil qui confondrait les deux dates passerait inaperçu sans lui.
     *
     * @return array{entreprise: Entreprise, invite: Invite, client: Client, avenant: Avenant}
     */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit Chronologie')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)
            ->setLicence('LIC-CHR')
            ->setAdresse('1 rue du Temps')
            ->setTelephone('+243000000003')
            ->setRccm('RCCM-CHR')
            ->setIdnat('IDNAT-CHR')
            ->setNumimpot('IMP-CHR')
            ->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Serge');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        // ANTIDATER PASSE PAR SQL, ET C'EST STRUCTUREL. AuditableTrait::onPrePersist()
        // réécrit createdAt à `now()` au moment du flush, sur les 42 entités auditables
        // (toutes déclarent #[ORM\HasLifecycleCallbacks]) : un setCreatedAt() avant
        // persist est systématiquement écrasé. C'est une bonne propriété — la date de
        // saisie n'est pas forgeable depuis le code applicatif — mais elle oblige un test
        // d'historique à repasser derrière, en base.
        $aAntidater = [];
        $horodater = static function (object $e, string $quand) use ($invite, &$aAntidater): object {
            $e->setInvite($invite);
            $aAntidater[] = [$e, $quand];

            return $e;
        };

        $client = (new Client())->setNom('MIC-RC')->setExonere(false);
        $client->setEntreprise($entreprise);
        $horodater($client, '2026-01-12 09:30:00');
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Incendie 2026')
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test chronologie')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise);
        $horodater($piste, '2026-02-03 10:00:00');
        $em->persist($piste);

        $assureur = (new Assureur())
            ->setNom('SFA Assurances')
            ->setEmail('sfa-chrono@example.test')
            ->setNumimpot('IMP-CHR-A')
            ->setIdnat('NAT-CHR-A')
            ->setRccm('RCCM-CHR-A');
        $assureur->setEntreprise($entreprise);
        $horodater($assureur, '2026-01-05 08:00:00');
        $em->persist($assureur);

        $cotation = (new Cotation())->setNom('Proposition SFA')->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $horodater($cotation, '2026-02-20 11:00:00');
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime nette')
            ->setMontantFlatExceptionel(1080.0)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $horodater($chargement, '2026-02-20 11:05:00');
        $em->persist($chargement);

        // SAISIE le 28/02, EFFET le 01/03 : le décalage qui rend ce test probant.
        $avenant = (new Avenant())
            ->setReferencePolice('POL-130')
            ->setNumero('0')
            ->setDescription('Police de test chronologie')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCotation($cotation);
        $avenant->setEntreprise($entreprise);
        $horodater($avenant, '2026-02-28 17:05:00');
        $em->persist($avenant);

        $tranche = (new Tranche())
            ->setNom('Tranche unique')
            ->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('2026-03-01'))
            ->setEcheanceAt(new \DateTimeImmutable('2026-03-31'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $horodater($tranche, '2026-02-28 17:06:00');
        $em->persist($tranche);

        // RÉGLÉE le 15/03, SIGNALÉE le 17/03 — et rattachée au client par QUATRE
        // segments, ce qu'aucun parcours générique n'atteint.
        $paiement = (new PaiementPrime())
            ->setReference('PRIME-001')
            ->setMontant(1080.0)
            ->setPaidAt(new \DateTimeImmutable('2026-03-15'))
            ->setDescription('Avis de règlement')
            ->setTranche($tranche);
        $paiement->setEntreprise($entreprise);
        $horodater($paiement, '2026-03-17 09:00:00');
        $em->persist($paiement);

        $em->flush();

        $conn = $em->getConnection();
        foreach ($aAntidater as [$entite, $quand]) {
            $table = $em->getClassMetadata($entite::class)->getTableName();
            $conn->executeStatement(
                sprintf('UPDATE `%s` SET created_at = :quand WHERE id = :id', $table),
                ['quand' => $quand, 'id' => $entite->getId()],
            );
        }

        $em->clear();

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'invite' => $em->getRepository(Invite::class)->find($invite->getId()),
            'client' => $em->getRepository(Client::class)->find($client->getId()),
            'avenant' => $em->getRepository(Avenant::class)->find($avenant->getId()),
        ];
    }

    /**
     * LE CAS DE L'INCIDENT, contre la vraie base : « quand ce compte a-t-il été créé ? »
     * trouve une réponse, et la chronologie raconte le dossier dans l'ordre des dates
     * MÉTIER — pas dans celui des saisies.
     */
    public function testLaChronologieDUnClientRacontLeDossierDansLOrdreMetier(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'client' => $client] = $this->seed();

        $result = $this->tool()->execute(
            ['entite' => 'Client', 'id' => $client->getId()],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('MIC-RC', $result->data['dossier']);

        $faits = array_map(static fn (array $l) => [$l['date'], $l['fait']], $result->data['lignes']);

        $this->assertSame([
            ['2026-01-12', 'Compte client créé'],
            ['2026-02-03', 'Opportunité ouverte'],
            ['2026-02-20', 'Proposition d\'assureur enregistrée'],
            ['2026-02-28', 'Police enregistrée'],
            ['2026-02-28', 'Échéancier créé'],
            ['2026-03-01', 'Police prend effet'],
            ['2026-03-15', 'Prime réglée par l\'assuré'],
            ['2026-03-17', 'Paiement de prime signalé'],
            ['2026-03-31', 'Échéance de prime'],
            ['2027-02-28', 'Police arrive à échéance'],
        ], $faits);
    }

    /**
     * LE CHEMIN À QUATRE SEGMENTS, prouvé en SQL. Sans lui, les règlements de prime —
     * précisément les lignes que le courtier regardait — disparaissaient de la
     * chronologie, sans la moindre erreur pour le signaler.
     */
    public function testLesReglementsDePrimeRemontentMalgreLeursQuatreSegments(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'client' => $client] = $this->seed();

        $lignes = $this->tool()
            ->execute(['entite' => 'Client', 'id' => $client->getId()], new AiScope($entreprise, $invite))
            ->data['lignes'];

        $reglement = array_values(array_filter(
            $lignes,
            static fn (array $l) => $l['fait'] === 'Prime réglée par l\'assuré',
        ));

        $this->assertCount(1, $reglement, 'Le chemin tranche.cotation.piste.client doit joindre en SQL.');
        $this->assertSame('2026-03-15', $reglement[0]['date'], 'La date métier est celle du règlement.');
        $this->assertSame('2026-03-17', $reglement[0]['saisiLe'], 'La saisie, elle, est deux jours plus tard.');
        $this->assertSame('Serge', $reglement[0]['par']);
    }

    /**
     * Désigner le dossier par une POLICE ramène au client, et la réponse le DIT. C'est
     * la garde contre l'erreur que le graphe générique aurait produite : pris depuis un
     * avenant, il ne trouvait les tranches que par « cotation.piste.avenantDeBase »,
     * c'est-à-dire celles d'un renouvellement dérivé, présentées comme celles du contrat.
     */
    public function testUneAncrePoliceRamenAuDossierDeSonClient(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'avenant' => $avenant] = $this->seed();

        $result = $this->tool()->execute(
            ['entite' => 'Avenant', 'id' => $avenant->getId()],
            new AiScope($entreprise, $invite),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('MIC-RC', $result->data['dossier']);
        $this->assertStringContainsString('POL-130', $result->data['ancre']);
        $this->assertNotEmpty($result->data['lignes']);
    }

    /** La période filtre sur la date MÉTIER, celle qui ordonne la chronologie. */
    public function testLaPeriodeFiltreSurLaDateMetier(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'client' => $client] = $this->seed();

        $lignes = $this->tool()->execute(
            ['entite' => 'Client', 'id' => $client->getId(), 'du' => '2026-03-01', 'au' => '2026-03-31'],
            new AiScope($entreprise, $invite),
        )->data['lignes'];

        $faits = array_map(static fn (array $l) => $l['fait'], $lignes);

        // La police SAISIE le 28/02 est hors fenêtre ; sa PRISE D'EFFET du 01/03 y entre.
        $this->assertSame([
            'Police prend effet',
            'Prime réglée par l\'assuré',
            'Paiement de prime signalé',
            'Échéance de prime',
        ], $faits);
    }
}
