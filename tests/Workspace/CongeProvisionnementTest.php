<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\TypeAbsence;
use App\Entity\Utilisateur;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\CongeParametres;
use App\Services\ServiceInitialisationEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UN CABINET NEUF PEUT POSER UN CONGÉ SANS AUCUN PARAMÉTRAGE MANUEL.
 *
 * Scénarios 23 et 25 de la recette. Sans ce semis, la rubrique s'ouvre sur une liste de
 * types vide et un compteur à zéro : la première demande est refusée par le contrôle de
 * solde, ce qui ressemble à une panne et non à un réglage manquant.
 *
 * ── L'IDEMPOTENCE N'EST PAS UN LUXE ─────────────────────────────────────────────────
 * Le même semis est rejoué par la commande `app:conges:provisionner` sur les cabinets
 * déjà en service. Rejoué sans garde, il doublerait la dotation de chacun — en silence,
 * et personne ne s'en apercevrait avant que quelqu'un ne prenne des jours qu'il n'a pas.
 */
class CongeProvisionnementTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-conge-provision@test.local';
    private const ENT = 'PHPUnit Congés Provision SARL';

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

    private function initialisation(): ServiceInitialisationEntreprise
    {
        return static::getContainer()->get(ServiceInitialisationEntreprise::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        // TAXE ET AUTORITÉ FISCALE SE POINTENT MUTUELLEMENT : aucun ordre de suppression
        // ne peut les départager. On casse le cycle d'abord, en dénouant un des deux
        // liens, puis l'ordre ordinaire suffit.
        $conn->executeStatement(
            'UPDATE autorite_fiscale a JOIN entreprise e ON a.entreprise_id = e.id SET a.taxe_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENT],
        );

        // L'ORDRE EST CELUI DES CLÉS ÉTRANGÈRES, des enfants vers les parents :
        // type_revenu pointe sur chargement. Nettoyer un parent d'abord fait échouer la
        // contrainte — et le test se met alors à parler d'autre chose que de ce qu'il
        // mesure.
        foreach ([
            'mouvement_conge', 'historique_demande', 'demande_conge', 'regime_travail',
            'jour_ferie', 'type_absence', 'roles_en_administration',
            'type_revenu', 'chargement', 'taxe', 'autorite_fiscale',
            'risque', 'groupe', 'monnaie', 'invite',
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

    /** @return array{entreprise: Entreprise, proprietaire: Invite} */
    private function cabinetNeuf(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Provision')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($ent);
        $owner->setConnectedTo($ent);

        $proprietaire = (new Invite())->setNom('Le Patron')->setProprietaire(true);
        $proprietaire->setUtilisateur($owner)->setEntreprise($ent);
        $em->persist($proprietaire);

        $em->flush();
        $em->refresh($ent);

        return ['entreprise' => $ent, 'proprietaire' => $proprietaire];
    }

    /** @return TypeAbsence[] */
    private function typesDe(Entreprise $ent): array
    {
        return $this->em()->getRepository(TypeAbsence::class)->findBy(['entreprise' => $ent], ['id' => 'ASC']);
    }

    // ═══════════ Scénario 25 : un cabinet neuf est immédiatement utilisable ═══════════

    public function testUnCabinetNeufRecoitLesCinqTypesDAbsence(): void
    {
        $c = $this->cabinetNeuf();

        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $codes = array_map(static fn (TypeAbsence $t) => $t->getCode(), $this->typesDe($c['entreprise']));

        self::assertEqualsCanonicalizing(
            [
                TypeAbsence::CODE_CONGE_ANNUEL,
                TypeAbsence::CODE_SANS_SOLDE,
                TypeAbsence::CODE_MALADIE,
                TypeAbsence::CODE_EVENEMENT_FAMILIAL,
                TypeAbsence::CODE_RECUPERATION,
            ],
            $codes,
        );
    }

    /**
     * `decompte` est le champ qui décide de tout : une maladie décomptée par inadvertance
     * retirerait des jours de congé annuel à qui subit un arrêt de travail.
     */
    public function testSeulsLeCongeAnnuelEtLaRecuperationDecomptent(): void
    {
        $c = $this->cabinetNeuf();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $decomptes = [];
        foreach ($this->typesDe($c['entreprise']) as $type) {
            $decomptes[$type->getCode()] = $type->isDecompte();
        }

        self::assertTrue($decomptes[TypeAbsence::CODE_CONGE_ANNUEL]);
        self::assertTrue($decomptes[TypeAbsence::CODE_RECUPERATION]);
        self::assertFalse($decomptes[TypeAbsence::CODE_MALADIE], 'Un arrêt de travail n\'est pas un congé.');
        self::assertFalse($decomptes[TypeAbsence::CODE_EVENEMENT_FAMILIAL]);
        self::assertFalse($decomptes[TypeAbsence::CODE_SANS_SOLDE]);

        // La maladie et l'événement familial exigent une pièce ; le congé annuel, non.
        $justificatifs = [];
        foreach ($this->typesDe($c['entreprise']) as $type) {
            $justificatifs[$type->getCode()] = $type->isJustificatifRequis();
        }
        self::assertTrue($justificatifs[TypeAbsence::CODE_MALADIE]);
        self::assertFalse($justificatifs[TypeAbsence::CODE_CONGE_ANNUEL]);
    }

    public function testLeProprietaireRecoitSaDotationAuProrata(): void
    {
        $c = $this->cabinetNeuf();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');
        $attendu = CongeParametres::dotationAuProrata(
            CongeParametres::DOTATION_ANNUELLE_DEFAUT,
            $c['proprietaire']->getCreatedAt() ?? new \DateTimeImmutable('now'),
            $exercice,
        );

        $solde = static::getContainer()->get(CalculateurSolde::class)->pour($c['proprietaire'], $exercice);

        self::assertGreaterThan(0.0, $solde->acquis, 'Un compteur à zéro ressemble à une panne.');
        self::assertSame($attendu, $solde->acquis);
        self::assertSame($attendu, $solde->disponible());
    }

    /** La dotation est bien rattachée au CONGÉ ANNUEL, sinon elle serait invisible du solde. */
    public function testLaDotationEstRattacheeAuCongeAnnuel(): void
    {
        $c = $this->cabinetNeuf();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $mouvements = $this->em()->getRepository(MouvementConge::class)
            ->findBy(['entreprise' => $c['entreprise'], 'nature' => MouvementConge::NATURE_DOTATION]);

        self::assertCount(1, $mouvements);
        self::assertSame(
            TypeAbsence::CODE_CONGE_ANNUEL,
            $mouvements[0]->getTypeAbsence()?->getCode(),
            'Une dotation sans type ne créditerait rien de lisible.',
        );
    }

    /** Aucun jour férié n'est semé : ils dépendent du pays, et les dates mobiles de l'année. */
    public function testAucunJourFerieNEstSeme(): void
    {
        $c = $this->cabinetNeuf();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        self::assertSame(
            [],
            $this->em()->getRepository(\App\Entity\JourFerie::class)->findBy(['entreprise' => $c['entreprise']]),
            "Aucun calendrier n'est fourni d'office : le valideur saisit le sien.",
        );
    }

    // ═══════════ Scénario 23 : le rejeu ne double rien ═══════════

    public function testLeRejeuNeCreePasDeTypeEnDouble(): void
    {
        $c = $this->cabinetNeuf();

        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        self::assertCount(5, $this->typesDe($c['entreprise']), 'Cinq types, rejeu ou non.');
    }

    /**
     * LE REJEU NE DOUBLE PAS LA DOTATION. C'est le risque le plus coûteux du semis : un
     * droit doublé ne se voit que le jour où quelqu'un prend des jours qu'il n'a pas.
     */
    public function testLeRejeuNeDoublePasLaDotation(): void
    {
        $c = $this->cabinetNeuf();

        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();
        $exercice = (int) (new \DateTimeImmutable('now'))->format('Y');
        $apresUnPassage = static::getContainer()->get(CalculateurSolde::class)->pour($c['proprietaire'], $exercice)->acquis;

        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();
        $apresDeuxPassages = static::getContainer()->get(CalculateurSolde::class)->pour($c['proprietaire'], $exercice)->acquis;

        self::assertSame($apresUnPassage, $apresDeuxPassages);
        self::assertCount(
            1,
            $this->em()->getRepository(MouvementConge::class)
                ->findBy(['agent' => $c['proprietaire'], 'exercice' => $exercice, 'nature' => MouvementConge::NATURE_DOTATION]),
        );
    }

    /**
     * UN TYPE DÉSACTIVÉ NE REVIENT PAS. Le cabinet l'a volontairement retiré de la saisie ;
     * le recréer au rejeu défairait son réglage.
     */
    public function testLeRejeuNeRessusciteoasUnTypeDesactive(): void
    {
        $c = $this->cabinetNeuf();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $recup = $this->em()->getRepository(TypeAbsence::class)
            ->findOneBy(['entreprise' => $c['entreprise'], 'code' => TypeAbsence::CODE_RECUPERATION]);
        $recup->setActif(false);
        $this->em()->flush();

        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $recups = $this->em()->getRepository(TypeAbsence::class)
            ->findBy(['entreprise' => $c['entreprise'], 'code' => TypeAbsence::CODE_RECUPERATION]);

        self::assertCount(1, $recups, 'Un type désactivé ne doit pas être recréé à côté.');
        self::assertFalse($recups[0]->isActif(), 'Et il doit rester désactivé.');
    }

    /**
     * UN PARAMÈTRE MODIFIÉ PAR LE CABINET N'EST PAS ÉCRASÉ. Le rejeu complète ce qui
     * manque, il ne remet pas les valeurs d'usine.
     */
    public function testLeRejeuNEcrasePasUnParametrageModifie(): void
    {
        $c = $this->cabinetNeuf();
        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();

        $ca = $this->em()->getRepository(TypeAbsence::class)
            ->findOneBy(['entreprise' => $c['entreprise'], 'code' => TypeAbsence::CODE_CONGE_ANNUEL]);
        $ca->setLibelle('Congé payé annuel (convention maison)');
        $ca->setPlafondParDemande('10.0');
        $this->em()->flush();

        $this->initialisation()->initialiser($c['entreprise'], $c['proprietaire']);
        $this->em()->flush();
        $this->em()->refresh($ca);

        self::assertSame('Congé payé annuel (convention maison)', $ca->getLibelle());
        self::assertSame('10.0', $ca->getPlafondParDemande());
    }

    // ═══════════ Le prorata lui-même ═══════════

    /**
     * @dataProvider prorata
     */
    public function testLaDotationSuitLesMoisEntiersDePresence(string $entree, int $exercice, float $attendu): void
    {
        self::assertSame(
            $attendu,
            CongeParametres::dotationAuProrata(26.0, new \DateTimeImmutable($entree), $exercice),
        );
    }

    public static function prorata(): iterable
    {
        // Présent toute l'année : la dotation entière.
        yield 'entré avant l\'exercice' => ['2025-03-01', 2026, 26.0];
        yield 'entré le 1er janvier' => ['2026-01-15', 2026, 26.0];
        // Le mois d'entrée compte : arriver le 3 mars, c'est avoir travaillé en mars.
        yield 'entré en mars' => ['2026-03-03', 2026, 22.0];  // 26 × 10/12 = 21,67 → 22,0
        yield 'entré en juillet' => ['2026-07-20', 2026, 13.0]; // 26 × 6/12 = 13,0
        yield 'entré en décembre' => ['2026-12-31', 2026, 2.5]; // 26 × 1/12 = 2,17 → 2,5
        // Pas encore arrivé : aucun droit sur cet exercice.
        yield 'entré après l\'exercice' => ['2027-02-01', 2026, 0.0];
    }
}
