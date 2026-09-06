<?php

namespace App\Tests\Echange;

use App\Ai\Finance\EconomieTranche;
use App\Echange\Etat\CatalogueDesColonnes;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Etat\ProducteurDeLEtat;
use App\Echange\Classeur\EcrivainJsbx;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * L'ÉTAT DU PORTEFEUILLE : trois feuilles, une ligne par tranche, et des chiffres qui
 * sont CEUX DE L'ÉCRAN.
 *
 * Ce que ces tests protègent avant tout : qu'aucune colonne ne se branche sur le mauvais
 * indicateur. C'est l'erreur la plus difficile à voir — un montant plausible en face d'un
 * libellé plausible — et la plus grave, puisque ce fichier sort du cabinet et sert à
 * discuter avec un assureur ou un partenaire.
 */
class EtatDuPortefeuilleTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-etat-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit État SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit État Autre SARL';

    /** Commission de courtage due par l'ASSUREUR : l'assiette des deux taxes. */
    private const COMMISSION = 100.0;
    private const TAUX_TVA = 16.0;
    private const TAUX_ARCA = 2.0;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La forme du classeur
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ TROIS FEUILLES, ET PAS UNE DE PLUS.
     *
     * Le format d'échange en comptait quarante-cinq. L'état n'a rien à faire relire : ni
     * feuille de listes déroulantes, ni ligne de codes techniques masquée. Une feuille de
     * plus signifierait qu'on a recopié le classeur d'échange au lieu d'en écrire un
     * autre.
     */
    public function testLeClasseurNAQueTroisFeuilles(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $classeur = $this->produire($entreprise, $invite);

        self::assertSame(
            [EcrivainJsbx::FEUILLE_MANIFESTE, EcrivainJsbx::FEUILLE_DICTIONNAIRE, EtatDuPortefeuille::FEUILLE],
            $classeur->getSheetNames(),
        );
        self::assertNull(
            $classeur->getSheetByName(EcrivainJsbx::FEUILLE_LISTES),
            'Un état en lecture seule n\'a aucune liste déroulante à proposer.',
        );
    }

    /** Une ligne par tranche du cabinet, et l'en-tête n'en occupe qu'une. */
    public function testUneLigneParTranche(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $feuille = $this->produire($entreprise, $invite)->getSheetByName(EtatDuPortefeuille::FEUILLE);

        // Le seed pose UNE tranche pour ce cabinet.
        self::assertSame(2, $feuille->getHighestDataRow(), 'Un en-tête, une tranche.');
    }

    /**
     * ⚠ LE PÉRIMÈTRE TIENT. Un état ne doit jamais laisser filtrer la tranche d'un autre
     * cabinet : c'est la garantie qui compte le plus dans un fichier qui sort.
     */
    public function testLeScopingParCabinetTient(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $texte = $this->texteDe($this->produire($entreprise, $invite));

        self::assertStringContainsString('POL-ETAT-A', $texte);
        self::assertStringNotContainsString(
            'POL-ETAT-B',
            $texte,
            'La police d\'un autre cabinet ne doit apparaître nulle part.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les chiffres
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE FICHIER DIT CE QUE DIT L'ÉCRAN, au centime.
     *
     * On compare colonne à colonne avec `EconomieTranche::depuis()`, qui projette les
     * mêmes indicateurs que la rubrique Tranches. Une colonne branchée sur le mauvais
     * indicateur passerait tous les autres tests : elle porterait un nombre, au bon
     * format, simplement faux.
     */
    public function testLesMontantsSontCeuxDesIndicateurs(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'tranche' => $tranche] = $this->seed();

        $ligne = $this->ligneDe($this->produire($entreprise, $invite));

        static::getContainer()->get(TranchePaiementService::class)->chargerIndicateurs([$tranche]);
        $attendu = EconomieTranche::depuis($tranche);
        self::assertNotEmpty($attendu, 'La tranche doit être hydratée, sans quoi la comparaison ne prouve rien.');

        $paires = [
            'Prime · Totale' => 'primeTranche',
            'Prime · Payée' => 'primeSignalee',
            'Prime · Solde' => 'primeSolde',
            'Commission · HT' => 'commissionHt',
            'Commission · TTC' => 'commissionTtc',
            'Commission · Encaissée' => 'commissionEncaissee',
            'Commission · Solde' => 'commissionSolde',
        ];

        foreach ($paires as $libelle => $cle) {
            self::assertEqualsWithDelta(
                (float) $attendu[$cle],
                (float) $ligne[$libelle],
                0.001,
                sprintf('« %s » ne dit pas la même chose que l\'écran.', $libelle),
            );
        }
    }

    /**
     * ⚠ LES TAUX SORTENT EN POINTS (16 = 16 %), la convention unique du projet.
     *
     * Les écrire en fraction (0,16) créerait une seconde vérité : l'écran affiche des
     * points, le fichier des fractions, et le premier qui recalcule une taxe à partir du
     * fichier se trompe d'un facteur cent.
     */
    public function testLesTauxDeTaxeSortentEnPoints(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $ligne = $this->ligneDe($this->produire($entreprise, $invite));

        $tauxCourtier = $this->valeurCommencantPar($ligne, 'Taxe courtier', 'Taux');
        $tauxAssureur = $this->valeurCommencantPar($ligne, 'Taxe assureur', 'Taux');

        self::assertEqualsWithDelta(self::TAUX_ARCA, (float) $tauxCourtier, 0.001);
        self::assertEqualsWithDelta(self::TAUX_TVA, (float) $tauxAssureur, 0.001);
    }

    /**
     * L'en-tête des taxes porte le NOM paramétré dans le cabinet : un montant de taxe
     * sans le nom de la taxe ne se rattache à rien.
     */
    public function testLesEnTetesDeTaxePortentLeNomParametre(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $entetes = array_keys($this->ligneDe($this->produire($entreprise, $invite)));
        $joints = implode(' | ', $entetes);

        self::assertStringContainsString('ARCA-ETAT', $joints, 'La taxe du courtier doit être nommée.');
        self::assertStringContainsString('TVA-ETAT', $joints, 'La taxe de l\'assureur doit être nommée.');
    }

    /**
     * ⚠ PLUSIEURS RÈGLEMENTS, UNE SEULE LIGNE : la date est celle du DERNIER, les
     * références sont TOUTES là — et leur somme égale la prime payée d'à côté.
     *
     * C'est le test qui interdit aux références de contredire le montant qu'elles sont
     * censées justifier.
     */
    public function testLesReglementsMultiplesNeContredisentPasLeMontant(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $ligne = $this->ligneDe($this->produire($entreprise, $invite));

        $references = (string) $ligne['Prime · Références des règlements'];
        self::assertStringContainsString('ET-A-001', $references);
        self::assertStringContainsString('ET-A-002', $references);

        // 400 + 200 : la somme des règlements listés EST la prime payée annoncée.
        self::assertEqualsWithDelta(600.0, (float) $ligne['Prime · Payée'], 0.001);
    }

    /**
     * ⚠ L'EXIGIBILITÉ D'UNE TAXE SUIT LA COMMISSION ENCAISSÉE.
     *
     * Rien n'ayant été encaissé dans ce jeu, aucune part n'est réclamable. Un montant non
     * nul ici voudrait dire qu'on réclame une taxe sur un revenu qui n'est pas rentré.
     */
    public function testLaTaxeNEstPasExigibleTantQueRienNEstEncaisse(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $ligne = $this->ligneDe($this->produire($entreprise, $invite));

        self::assertEqualsWithDelta(
            0.0,
            (float) $this->valeurCommencantPar($ligne, 'Taxe courtier', 'Exigible'),
            0.001,
            'Aucune commission encaissée : aucune taxe réclamable.',
        );
    }

    /** Le catalogue et l'assembleur se répondent : aucune colonne orpheline. */
    public function testChaqueColonneDuCatalogueEstRemplissable(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $entetes = array_keys($this->ligneDe($this->produire($entreprise, $invite)));
        $catalogue = array_map(
            static fn ($colonne) => $colonne->libelle,
            array_values(CatalogueDesColonnes::pour('ARCA-ETAT', 'TVA-ETAT')),
        );

        self::assertSame(
            $catalogue,
            $entetes,
            'L\'en-tête du fichier doit être exactement le catalogue, dans son ordre.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Lecture du classeur
    // ─────────────────────────────────────────────────────────────────────────────

    private function produire(Entreprise $entreprise, Invite $invite): Spreadsheet
    {
        // ⚠ `produire()` et JAMAIS `exporter()` : le second facture. Un test qui débite
        // le compte d'un cabinet est un test qui coûte de l'argent à chaque exécution.
        [$classeur] = static::getContainer()->get(ProducteurDeLEtat::class)
            ->produire($entreprise, $invite, $entreprise->getUtilisateur());

        return $classeur;
    }

    /** La première ligne de données, indexée par LIBELLÉ de colonne. */
    private function ligneDe(Spreadsheet $classeur): array
    {
        $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        self::assertNotNull($feuille);

        $derniere = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        $ligne = [];
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            $entete = (string) $feuille->getCell($lettre . '1')->getValue();
            $ligne[$entete] = $feuille->getCell($lettre . '2')->getValue();
        }

        return $ligne;
    }

    /** La valeur d'une colonne dont on ne connaît que le début et la fin du libellé. */
    private function valeurCommencantPar(array $ligne, string $prefixe, string $suffixe): mixed
    {
        foreach ($ligne as $libelle => $valeur) {
            if (str_starts_with($libelle, $prefixe) && str_ends_with($libelle, $suffixe)) {
                return $valeur;
            }
        }

        self::fail(sprintf('Aucune colonne « %s … %s ».', $prefixe, $suffixe));
    }

    private function texteDe(Spreadsheet $classeur): string
    {
        $texte = '';
        foreach ($classeur->getSheetByName(EtatDuPortefeuille::FEUILLE)->toArray(null, false, false, false) as $ligne) {
            $texte .= implode(' | ', array_map(static fn ($v) => (string) $v, $ligne)) . "\n";
        }

        return $texte;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array{entreprise: Entreprise, invite: Invite, tranche: Tranche} */
    private function seed(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())
            ->setEmail(self::OWNER_EMAIL)
            ->setNom('PHPUnit État')
            ->setVerified(true)
            ->setPassword('irrelevant');
        $em->persist($owner);

        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $owner);
        $owner->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Propriétaire État');
        $invite->setUtilisateur($owner)->setEntreprise($entreprise)->setProprietaire(true);
        $em->persist($invite);

        // Les deux taxes SUR LA COMMISSION du cabinet : sans elles, la commission TTC
        // vaudrait le HT et les taux resteraient à zéro.
        $this->makeTaxe($entreprise, 'TVA-ETAT', Taxe::REDEVABLE_ASSUREUR, self::TAUX_TVA);
        $this->makeTaxe($entreprise, 'ARCA-ETAT', Taxe::REDEVABLE_COURTIER, self::TAUX_ARCA);

        $tranche = $this->makeChaine($entreprise, $invite, 'A', 1000.0);
        $this->makeSignalement($entreprise, $tranche, 'ET-A-001', 400.0, '-40 days');
        $this->makeSignalement($entreprise, $tranche, 'ET-A-002', 200.0, '-5 days');

        // Un SECOND cabinet, dont rien ne doit filtrer dans l'état du premier.
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $owner);
        $inviteB = (new Invite())->setNom('Propriétaire B');
        $inviteB->setUtilisateur($owner)->setEntreprise($entrepriseB)->setProprietaire(true);
        $em->persist($inviteB);
        $this->makeChaine($entrepriseB, $inviteB, 'B', 800.0);

        $em->flush();
        $em->clear();

        // Le calcul des montants de taxe passe par ServiceTaxes, qui lit le paramétrage de
        // l'entreprise ACTIVE de l'utilisateur connecté : l'écran l'est toujours.
        $this->connecter();

        return [
            'entreprise' => $em->getRepository(Entreprise::class)->find($entreprise->getId()),
            'invite' => $em->getRepository(Invite::class)->find($invite->getId()),
            'tranche' => $em->getRepository(Tranche::class)->find($tranche->getId()),
        ];
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setLicence('LIC-ETAT')
            ->setAdresse('1 rue de l\'État')
            ->setTelephone('+243000000003')
            ->setRccm('RCCM-ETAT')
            ->setIdnat('IDNAT-ETAT')
            ->setNumimpot('IMP-ETAT')
            ->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /** Client → piste → cotation (prime + commission) → avenant → tranche. */
    private function makeChaine(Entreprise $entreprise, Invite $gestionnaire, string $suffixe, float $prime): Tranche
    {
        $em = $this->em();

        $client = (new Client())->setNom('Client État ' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())
            ->setNom('Piste État ' . $suffixe)
            ->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque de test état')
            ->setExercice(2026)
            ->setClient($client);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        $assureur = (new Assureur())
            ->setNom('Assureur État ' . $suffixe)
            ->setEmail(sprintf('assureur-etat-%s@example.test', strtolower($suffixe)))
            ->setNumimpot('IMP-ET-' . $suffixe)
            ->setIdnat('NAT-ET-' . $suffixe)
            ->setRccm('RCCM-ET-' . $suffixe);
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $cotation = (new Cotation())->setNom('Cotation État ' . $suffixe)->setDuree(365);
        $cotation->setPiste($piste)->setAssureur($assureur);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $avenant = (new Avenant())
            ->setReferencePolice('POL-ETAT-' . $suffixe)
            ->setNumero('0')
            ->setDescription('Police de test état')
            ->setStartingAt(new \DateTimeImmutable('-60 days'))
            ->setEndingAt(new \DateTimeImmutable('+305 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $chargement = (new ChargementPourPrime())
            ->setNom('Prime État ' . $suffixe)
            ->setMontantFlatExceptionel($prime)
            ->setCotation($cotation);
        $chargement->setEntreprise($entreprise);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())
            ->setNom('Commission État ' . $suffixe)
            ->setMontantflat(self::COMMISSION)
            ->setShared(false)
            ->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())
            ->setNom('Revenu État ' . $suffixe)
            ->setTypeRevenu($typeRevenu)
            ->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $tranche = (new Tranche())
            ->setNom('Tranche État ' . $suffixe)
            ->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-60 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        return $tranche;
    }

    private function makeTaxe(Entreprise $entreprise, string $code, int $redevable, float $taux): Taxe
    {
        $taxe = (new Taxe())
            ->setCode($code)
            ->setDescription('Taxe de test ' . $code)
            ->setRedevable($redevable)
            ->setTauxIARD((string) $taux)
            ->setTauxVIE((string) $taux);
        $taxe->setEntreprise($entreprise);
        $this->em()->persist($taxe);

        return $taxe;
    }

    private function makeSignalement(Entreprise $entreprise, Tranche $tranche, string $reference, float $montant, string $quand): void
    {
        $paiement = (new PaiementPrime())
            ->setReference($reference)
            ->setMontant($montant)
            ->setPaidAt(new \DateTimeImmutable($quand))
            ->setDescription('Avis de règlement ' . $reference)
            ->setTranche($tranche);
        $paiement->setEntreprise($entreprise);
        $this->em()->persist($paiement);
    }

    private function connecter(): void
    {
        $owner = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($owner, 'main', $owner->getRoles()),
        );
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge ciblée sur les cabinets de ce test : enfants avant parents. */
    private function nettoyer(): void
    {
        $conn = $this->em()->getConnection();
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        $tables = [
            'echange_occurrence', 'paiement_prime', 'tranche', 'chargement_pour_prime',
            'revenu_pour_courtier', 'avenant', 'cotation', 'type_revenu', 'assureur',
            'piste', 'client', 'invite', 'taxe',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email = :email',
            ['email' => self::OWNER_EMAIL],
        );
        $conn->executeStatement(
            'DELETE FROM entreprise WHERE nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }
}
