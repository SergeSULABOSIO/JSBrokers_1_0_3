<?php

namespace App\Tests\Echange;

use App\Ai\Finance\EconomieTranche;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\EchangeConsulterTool;
use App\Echange\Etat\CatalogueDesColonnes;
use App\Echange\Etat\Charte;
use App\Echange\Etat\EcrivainEtat;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Etat\InjecteurDeTcd;
use App\Echange\Etat\ProducteurDeLEtat;
use App\Echange\Reprise\ValeursMultiples;
use App\Echange\Etat\ValiditeDesTranches;
use App\Services\Search\CotationSouscriptionScope;
use App\Echange\Classeur\EcrivainJsbx;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Chargement;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    /**
     * LA CARTE DE LA PARITÉ : clé de l'état → clé de l'économie de la tranche.
     *
     * Les noms diffèrent parce que chaque vocabulaire est le sien — le fichier parle au
     * courtier, l'économie parle à l'assistant. Les VALEURS, elles, ne peuvent pas
     * différer : c'est ce que le test vérifie, au centime.
     */
    private const CORRESPONDANCES = [
        'primeTotale' => 'primeTranche',
        'primePayee' => 'primeSignalee',
        'primeSolde' => 'primeSolde',
        'commissionTtc' => 'commissionTtc',
        'commissionHt' => 'commissionHt',
        'commissionEncaissee' => 'commissionEncaissee',
        'commissionSolde' => 'commissionSolde',
        'commissionExigible' => 'commissionExigible',
        'taxeCourtierTaux' => 'tauxTaxeCourtier',
        'taxeCourtierMontant' => 'taxeCourtier',
        'taxeCourtierPayee' => 'taxeCourtierPayee',
        'taxeCourtierSolde' => 'taxeCourtierSolde',
        'taxeCourtierExigible' => 'taxeCourtierExigible',
        'taxeAssureurTaux' => 'tauxTaxeAssureur',
        'taxeAssureurMontant' => 'taxeAssureur',
        'taxeAssureurPayee' => 'taxeAssureurPayee',
        'taxeAssureurSolde' => 'taxeAssureurSolde',
        'taxeAssureurExigible' => 'taxeAssureurExigible',
        'commissionPure' => 'commissionPure',
        'reserve' => 'reserve',
        'retroPartenaireDue' => 'retroCommission',
        'retroPartenairePayee' => 'retroReversee',
        'retroPartenaireSolde' => 'retroSolde',
        'retroPartenaireExigible' => 'retroAPayer',
        'retroAgentDue' => 'retroAgentDue',
        'retroAgentPayee' => 'retroAgentReversee',
        'retroAgentSolde' => 'retroAgentSolde',
        'retroAgentExigible' => 'retroAgentExigible',
    ];

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
     * ⚠ TROIS FEUILLES, ET PAS UNE DE PLUS : le dictionnaire, les données, la synthèse.
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
            [EcrivainJsbx::FEUILLE_DICTIONNAIRE, EtatDuPortefeuille::FEUILLE, InjecteurDeTcd::FEUILLE],
            $classeur->getSheetNames(),
        );
        self::assertNull(
            $classeur->getSheetByName(EcrivainJsbx::FEUILLE_LISTES),
            'Un état en lecture seule n\'a aucune liste déroulante à proposer.',
        );
        self::assertNull(
            $classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE),
            'L\'état ne se relit pas : il n\'a ni empreinte ni périmètre à déclarer.',
        );

        // ⚠ LE BANDEAU DE TÊTE NE DOIT PAS DISPARAÎTRE AVEC LE MANIFESTE. Il ouvre le
        // dictionnaire : c'est la première chose que lira celui qui retrouve ce fichier
        // dans six mois, sans l'écran sous les yeux.
        //
        // ⚠ ET IL DISAIT LE CONTRAIRE DE LA VÉRITÉ. Il annonçait « LECTURE SEULE — cet
        // état ne peut pas être réimporté » : exact tant que le fichier ne portait que des
        // résultats, faux depuis qu'il porte aussi ce qui les produit. Un fichier qui se
        // trompe sur sa propre nature est pire qu'un fichier muet — on le range, et on ne
        // le ressort jamais.
        $dictionnaire = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_DICTIONNAIRE);
        self::assertSame(EcrivainEtat::CLE_REIMPORTABLE, $dictionnaire->getCell('A2')->getValue());
        self::assertStringContainsString('SE REDÉPOSE', (string) $dictionnaire->getCell('C2')->getValue());

        // ⚠ ET IL DIT AUSSI CE QUI NE SE RELIT PAS. Sans cette réserve, un courtier
        // corrigerait « Prime · Solde », redéposerait, et ne comprendrait jamais pourquoi
        // l'écran affiche autre chose.
        self::assertStringContainsString('RÉSULTATS', (string) $dictionnaire->getCell('C2')->getValue());

        // L'identité du cabinet a rejoint le dictionnaire, faute de manifeste : c'est elle
        // qui permet de refuser un fichier venu d'un autre cabinet.
        self::assertSame(EcrivainEtat::CLE_CABINET, $dictionnaire->getCell('A5')->getValue());
        self::assertSame(
            (string) $entreprise->getId(),
            (string) $dictionnaire->getCell('B5')->getValue(),
        );
    }

    /** Une ligne par tranche du cabinet, et l'en-tête n'en occupe qu'une. */
    public function testUneLigneParTranche(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $feuille = $this->produire($entreprise, $invite)->getSheetByName(EtatDuPortefeuille::FEUILLE);

        // Le seed pose UNE tranche : un en-tête, une donnée, une ligne de totaux.
        self::assertSame(3, $feuille->getHighestDataRow());
        self::assertSame('TOTAUX', $feuille->getCell('A3')->getValue());
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

        // ⚠ LA RÉFÉRENCE EST LE SERVICE, PAS UN CATALOGUE FABRIQUÉ À CÔTÉ. Les colonnes
        // dépendent désormais du cabinet — une par type de chargement, une par type de
        // revenu. Reconstruire ici un catalogue « équivalent » reviendrait à recopier cette
        // règle, et le test finirait par vérifier la copie plutôt que l'original.
        $catalogue = array_map(
            static fn ($colonne) => $colonne->libelle,
            array_values(static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise)),
        );

        self::assertSame(
            $catalogue,
            $entetes,
            'L\'en-tête du fichier doit être exactement le catalogue, dans son ordre.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────────────
    // La reprise : ce que le fichier doit porter pour se réimporter
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ CHAQUE COLONNE DE SAISIE DÉSIGNE UNE CIBLE QUI EXISTE.
     *
     * Une colonne de saisie déclare OÙ elle s\'écrit (`Entite.propriete`). Une cible fausse
     * n\'est pas une erreur bruyante : la colonne s\'écrirait dans le vide, la reprise
     * rendrait une fiche incomplète, et rien ne le signalerait. Ce test a déjà attrapé deux
     * cibles fausses à l\'écriture — `ConditionPartage.tauxRetroCommission`, qui s\'appelle
     * `taux`, et `Piste.portefeuille`, qui n\'existe pas (le portefeuille vit sur le CLIENT).
     */
    public function testChaqueColonneDeSaisieDesigneUneCibleQuiExiste(): void
    {
        $fautes = [];

        foreach (CatalogueDesColonnes::pour('ARCA', 'TVA') as $code => $colonne) {
            if ($colonne->lectureSeule()) {
                continue;
            }

            $entite = $colonne->entiteCible();
            $propriete = (string) $colonne->proprieteCible();

            // Les colonnes techniques (`_action`) ne visent aucune propriété : c\'est
            // l\'opération elle-même qu\'elles décident.
            if (str_starts_with($propriete, '_')) {
                continue;
            }

            $fqcn = 'App' . chr(92) . 'Entity' . chr(92) . $entite;
            if (!class_exists($fqcn)) {
                $fautes[] = sprintf('%s : entité inconnue « %s »', $code, (string) $entite);
                continue;
            }

            if (!(new \ReflectionClass($fqcn))->hasProperty($propriete)) {
                $fautes[] = sprintf('%s : « %s » n\'a pas de propriété « %s »', $code, (string) $entite, $propriete);
            }
        }

        self::assertSame([], $fautes, implode(' | ', $fautes));
    }

    /**
     * ⚠ UNE COLONNE EST UN RÉSULTAT PAR DÉFAUT, ET LA MAJORITÉ LE RESTE.
     *
     * Le défaut doit pencher du bon côté : une colonne de saisie qu\'on oublie de marquer
     * reste ignorée — un désagrément. L\'inverse relirait un chiffre que l\'application
     * recalcule, et l\'écrirait en base. Ce test tient le rapport de force : l\'état porte
     * une quarantaine de résultats pour une vingtaine de saisies, et l\'inversion de cette
     * proportion signalerait qu\'on a marqué en saisie ce qui se calcule.
     */
    public function testLesResultatsRestentMajoritairesEtNeSeReimportentPas(): void
    {
        $colonnes = CatalogueDesColonnes::pour('ARCA', 'TVA');

        $saisies = 0;
        foreach ($colonnes as $colonne) {
            if (!$colonne->lectureSeule()) {
                ++$saisies;
            }
        }

        self::assertGreaterThan($saisies, count($colonnes) - $saisies, 'Les résultats doivent rester majoritaires.');

        // Nommément : ce qui se calcule ne se relit jamais. Un solde réimporté écraserait
        // une soustraction que l\'application refera de toute façon.
        foreach (['primeSolde', 'commissionEncaissee', 'commissionSolde', 'reserve', 'taxeCourtierExigible', 'retroAgentSolde'] as $calcule) {
            self::assertTrue(
                $colonnes[$calcule]->lectureSeule(),
                sprintf('« %s » se calcule : elle ne doit pas être relue.', $calcule),
            );
        }
    }

    /**
     * ⚠ LA SOMME DES COLONNES DE CHARGEMENT ÉGALE « Prime · Totale » — LIGNE À LIGNE.
     *
     * C'est LA propriété du chantier, et elle tient à un détail : les chargements vivent
     * sur la COTATION, la ligne est une TRANCHE. Y porter les montants de la police ferait
     * qu'une police à quatre échéances les répète quatre fois — et la ligne de totaux
     * compterait chaque chargement quatre fois, pour un chiffre resté plausible.
     *
     * Au prorata de l'échéance, la somme des colonnes fait la prime de SA ligne. Sans ce
     * test, l'erreur ne se verrait qu'en additionnant un export à la main.
     *
     * Vérifié sur le portefeuille réel du cabinet : 79 lignes, aucun écart — ni sur la
     * prime, ni sur la commission.
     */
    public function testLaSommeDesColonnesDeChargementFaitLaPrimeDeLaLigne(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);
        $ligne = $this->ligneDe($this->produire($entreprise, $invite));

        $somme = 0.0;
        $trouvees = 0;
        foreach ($colonnes as $code => $colonne) {
            if (!str_starts_with($code, CatalogueDesColonnes::PREFIXE_CHARGEMENT)) {
                continue;
            }
            ++$trouvees;
            $somme += (float) ($ligne[$colonne->libelle] ?? 0.0);
        }

        self::assertGreaterThan(0, $trouvees, 'Le cabinet a des types de chargement : ils doivent avoir leurs colonnes.');
        self::assertEqualsWithDelta((float) $ligne['Prime · Totale'], $somme, 0.01);
    }

    /**
     * ⚠ ET LA SOMME DES COLONNES DE REVENU FAIT « Commission · TTC », même raison.
     *
     * Ces colonnes-là sont des RÉSULTATS : un revenu n'a pas de montant écrit, il se
     * calcule d'un taux souvent hérité du risque. Elles n'en doivent pas moins se
     * totaliser juste — c'est tout l'objet d'une colonne numérique.
     */
    public function testLaSommeDesColonnesDeRevenuFaitLaCommission(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);
        $ligne = $this->ligneDe($this->produire($entreprise, $invite));

        $somme = 0.0;
        $trouvees = 0;
        foreach ($colonnes as $code => $colonne) {
            if (!str_starts_with($code, CatalogueDesColonnes::PREFIXE_REVENU)) {
                continue;
            }
            ++$trouvees;
            $somme += (float) ($ligne[$colonne->libelle] ?? 0.0);
        }

        self::assertGreaterThan(0, $trouvees);
        self::assertEqualsWithDelta((float) $ligne['Commission · TTC'], $somme, 0.01);
    }

    /**
     * ⚠ DEUX TYPES DE MÊME FONCTION NE FONT QU'UNE COLONNE, ET LEURS MONTANTS S'Y AJOUTENT.
     *
     * C'est la raison d'être des quatre colonnes. Un cabinet nomme ses postes librement —
     * « Frais accessoires », « Sneca », « Frais Arca » —, et le catalogue réel en porte
     * huit là où un autre en porte cinq : une colonne par nom donnait deux exports
     * incomparables pour une même réalité comptable. La FONCTION, elle, est la même
     * partout.
     *
     * ⚠ ET LA SOMME EST LE POINT DÉLICAT. Écraser au lieu d'ajouter est le geste naturel
     * quand on regroupe : la composition cesserait alors de faire la prime, et l'écart
     * passerait pour une erreur de calcul.
     */
    public function testDeuxTypesDeMemeFonctionNeFontQuUneColonne(): void
    {
        ['entreprise' => $entreprise] = $this->seed();

        $em = $this->em();
        $cotation = $em->getRepository(Cotation::class)->findOneBy(['entreprise' => $entreprise]);
        self::assertNotNull($cotation);

        // Deux postes DIFFÉRENTS, de la même fonction — le cas du cabinet réel.
        $avant = 0.0;
        foreach (['Sneca', 'Frais Arca'] as $rang => $nom) {
            $type = (new Chargement())->setNom($nom)->setFonction(Chargement::FONCTION_FRAIS_ADMIN);
            $type->setEntreprise($entreprise);
            $em->persist($type);

            $charge = (new ChargementPourPrime())
                ->setNom($nom)
                ->setType($type)
                ->setMontantFlatExceptionel(100.0 * ($rang + 1));
            $charge->setEntreprise($entreprise);
            $charge->setCotation($cotation);
            $em->persist($charge);
            $avant += 100.0 * ($rang + 1);
        }
        $em->flush();

        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);

        $codes = [];
        foreach (array_keys($colonnes) as $code) {
            if (str_starts_with($code, CatalogueDesColonnes::PREFIXE_CHARGEMENT)) {
                $codes[] = $code;
            }
        }

        // ⚠ QUATRE, JAMAIS PLUS : c'est la demande, et deux types de plus n'y changent rien.
        self::assertCount(4, $codes, 'La prime se décompose en quatre colonnes, pas une de plus.');
        self::assertSame($codes, array_unique($codes));
        self::assertContains(
            CatalogueDesColonnes::codeDeFonction(Chargement::FONCTION_FRAIS_ADMIN),
            $codes,
        );

        // Et les deux montants se retrouvent DANS la même colonne, additionnés.
        // ⚠ `lignes()` indexe par CODE (le langage commun de l'état et de l'économie),
        // là où le classeur indexe par LIBELLÉ (le langage de l'écran).
        $code = CatalogueDesColonnes::codeDeFonction(Chargement::FONCTION_FRAIS_ADMIN);

        $cumul = 0.0;
        foreach (static::getContainer()->get(EtatDuPortefeuille::class)->lignes($entreprise) as $ligne) {
            $cumul += (float) ($ligne[$code] ?? 0.0);
        }
        self::assertGreaterThanOrEqual(
            $avant - 0.01,
            $cumul,
            'Deux postes de même fonction sʼajoutent dans la colonne, ils ne sʼy écrasent pas.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le rendu : charte graphique et lisibilité
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ AUCUNE COULEUR HORS CHARTE DANS LE CLASSEUR PRODUIT.
     *
     * Ce test rend la charte VÉRIFIABLE plutôt que déclarative. Un bleu choisi à l'œil
     * dans un écrivain ne se voit pas en relecture de code — il se voit à l'ouverture du
     * fichier, chez le client, à côté d'une page de l'application qui n'a pas la même
     * teinte. On lit donc les styles réellement écrits, et l'on exige que chacun soit
     * l'un des jetons de `Charte`.
     *
     * On passe par `getCellXfCollection()` : la collection des styles DISTINCTS du
     * classeur. Parcourir les cinq mille cellules donnerait le même verdict en cent fois
     * plus de temps.
     */
    public function testAucuneCouleurNEchappeALaCharte(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $classeur = $this->produire($entreprise, $invite);

        $admises = array_values((new \ReflectionClass(Charte::class))->getConstants());
        // Le noir et l'absence de fond sont les valeurs par défaut de PhpSpreadsheet :
        // elles ne sont écrites par personne, et n'ont donc rien à voir avec la charte.
        $admises[] = 'FF000000';

        $vues = [];
        foreach ($classeur->getCellXfCollection() as $style) {
            $vues[] = $style->getFont()->getColor()->getARGB();
            $vues[] = $style->getFill()->getStartColor()->getARGB();
            $vues[] = $style->getFill()->getEndColor()->getARGB();
            foreach (['getTop', 'getBottom', 'getLeft', 'getRight'] as $cote) {
                $vues[] = $style->getBorders()->{$cote}()->getColor()->getARGB();
            }
        }

        $hors = array_values(array_unique(array_filter(
            $vues,
            static fn (?string $couleur): bool => $couleur !== null && !\in_array($couleur, $admises, true),
        )));

        self::assertSame([], $hors, sprintf('Couleurs hors charte : %s', implode(', ', $hors)));
    }

    /**
     * ⚠ TOUTE EXPLICATION DU DICTIONNAIRE REVIENT À LA LIGNE.
     *
     * Test de non-régression : le retour à la ligne n'était posé que sur les trois
     * bandeaux de tête. Les soixante entrées, elles, étalaient leur explication sur une
     * ligne unique de plusieurs milliers de pixels — un texte qu'on ne lit tout
     * simplement pas, dans la colonne dont c'est pourtant la raison d'être.
     */
    public function testChaqueExplicationDuDictionnaireRevientALaLigne(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $dictionnaire = $this->produire($entreprise, $invite)
            ->getSheetByName(EcrivainJsbx::FEUILLE_DICTIONNAIRE);

        $sansRetour = [];
        for ($l = 2; $l <= $dictionnaire->getHighestDataRow(); ++$l) {
            if ((string) $dictionnaire->getCell('C' . $l)->getValue() === '') {
                continue;
            }
            if (!$dictionnaire->getStyle('C' . $l)->getAlignment()->getWrapText()) {
                $sansRetour[] = $l;
            }
        }

        self::assertSame([], $sansRetour, sprintf(
            'Explications sur une seule ligne, en %s',
            implode(', ', array_map(static fn (int $l): string => 'C' . $l, $sansRetour)),
        ));
    }

    /**
     * ⚠ LA HAUTEUR D'EN-TÊTE VA SUR LA LIGNE D'EN-TÊTE, pas sur la ligne 1.
     *
     * `styleEntete()` posait ses trente pixels sur la ligne 1 en dur. L'en-tête de la
     * synthèse étant en ligne 3, le titre recevait la hauteur et l'en-tête restait à
     * l'étroit, ses libellés sur deux lignes rognés par le bas.
     */
    public function testLEnteteDeLaSyntheseRecoitSaHauteur(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $synthese = $this->produire($entreprise, $invite)->getSheetByName(InjecteurDeTcd::FEUILLE);
        self::assertNotNull($synthese);

        self::assertSame(30.0, $synthese->getRowDimension(3)->getRowHeight());
        self::assertSame('Étiquettes de lignes', $synthese->getCell('A3')->getValue());
    }

    /**
     * Les trois feuilles sortent à l'imprimante sans perdre leur en-tête.
     *
     * Un état du portefeuille se présente, et souvent sur papier. Sans réglage, soixante
     * colonnes partent sur onze feuilles dont dix n'ont plus de titre de colonne : on
     * tient une colonne de nombres dont personne ne sait le nom.
     */
    public function testChaqueFeuilleEstPreteAImprimer(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $classeur = $this->produire($entreprise, $invite);

        foreach ($classeur->getAllSheets() as $feuille) {
            $mise = $feuille->getPageSetup();

            self::assertSame(
                PageSetup::ORIENTATION_LANDSCAPE,
                $mise->getOrientation(),
                sprintf('%s : une table large ne tient pas en portrait.', $feuille->getTitle()),
            );
            self::assertSame(1, $mise->getFitToWidth(), $feuille->getTitle());
            // ⚠ ZÉRO EN HAUTEUR = autant de pages qu'il faut. À 1, mille tranches
            // seraient tassées sur une page, en corps illisible.
            self::assertSame(0, $mise->getFitToHeight(), $feuille->getTitle());
            self::assertNotSame('', (string) $mise->getPrintArea(), $feuille->getTitle());
        }

        // Les feuilles à en-tête répètent leur ligne de titre sur chaque page.
        self::assertSame(
            [1, 1],
            $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE)->getPageSetup()->getRowsToRepeatAtTop(),
        );
        self::assertSame(
            [1, 3],
            $classeur->getSheetByName(InjecteurDeTcd::FEUILLE)->getPageSetup()->getRowsToRepeatAtTop(),
        );
    }

    // La synthèse : des FORMULES, et non un tableau croisé
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ POURQUOI CES TESTS ONT CHANGÉ DE NATURE — 06/09/2026.
     *
     * La synthèse a d'abord été un VRAI tableau croisé, injecté en OOXML brut. Les tests
     * d'alors vérifiaient le paquet : parties présentes, XML bien formé, relations
     * résolues, ordre des enfants conforme au schéma. Tout passait — et Excel a refusé le
     * fichier, puis a PLANTÉ. Ces contrôles disaient que le paquet était cohérent ; ils ne
     * pouvaient pas dire qu'Excel l'accepterait, et ce poste n'a aucun tableur pour juger.
     *
     * Des FORMULES, elles, s'évaluent ici. On relit le classeur produit, on demande le
     * calcul, et l'on compare. C'est la raison du changement : une synthèse dont ce dépôt
     * peut prouver qu'elle dit vrai, plutôt qu'une dont l'utilisateur porte la
     * vérification. `InjecteurDeTcd` reste en place pour le jour où ce sera vérifiable.
     */
    public function testLaSyntheseNeCalculeAucunChiffreEnPhp(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $classeur = $this->produire($entreprise, $invite);
        $synthese = $classeur->getSheetByName(InjecteurDeTcd::FEUILLE);

        self::assertNotNull($synthese);
        self::assertSame(
            [EcrivainJsbx::FEUILLE_DICTIONNAIRE, EtatDuPortefeuille::FEUILLE, InjecteurDeTcd::FEUILLE],
            $classeur->getSheetNames(),
        );

        $derniere = $synthese->getHighestDataRow();
        self::assertSame('Total général', $synthese->getCell('A' . $derniere)->getValue());

        // ⚠ AUCUNE VALEUR FIGÉE. Un nombre écrit en dur mentirait dès la première
        // correction d'une ligne de données, sans que rien ne le signale.
        $formules = 0;
        for ($l = 4; $l <= $derniere; ++$l) {
            $valeur = $synthese->getCell('B' . $l)->getValue();
            self::assertIsString($valeur, sprintf('Ligne %d : la valeur doit être une formule.', $l));
            self::assertStringStartsWith('=', $valeur);
            ++$formules;
        }

        self::assertGreaterThan(0, $formules);
    }

    /**
     * LA SYNTHÈSE DIT-ELLE LA MÊME CHOSE QUE LES DONNÉES ?
     *
     * Deux égalités, et il en faut bien DEUX :
     *
     *  1. le total général égale la somme de la colonne — il attrape une plage décalée ;
     *  2. ⚠ LES GROUPES REFONT CE TOTAL — et lui seul attrape un groupe dont le libellé
     *     n'existe pas dans les données. C'est le défaut qu'a eu cette feuille : les
     *     tranches sans date d'effet étaient regroupées sous un nom inventé par la
     *     synthèse, leur somme conditionnelle ne trouvait rien, et le groupe affichait
     *     0,00 pour 4 952,50 réels. Le total général, lui, restait juste.
     */
    public function testLaSyntheseRefaitLesChiffresDesDonnees(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $producteur = static::getContainer()->get(ProducteurDeLEtat::class);
        [$classeur] = $producteur->produire($entreprise, $invite, $entreprise->getUtilisateur());

        $chemin = (string) tempnam(sys_get_temp_dir(), 'jsbx_test_');

        try {
            $producteur->ecrireSur($classeur, $chemin);

            $relu = IOFactory::createReader('Xlsx')->load($chemin);
            $synthese = $relu->getSheetByName(InjecteurDeTcd::FEUILLE);
            $donnees = $relu->getSheetByName(EtatDuPortefeuille::FEUILLE);
            self::assertNotNull($synthese);
            self::assertNotNull($donnees);

            $ligneTotal = $synthese->getHighestDataRow();
            $total = (float) $synthese->getCell('B' . $ligneTotal)->getCalculatedValue();

            // La colonne sommée, retrouvée par son libellé : coder une position se
            // périmerait au premier export restreint.
            $libelle = str_replace('Somme de ', '', (string) $synthese->getCell('B3')->getValue());
            $lettre = $this->colonneParLibelle($donnees, $libelle);

            $somme = 0.0;
            for ($l = 2; $l < $donnees->getHighestDataRow(); ++$l) {
                $somme += (float) $donnees->getCell($lettre . $l)->getValue();
            }

            self::assertEqualsWithDelta($somme, $total, 0.01, 'Le total ne refait pas la colonne.');

            $groupes = 0.0;
            for ($l = 4; $l < $ligneTotal; ++$l) {
                if ($synthese->getStyle('A' . $l)->getAlignment()->getIndent() > 0) {
                    continue; // une sous-ligne, déjà comptée dans son groupe
                }
                $groupes += (float) $synthese->getCell('B' . $l)->getCalculatedValue();
            }

            self::assertEqualsWithDelta($total, $groupes, 0.01, 'Les groupes ne refont pas le total.');
        } finally {
            @unlink($chemin);
        }
    }

    /**
     * ⚠ LES PLAGES S'ARRÊTENT AVANT LA LIGNE DE TOTAUX DE `DONNEES`.
     *
     * L'y inclure ferait compter une seconde fois chaque montant : la synthèse afficherait
     * exactement le double, et le chiffre resterait parfaitement plausible.
     */
    public function testLesPlagesSarretentAvantLaLigneDeTotaux(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $classeur = $this->produire($entreprise, $invite);
        $synthese = $classeur->getSheetByName(InjecteurDeTcd::FEUILLE);
        $donnees = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        self::assertNotNull($synthese);

        $attendue = $donnees->getHighestDataRow() - 1;

        for ($l = 4; $l <= $synthese->getHighestDataRow(); ++$l) {
            $formule = (string) $synthese->getCell('B' . $l)->getValue();
            self::assertSame(
                1,
                preg_match(
                    '/' . EtatDuPortefeuille::FEUILLE . '!\$?[A-Z]+\$?2:\$?[A-Z]+\$?(\d+)/',
                    $formule,
                    $trouve,
                ),
                sprintf('Ligne %d : la formule doit pointer une plage de DONNEES.', $l),
            );
            self::assertSame($attendue, (int) $trouve[1], sprintf('Ligne %d : plage trop longue.', $l));
        }
    }

    /**
     * ⚠ AUCUN LIBELLÉ DE MOIS NE DOIT SE LAISSER LIRE COMME UNE DATE.
     *
     * Test de non-régression, et il vient d'un défaut réel. Les mois s'écrivaient
     * « 01 janv », « 02 févr »… : le rang devant servait à les trier. Mais `strtotime`
     * reconnaît « jan » et « mar », si bien que « 01 janv » et « 03 mars » — et EUX SEULS —
     * étaient convertis en dates par le moteur de formules. Leur somme conditionnelle ne
     * trouvait plus rien : janvier annonçait 0,00 quand la somme brute valait 605 715,10.
     *
     * Les dix autres mois tombaient juste, ce qui rendait le défaut presque invisible.
     */
    public function testAucunLibelleDeMoisNeSeLitCommeUneDate(): void
    {
        $libelles = EtatDuPortefeuille::MOIS;
        $libelles[] = EtatDuPortefeuille::SANS_MOIS;

        foreach ($libelles as $libelle) {
            self::assertFalse(
                strtotime($libelle),
                sprintf('« %s » est lu comme une date : sa somme conditionnelle rendra zéro.', $libelle),
            );
        }
    }

    /**
     * Les mois se suivent dans l'ordre du calendrier, et l'inconnu passe en queue.
     *
     * Le libellé ne porte plus son rang : sans tri explicite, la feuille commencerait par
     * août et finirait par septembre.
     */
    public function testLesMoisSuiventLordreDuCalendrier(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $synthese = $this->produire($entreprise, $invite)->getSheetByName(InjecteurDeTcd::FEUILLE);
        self::assertNotNull($synthese);

        $rangs = [];
        for ($l = 4; $l < $synthese->getHighestDataRow(); ++$l) {
            if ($synthese->getStyle('A' . $l)->getAlignment()->getIndent() > 0) {
                continue;
            }
            $rang = array_search(
                (string) $synthese->getCell('A' . $l)->getValue(),
                EtatDuPortefeuille::MOIS,
                true,
            );
            $rangs[] = $rang === false ? \PHP_INT_MAX : $rang;
        }

        $ordonnes = $rangs;
        sort($ordonnes);
        self::assertSame($ordonnes, $rangs, 'Les mois ne suivent pas le calendrier.');
    }

    /** La lettre de la colonne de DONNEES qui porte ce libellé. */
    private function colonneParLibelle(Worksheet $donnees, string $libelle): string
    {
        $derniere = Coordinate::columnIndexFromString($donnees->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            if ((string) $donnees->getCell($lettre . '1')->getValue() === $libelle) {
                return $lettre;
            }
        }

        self::fail(sprintf('Colonne introuvable : %s', $libelle));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les chips de validité
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LA PARTITION EST COMPLÈTE : souscrites ⊎ en attente ⊎ caduques = toutes.
     *
     * C'est ce qui rend les chips honnêtes. Si les trois ne se recomposaient pas en
     * « toutes », un utilisateur qui les parcourt tous croirait avoir vu son portefeuille
     * entier alors qu'une part lui échapperait — et rien à l'écran ne le dirait.
     */
    public function testLesTroisStatutsPartitionnentLesTranches(): void
    {
        ['entreprise' => $entreprise] = $this->seed();

        $etat = static::getContainer()->get(EtatDuPortefeuille::class);

        $toutes = $etat->compterLignes($entreprise, ValiditeDesTranches::TOUTES);
        $somme = 0;
        foreach ([
            CotationSouscriptionScope::STATUT_SOUSCRITES,
            CotationSouscriptionScope::STATUT_EN_ATTENTE,
            CotationSouscriptionScope::STATUT_CADUQUES,
        ] as $statut) {
            $somme += $etat->compterLignes($entreprise, $statut);
        }

        self::assertSame($toutes, $somme, 'Les trois statuts doivent recomposer exactement le tout.');
    }

    /**
     * ⚠ UNE TRANCHE DE POLICE N'EST PAS UN PROJET, et réciproquement.
     *
     * Le fixture pose une proposition AVEC son avenant : c'est une police. Le chip des
     * projets ne doit donc rien porter — mêler les deux dans un état qu'on présente à un
     * assureur reviendrait à annoncer un portefeuille qu'on n'a pas.
     */
    public function testUnePoliceNestPasUnProjet(): void
    {
        ['entreprise' => $entreprise] = $this->seed();

        $etat = static::getContainer()->get(EtatDuPortefeuille::class);

        self::assertSame(
            1,
            $etat->compterLignes($entreprise, CotationSouscriptionScope::STATUT_SOUSCRITES),
            'La proposition porte un avenant : elle est souscrite.',
        );
        self::assertSame(
            0,
            $etat->compterLignes($entreprise, CotationSouscriptionScope::STATUT_EN_ATTENTE),
            'Aucun projet : la seule proposition du jeu est devenue une police.',
        );
        self::assertSame(
            0,
            $etat->compterLignes($entreprise, CotationSouscriptionScope::STATUT_CADUQUES),
            'Aucune concurrente perdante.',
        );
    }

    /**
     * ⚠ LE FICHIER DIT QUEL PÉRIMÈTRE IL PORTE.
     *
     * Un état des seuls projets ressemble trait pour trait à un état de polices : mêmes
     * colonnes, montants de même allure. Sans cette ligne, on le confondrait avec le
     * portefeuille réel — et six mois plus tard, personne ne pourrait trancher.
     */
    public function testLeDictionnaireAnnonceLePerimetreRetenu(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        [$classeur] = static::getContainer()->get(ProducteurDeLEtat::class)->produire(
            $entreprise,
            $invite,
            $entreprise->getUtilisateur(),
            [],
            CotationSouscriptionScope::STATUT_EN_ATTENTE,
        );

        $dictionnaire = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_DICTIONNAIRE);
        self::assertSame('PÉRIMÈTRE', $dictionnaire->getCell('A3')->getValue());
        self::assertStringContainsString('Projets', (string) $dictionnaire->getCell('B3')->getValue());
        self::assertStringContainsString(
            'ne sont pas des créances',
            (string) $dictionnaire->getCell('C3')->getValue(),
        );
    }

    /** Le vocabulaire des chips vient de la source unique, jamais d'une liste recopiée. */
    public function testLeVocabulaireDesChipsVientDeLaSourceUnique(): void
    {
        $valeurs = array_keys(ValiditeDesTranches::valeurs());

        self::assertContains(CotationSouscriptionScope::STATUT_SOUSCRITES, $valeurs);
        self::assertContains(CotationSouscriptionScope::STATUT_EN_ATTENTE, $valeurs);
        self::assertContains(CotationSouscriptionScope::STATUT_CADUQUES, $valeurs);
        self::assertContains(ValiditeDesTranches::TOUTES, $valeurs);
        self::assertCount(4, $valeurs, 'Quatre chips, et pas un de plus.');
    }

    /**
     * ⚠ LA RÉFÉRENCE DU BORDEREAU A SA COLONNE, dans la famille Commission.
     *
     * Une commission s'encaisse par facture d'articles OU par bordereau ; sans cette
     * colonne, le second circuit restait invisible même une fois son montant compté.
     */
    public function testLaReferenceDuBordereauALaSienne(): void
    {
        $catalogue = CatalogueDesColonnes::pour('ARCA', 'TVA');

        self::assertArrayHasKey('commissionBordereaux', $catalogue);
        self::assertSame('Commission', $catalogue['commissionBordereaux']->groupe());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Parité Ket
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ KET VOIT EXACTEMENT LES COLONNES QUE LE FICHIER PORTE — les dynamiques comprises.
     *
     * La doctrine de la rubrique : « une capacité présente à l'écran et absente du chat
     * est un défaut, au même titre qu'un test rouge ». Elle vaut pour les COLONNES : un
     * courtier qui lit « Prime · Fronting » dans son export et le demande à Ket doit être
     * compris.
     *
     * ⚠ ET C'EST LE POINT DE PASSAGE UNIQUE QUI LE GARANTIT. Le fichier, le gabarit,
     * l'écran de choix, l'import et les deux outils de Ket appellent tous
     * `EtatDuPortefeuille::colonnes($entreprise)` — personne n'appelle le catalogue
     * directement. Le jour où quelqu'un le ferait, il raterait les colonnes du cabinet
     * sans s'en apercevoir : ce test le dirait.
     */
    public function testKetVoitLesMemesColonnesQueLeFichier(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $attendues = array_keys(
            static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise),
        );

        $resultat = static::getContainer()->get(EchangeConsulterTool::class)->execute(
            ['sujet' => 'colonnes'],
            new AiScope($entreprise, $invite, null),
        );

        $vues = [];
        foreach ($resultat->data['colonnes_etat'] ?? [] as $colonne) {
            $vues[] = $colonne['code'];
        }

        self::assertSame($attendues, $vues, 'Ket doit énumérer exactement les colonnes du fichier.');

        // Nommément : les colonnes du CABINET, celles qu'un catalogue figé ne connaîtrait pas.
        $dynamiques = array_filter(
            $vues,
            static fn (string $code): bool => str_starts_with($code, CatalogueDesColonnes::PREFIXE_CHARGEMENT),
        );
        self::assertNotEmpty($dynamiques, 'Les colonnes de chargement du cabinet doivent en être.');
    }

    /**
     * ⚠ LE TEST QUI FAIT LA PARITÉ, ET QUI DOIT RESTER.
     *
     * La doctrine de la rubrique est écrite dans PariteKetImportTest : « une capacité
     * présente à l'écran et absente du chat est un défaut, au même titre qu'un test
     * rouge ». Appliquée aux chiffres, elle dit ceci : tout montant que le fichier
     * affiche, l'assistant doit savoir le dire — et le dire PAREIL.
     *
     * On classe donc CHAQUE clé de la ligne d'état. Une clé nouvelle qui ne serait ni
     * reliée à l'économie de la tranche, ni inscrite dans le hors-économie, fait échouer
     * ce test : on est forcé de trancher plutôt que d'oublier.
     */
    public function testChaqueChiffreDeLEtatEstDisibleParKet(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite, 'tranche' => $tranche] = $this->seed();

        $ligne = $this->ligneBrute($entreprise);

        static::getContainer()->get(TranchePaiementService::class)->chargerIndicateurs([$tranche]);
        $eco = EconomieTranche::depuis($tranche);
        self::assertNotEmpty($eco);

        // CE QUI N'EST PAS DE L'ÉCONOMIE : identité, contexte, dates et pièces. Ces
        // notions n'ont pas leur place dans EconomieTranche — elle est STATIQUE et lit une
        // tranche hydratée, quand les pièces exigent le helper d'indicateurs (le circuit
        // bordereau n'a aucune trace sur la tranche elle-même).
        $horsEconomie = [
            'id', 'policeDateEffet', 'policeEcheance', 'policeReference', 'policeNumeroAvenant',
            'policeMoisEffet',
            'trancheNom', 'tranchePayableAt', 'trancheEcheanceAt',
            'assure', 'risque', 'assureur',
            'primeDerniereLe', 'primeReferences',
            'commissionDerniereLe', 'commissionReferences', 'commissionComptes', 'commissionBordereaux',
            'taxeCourtierPayeeLe', 'taxeCourtierReferences',
            'taxeAssureurPayeeLe', 'taxeAssureurReferences',
            'intermediaire', 'intermediairePart',
            'retroPartenairePayeeLe', 'retroPartenaireReferences', 'retroPartenaireLots', 'retroPartenaireComptes',
            'retroAgentBeneficiaire',
            'retroAgentPayeeLe', 'retroAgentReferences', 'retroAgentLots', 'retroAgentComptes',

            // ⚠ ET CE QUI N\'EST PAS UN RÉSULTAT MAIS UNE ENTRÉE. Ces colonnes existent pour
            // que le classeur se RÉIMPORTE : elles portent ce qui PRODUIT les chiffres — la
            // composition de la prime, les types de revenu, le poids de l\'échéance — et non
            // ce que ces chiffres valent. `EconomieTranche` projette une économie CALCULÉE ;
            // lui demander de dire ses propres entrées serait lui faire remonter le courant.
            //
            // Ket ne perd rien au change : la prime, la commission et les taux qui en
            // sortent, il les dit déjà — par `primeTranche`, `commissionTtc` et les taux de
            // taxe, tous reliés ci-dessous.
            '_action',
            'tranchePart', 'trancheMontantFlat',
            'portefeuille',
            'primeChargements', 'commissionRevenus',

            // Les SOLDES D'OUVERTURE relèvent de la même famille : ils décrivent une
            // situation de DÉPART qu'on écrit une fois, non un chiffre que l'application
            // tient à jour. Ce que l'application en fait, elle, Ket le dit déjà — par
            // `primeSignalee`, `commissionEncaissee` et `retroReversee`, reliés ci-dessous.
            'ouverturePrimeEncaissee', 'ouverturePrimeLe',
            'ouvertureCommissionEncaissee', 'ouvertureCommissionLe',
            'ouvertureRetroReversee', 'ouvertureRetroLe',
        ];

        // La correspondance clé d'état → clé d'économie. Les noms diffèrent parce que
        // chaque vocabulaire est le sien ; les VALEURS, elles, ne peuvent pas différer.
        $correspondances = self::CORRESPONDANCES;

        foreach (array_keys($ligne) as $cle) {
            // ⚠ LES COLONNES DYNAMIQUES SE CLASSENT PAR PRÉFIXE, ET NON NOMMÉMENT. Il y en
            // a une par type de chargement et par type de revenu du cabinet : les nommer
            // dans ce test le rendrait faux au premier cabinet qui ajoute un type, et
            // impossible à tenir. Ce sont des DÉCOMPOSITIONS de « Prime · Totale » et de
            // « Commission · TTC », que Ket dit déjà — il ne perd rien au change.
            if (str_starts_with($cle, CatalogueDesColonnes::PREFIXE_CHARGEMENT)
                || str_starts_with($cle, CatalogueDesColonnes::PREFIXE_REVENU)) {
                continue;
            }

            self::assertTrue(
                in_array($cle, $horsEconomie, true) || isset($correspondances[$cle]),
                sprintf(
                    "« %s » n'est ni classée hors économie, ni reliée à EconomieTranche : "
                    . "l'écran saurait la dire, le chat non.",
                    $cle,
                ),
            );
        }

        // ⚠ ET LES VALEURS COÏNCIDENT, au centime. Une correspondance déclarée sur la
        // mauvaise clé passerait la vérification ci-dessus sans rien garantir.
        foreach ($correspondances as $cleEtat => $cleEco) {
            self::assertEqualsWithDelta(
                (float) ($eco[$cleEco] ?? 0.0),
                (float) ($ligne[$cleEtat] ?? 0.0),
                0.001,
                sprintf('« %s » diffère entre le fichier et ce que Ket sait dire.', $cleEtat),
            );
        }
    }

    /**
     * ⚠ LA RÉTROCOMMISSION DES AGENTS, dont Ket ne savait RIEN dire.
     *
     * Ses indicateurs existaient depuis longtemps ; la projection de l'assistant les
     * ignorait. Un courtier qui demandait « combien dois-je à mes agents sur cette
     * échéance ? » obtenait un silence, alors que l'écran l'affichait.
     */
    public function testKetSaitDireLaRetroDesAgents(): void
    {
        foreach (['retroAgentDue', 'retroAgentReversee', 'retroAgentSolde', 'retroAgentExigible'] as $cle) {
            self::assertArrayHasKey(
                $cle,
                EconomieTranche::ROLES,
                sprintf("Sans rôle de présentation, « %s » sortirait sans format ni alignement.", $cle),
            );
        }
    }

    /** Toute clé projetée par l'économie porte un rôle de présentation. */
    public function testChaqueCleDeLEconomieAUnRole(): void
    {
        ['tranche' => $tranche] = $this->seed();

        static::getContainer()->get(TranchePaiementService::class)->chargerIndicateurs([$tranche]);

        foreach (array_keys(EconomieTranche::depuis($tranche)) as $cle) {
            self::assertArrayHasKey($cle, EconomieTranche::ROLES, sprintf("« %s » n'a pas de rôle.", $cle));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La ligne de totaux
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ UNE FORMULE, PAS UN NOMBRE ÉCRIT.
     *
     * Un total figé ment dès qu'on touche au fichier : on corrige une cellule, on supprime
     * une ligne, et le bas de page continue d'afficher l'ancien. On vérifie donc la
     * formule ÉCRITE — c'est elle qu'Excel évaluera, et c'est elle qui peut être fausse —
     * plutôt qu'une valeur que PhpSpreadsheet recalculerait de son côté.
     *
     * `SUBTOTAL(109)` et non `SUM` : 109 ne somme que les lignes VISIBLES, si bien que
     * filtrer sur un assureur fait suivre les totaux.
     */
    public function testLaLigneDeTotauxPorteUneFormuleQuiSuitLeFiltre(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $feuille = $this->produire($entreprise, $invite)->getSheetByName(EtatDuPortefeuille::FEUILLE);
        $totaux = $feuille->getHighestDataRow();

        $lettre = $this->lettreDe($feuille, 'Prime · Totale');
        $formule = (string) $feuille->getCell($lettre . $totaux)->getValue();

        self::assertStringStartsWith('=SUBTOTAL(109,', $formule, 'Le total doit suivre le filtre.');
        // La plage couvre les données, et RIEN d'autre : ni l'en-tête, ni la ligne
        // elle-même — une formule qui s'inclut est une référence circulaire.
        self::assertStringContainsString(
            sprintf('%s2:%s%d', $lettre, $lettre, $totaux - 1),
            $formule,
        );
    }

    /**
     * ⚠ ON NE TOTALISE QUE CE QUI S'ADDITIONNE. Sommer des taux, des dates ou des
     * identifiants de tranche donnerait un nombre parfaitement calculé et parfaitement
     * absurde. Le rôle de la colonne le dit déjà : on n'invente aucune liste.
     */
    public function testSeulesLesColonnesSommablesPortentUnTotal(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $feuille = $this->produire($entreprise, $invite)->getSheetByName(EtatDuPortefeuille::FEUILLE);
        $totaux = $feuille->getHighestDataRow();

        foreach (['id', 'Assuré', 'Prime · Dernier règlement le'] as $libelle) {
            $lettre = $this->lettreDe($feuille, $libelle);
            $valeur = $feuille->getCell($lettre . $totaux)->getValue();
            self::assertTrue(
                $valeur === null || $valeur === '' || $libelle === 'id',
                sprintf("« %s » ne s'additionne pas et ne doit pas porter de total.", $libelle),
            );
        }
    }

    /**
     * ⚠ LE FILTRE S'ARRÊTE AVANT LES TOTAUX. L'y inclure ferait voyager cette ligne au
     * milieu des données au premier tri — un total posé entre deux tranches.
     */
    public function testLeFiltreAutomatiqueExclutLaLigneDeTotaux(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $feuille = $this->produire($entreprise, $invite)->getSheetByName(EtatDuPortefeuille::FEUILLE);
        $totaux = $feuille->getHighestDataRow();

        $plage = $feuille->getAutoFilter()->getRange();
        self::assertNotSame('', $plage, "L'en-tête doit porter un filtre.");
        self::assertStringEndsWith((string) ($totaux - 1), $plage);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le choix des colonnes
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ AUCUNE COLONNE NE RESTE SANS GROUPE.
     *
     * Le groupe se déduit du libellé ; une colonne qui n'en aurait pas serait invisible à
     * l'écran tout en sortant dans le fichier — on exporterait ce qu'on ne peut pas voir.
     */
    public function testChaqueColonneAUnGroupe(): void
    {
        foreach (CatalogueDesColonnes::pour('ARCA', 'TVA') as $code => $colonne) {
            self::assertNotSame('', $colonne->groupe(), sprintf("« %s » n'a pas de groupe.", $code));
        }
    }

    /**
     * ⚠ `id` REVIENT MÊME SI ON NE LA DEMANDE PAS.
     *
     * Elle rattache chaque ligne à sa tranche ; un état dont aucune ligne ne se rattache
     * n'est plus un état. La garantie est côté SERVEUR : la case désactivée de l'écran
     * n'engage que le navigateur, jamais l'adresse qu'on tape à la main.
     */
    public function testLaColonneDIdentiteRevientToujours(): void
    {
        ['entreprise' => $entreprise] = $this->seed();

        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)
            ->colonnes($entreprise, ['primeTotale']);

        self::assertSame(
            [EtatDuPortefeuille::COLONNE_IDENTITE, 'primeTotale'],
            array_keys($colonnes),
            "L'ordre reste celui du catalogue, jamais celui de la demande.",
        );
    }

    /** Un état restreint ne porte QUE les colonnes demandées. */
    public function testUnEtatRestreintNePorteQueSesColonnes(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        [$classeur] = static::getContainer()->get(ProducteurDeLEtat::class)
            ->produire($entreprise, $invite, $entreprise->getUtilisateur(), ['primeTotale']);

        $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        self::assertSame('id', $feuille->getCell('A1')->getValue());
        self::assertSame('Prime · Totale', $feuille->getCell('B1')->getValue());
        self::assertSame('B', $feuille->getHighestDataColumn(), 'Deux colonnes, et pas une de plus.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Lecture du classeur
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ L'EN-TÊTE DIT CE QU'ON PEUT CORRIGER — et c'est la seule chose qui le montrait
     * nulle part.
     *
     * Le dictionnaire l'écrivait déjà de chaque colonne (« Repris à l'import » / « IGNORÉ
     * à l'import »), mais dans la feuille de données rien ne l'indiquait : sur
     * soixante-quatorze colonnes dont vingt-cinq se reprennent, il fallait ouvrir le
     * dictionnaire colonne par colonne. Le fond de l'en-tête le dit désormais d'un coup
     * d'œil.
     *
     * ⚠ ET IL LE DIT AUSSI EN TOUTES LETTRES : la nature ne repose jamais sur la seule
     * couleur (WCAG 1.4.1). Un bandeau du dictionnaire l'explique, et la notice de chaque
     * colonne le répète — c'est ce que vérifie la fin de ce test.
     */
    public function testLEnteteDistingueLesColonnesRepriseDesColonnesCalculees(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $classeur = $this->produire($entreprise, $invite);
        $donnees = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        self::assertNotNull($donnees);

        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);

        $vues = ['saisie' => 0, 'calculee' => 0];
        $index = 0;
        foreach ($colonnes as $colonne) {
            ++$index;
            $fond = $donnees->getStyle(Coordinate::stringFromColumnIndex($index) . '1')
                ->getFill()->getStartColor()->getARGB();

            if ($colonne->lectureSeule()) {
                self::assertSame(
                    Charte::GRIS_MUET,
                    $fond,
                    sprintf('« %s » est calculée : son en-tête doit s\'effacer en gris.', $colonne->libelle),
                );
                ++$vues['calculee'];
                continue;
            }

            self::assertSame(
                Charte::COBALT,
                $fond,
                sprintf('« %s » se reprend à l\'import : son en-tête doit porter la couleur de marque.', $colonne->libelle),
            );
            ++$vues['saisie'];
        }

        // Les deux natures existent réellement dans l'état : sans quoi ce test passerait
        // en n'ayant rien vérifié du tout.
        self::assertGreaterThan(0, $vues['saisie']);
        self::assertGreaterThan(0, $vues['calculee']);

        // ⚠ LA LÉGENDE EST ÉCRITE, pas seulement montrée.
        $dictionnaire = $classeur->getSheetByName(\App\Echange\Classeur\EcrivainJsbx::FEUILLE_DICTIONNAIRE);
        self::assertNotNull($dictionnaire);
        self::assertSame('COULEURS DES COLONNES', $dictionnaire->getCell('A6')->getValue());
    }

    /**
     * ⚠ LA BARRE NE DOIT PAS ATTEINDRE CENT POUR CENT AVANT LA FIN DU TRAVAIL.
     *
     * Elle ne mesurait que la LECTURE du portefeuille : arrivée à cent pour cent, elle s'y
     * figeait pendant la mise en forme du classeur — souvent plus longue que la lecture —,
     * puis pendant la compression. L'utilisateur regardait « 100 % » sans que rien ne
     * bouge, ce qui se lit comme une panne et fait recliquer.
     *
     * Le budget compte donc deux pas par ligne, lire puis écrire ; et ce test tient la
     * promesse : au moment où la lecture s'achève, il reste du chemin à parcourir.
     */
    public function testLaProgressionDeLExportNeSaturePasAvantLaMiseEnForme(): void
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->seed();

        $pourcentages = [];
        $progression = new \App\Echange\Service\Progression(0, static function (array $etat) use (&$pourcentages): void {
            $pourcentages[] = $etat['pct'];
        });

        static::getContainer()->get(ProducteurDeLEtat::class)->produire(
            $entreprise,
            $invite,
            $entreprise->getUtilisateur(),
            [],
            null,
            \App\Echange\Etat\ExerciceDesTranches::TOUS,
            $progression,
        );

        self::assertNotEmpty($pourcentages, 'La production doit publier son avancement.');

        // ⚠ ON NE VÉRIFIE PAS QU'ELLE FINIT À CENT : c'est `terminer()` qui le fait, et il
        // appartient à l'appelant. Ce qui compte ici, c'est qu'elle n'y arrive pas TROP TÔT.
        $avantLaFin = array_slice($pourcentages, 0, -1);
        foreach ($avantLaFin as $pct) {
            self::assertLessThan(
                100.0,
                $pct,
                'Un pourcentage de cent pour cent publié avant la fin fige la barre sur le reste du travail.',
            );
        }
    }

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

    /**
     * La première ligne de données, indexée par CODE de colonne.
     *
     * On passe par le service plutôt que par le classeur : les codes sont le langage
     * commun de l'état et de l'économie, quand les libellés sont ceux de l'écran.
     */
    private function ligneBrute(Entreprise $entreprise): array
    {
        foreach (static::getContainer()->get(EtatDuPortefeuille::class)->lignes($entreprise) as $ligne) {
            return $ligne;
        }

        self::fail('Aucune ligne produite.');
    }

    /** La lettre de colonne portant ce libellé exact. */
    private function lettreDe($feuille, string $libelle): string
    {
        $derniere = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            if ((string) $feuille->getCell($lettre . '1')->getValue() === $libelle) {
                return $lettre;
            }
        }

        self::fail(sprintf('Aucune colonne « %s ».', $libelle));
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
            // ⚠ `chargement` MANQUAIT, et son absence a fait tomber vingt-quatre tests d'un
            // coup : le cabinet ne pouvait plus être supprimé (clé étrangère), et chaque
            // test suivant échouait à son propre nettoyage. Une table oubliée ici ne casse
            // pas le test qui l'écrit — elle casse tous ceux d'après.
            'chargement',
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
