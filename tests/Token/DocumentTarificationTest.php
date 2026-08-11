<?php

namespace App\Tests\Token;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\DocumentTarificateur;
use App\Entity\PlateformeParametres;
use App\Repository\PlateformeParametresRepository;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use App\Token\TokenPricing;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * LE BARÈME DES DOCUMENTS, éprouvé sur ses bornes.
 *
 *     cout = ceil( (base + pages × parPage) × multiplicateur(format) )
 *     pages = max(1, ceil(caractères / caractèresParPage))
 *
 * Deux familles d'assertions, et elles ne se recouvrent pas :
 *  - le CALCUL (bornes de page, arrondi, multiplicateurs) ;
 *  - le PARAMÉTRAGE, où se cache le vrai piège : une carte personnalisée en console
 *    ne doit jamais rendre invisible un format déclaré dans le code.
 *
 * Purement unitaire — repository simulé, aucune base.
 */
class DocumentTarificationTest extends TestCase
{
    private function tarificateur(?PlateformeParametres $parametres = null): DocumentTarificateur
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn($parametres ?? new PlateformeParametres());
        $service = new ParametresTokenService($repo);

        $em = $this->createMock(EntityManagerInterface::class);

        return new DocumentTarificateur($service, new TokenAccountService($em, $service));
    }

    private function parametres(): ParametresTokenService
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn(new PlateformeParametres());

        return new ParametresTokenService($repo);
    }

    // ── Le calcul ────────────────────────────────────────────────────────────

    /** Contenu vide : une page tout de même. On facture un document, pas du vent. */
    public function testUnContenuVideVautUnePage(): void
    {
        $devis = $this->tarificateur()->chiffrer('', DocumentFormat::Txt);

        self::assertSame(0, $devis->caracteres);
        self::assertSame(1, $devis->pages);
        self::assertSame(90, $devis->cout); // (60 + 1×30) × 1,0
    }

    public function testUnSeulCaractereVautUnePage(): void
    {
        self::assertSame(1, $this->tarificateur()->chiffrer('a', DocumentFormat::Txt)->pages);
    }

    /** LA BORNE : 2 500 caractères pile font UNE page, 2 501 en font deux. */
    public function testLaBorneDeLaPageEstExacte(): void
    {
        $tarificateur = $this->tarificateur();

        self::assertSame(1, $tarificateur->chiffrer(str_repeat('a', 2500), DocumentFormat::Txt)->pages);
        self::assertSame(2, $tarificateur->chiffrer(str_repeat('a', 2501), DocumentFormat::Txt)->pages);
        self::assertSame(2, $tarificateur->chiffrer(str_repeat('a', 5000), DocumentFormat::Txt)->pages);
        self::assertSame(3, $tarificateur->chiffrer(str_repeat('a', 5001), DocumentFormat::Txt)->pages);
    }

    /**
     * UN DOCUMENT FRANÇAIS N'EST PAS PLUS CHER QU'UN AUTRE. Compter en octets
     * (strlen) au lieu de caractères (mb_strlen) doublerait la facture de tout
     * texte accentué — un défaut silencieux, et systématique ici.
     */
    public function testLeContenuEstMesureEnCaracteresPasEnOctets(): void
    {
        // 2 500 « é » = 5 000 octets, mais 2 500 caractères : UNE page.
        $devis = $this->tarificateur()->chiffrer(str_repeat('é', 2500), DocumentFormat::Txt);

        self::assertSame(2500, $devis->caracteres);
        self::assertSame(1, $devis->pages);
    }

    /**
     * @dataProvider baremeParFormat
     *
     * La spec exécutable du barème : 7 500 caractères = 3 pages, sous-total 150.
     */
    public function testChaqueFormatAppliqueSonMultiplicateur(string $format, int $attendu): void
    {
        $devis = $this->tarificateur()->chiffrer(str_repeat('a', 7500), $format);

        self::assertSame(3, $devis->pages);
        self::assertSame(150, $devis->coutAvantFormat);
        self::assertSame($attendu, $devis->cout, sprintf('Format %s.', $format));
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function baremeParFormat(): iterable
    {
        yield 'txt ×1,0'  => ['txt', 150];
        yield 'md ×1,0'   => ['md', 150];
        yield 'html ×1,2' => ['html', 180];
        yield 'xlsx ×1,4' => ['xlsx', 210];
        yield 'docx ×1,5' => ['docx', 225];
        yield 'pdf ×1,8'  => ['pdf', 270];
    }

    /**
     * L'ARRONDI TOMBE APRÈS LA MULTIPLICATION. Impossible à démontrer avec les
     * valeurs par défaut — elles tombent toutes juste — d'où une base à 61 :
     * (61 + 30) × 1,2 = 109,2 → 110, et non 109.
     */
    public function testLArrondiEstAppliqueApresLeMultiplicateur(): void
    {
        $parametres = (new PlateformeParametres())->setDocumentBase(61);

        $devis = $this->tarificateur($parametres)->chiffrer('a', DocumentFormat::Html);

        self::assertSame(91, $devis->coutAvantFormat);
        self::assertSame(110, $devis->cout);
    }

    /** Un très gros document reste exact : aucune dérive de flottant. */
    public function testUnTresGrosDocumentResteExact(): void
    {
        $devis = $this->tarificateur()->chiffrer(str_repeat('a', 2_500_000), DocumentFormat::Docx);

        self::assertSame(1000, $devis->pages);
        self::assertSame(30060, $devis->coutAvantFormat);
        self::assertSame(45090, $devis->cout);
    }

    /** La ventilation ne peut pas contredire son propre total. */
    public function testLaVentilationEstAutoCoherente(): void
    {
        $devis = $this->tarificateur()->chiffrer(str_repeat('a', 4000), DocumentFormat::Pdf);

        self::assertSame($devis->base + $devis->pages * $devis->parPage, $devis->coutAvantFormat);
        self::assertSame((int) ceil($devis->coutAvantFormat * $devis->multiplicateur), $devis->cout);
        self::assertStringContainsString((string) $devis->cout, $devis->explication());
    }

    /**
     * Les deux portes d'entrée — contenu réel et nombre de pages — donnent le même
     * prix. C'est ce qui garantit que le tarif AFFICHÉ sur la page publique est
     * celui qui sera FACTURÉ.
     */
    public function testChiffrerEtChiffrerPagesConcordent(): void
    {
        $tarificateur = $this->tarificateur();

        foreach (DocumentFormat::cases() as $format) {
            self::assertSame(
                $tarificateur->chiffrer(str_repeat('a', 7500), $format)->cout,
                $tarificateur->chiffrerPages(3, $format)->cout,
                sprintf('Format %s : les deux portes doivent donner le même prix.', $format->value),
            );
        }
    }

    /** Un format inconnu retombe sur Word, jamais sur une exception. */
    public function testUnFormatInconnuRetombeSurLeDefaut(): void
    {
        self::assertSame(DocumentFormat::Docx, DocumentFormat::depuis('csv'));
        self::assertSame(DocumentFormat::Docx, DocumentFormat::depuis(null));
        self::assertSame(DocumentFormat::Docx, DocumentFormat::depuis(''));
        // Vocabulaire humain : le modèle écrit « Word », pas « docx ».
        self::assertSame(DocumentFormat::Docx, DocumentFormat::depuis('Word'));
        self::assertSame(DocumentFormat::Xlsx, DocumentFormat::depuis('  EXCEL '));
        self::assertSame(DocumentFormat::Pdf, DocumentFormat::depuis('.PDF'));
    }

    // ── Le paramétrage, et son piège ─────────────────────────────────────────

    /** Sans personnalisation : les constantes du code, à l'identique. */
    public function testSansPersonnalisationOnLitLesConstantes(): void
    {
        $parametres = $this->parametres();

        self::assertSame(TokenPricing::DOCUMENT_BASE, $parametres->documentBase());
        self::assertSame(TokenPricing::DOCUMENT_PAR_PAGE, $parametres->documentParPage());
        self::assertSame(TokenPricing::DOCUMENT_CARACTERES_PAR_PAGE, $parametres->documentCaracteresParPage());
        self::assertSame(TokenPricing::DOCUMENT_FORMATS, $parametres->documentFormats());
    }

    /** Chaque scalaire porte SON repli : régler l'un n'emporte pas les autres. */
    public function testChaqueScalaireEstIndependant(): void
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn((new PlateformeParametres())->setDocumentBase(99));
        $parametres = new ParametresTokenService($repo);

        self::assertSame(99, $parametres->documentBase());
        self::assertSame(TokenPricing::DOCUMENT_PAR_PAGE, $parametres->documentParPage());
        self::assertSame(TokenPricing::DOCUMENT_CARACTERES_PAR_PAGE, $parametres->documentCaracteresParPage());
    }

    /**
     * LE PIÈGE, NEUTRALISÉ. Avec une résolution `??` sur la carte entière — celle
     * qu'emploie writeWeights — personnaliser le seul PDF ferait retomber Word et
     * le texte sur le multiplicateur neutre, en silence, et toute la facturation
     * des autres formats serait fausse sans que rien ne casse.
     */
    public function testPersonnaliserUnFormatNEcrasePasLesAutres(): void
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn((new PlateformeParametres())->setDocumentFormats(['pdf' => 2.5]));
        $parametres = new ParametresTokenService($repo);

        self::assertSame(2.5, $parametres->documentMultiplicateur('pdf'));
        self::assertSame(1.5, $parametres->documentMultiplicateur('docx'));
        self::assertSame(1.0, $parametres->documentMultiplicateur('txt'));
        self::assertSame(1.4, $parametres->documentMultiplicateur('xlsx'));
    }

    /**
     * Corollaire à durée de vie infinie : le jour où quelqu'un ajoute un format au
     * CODE, il apparaît même sur une plateforme dont la carte est déjà
     * personnalisée. Sans la fusion, il serait facturé au multiplicateur neutre.
     */
    public function testUnFormatAjouteAuCodeApparaitMalgreUnePersonnalisation(): void
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn((new PlateformeParametres())->setDocumentFormats(['pdf' => 2.0]));

        $resolus = (new ParametresTokenService($repo))->documentFormats();

        self::assertSame([], array_diff_key(TokenPricing::DOCUMENT_FORMATS, $resolus));
    }

    /** Les valeurs aberrantes sont bornées plutôt que propagées. */
    public function testLesValeursAberrantesSontBornees(): void
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn(
            (new PlateformeParametres())
                ->setDocumentCaracteresParPage(0)   // division par zéro
                ->setDocumentBase(-5)
                ->setDocumentFormats(['pdf' => -1, 'PDFX' => 'abc']),
        );
        $parametres = new ParametresTokenService($repo);

        self::assertSame(1, $parametres->documentCaracteresParPage());
        self::assertSame(0, $parametres->documentBase());
        self::assertSame(0.0, $parametres->documentMultiplicateur('pdf'));
        // Valeur non numérique : ignorée à l'enregistrement, jamais stockée.
        self::assertFalse($parametres->estFormatDocumentConnu('pdfx'));
    }

    /** Les clés de format sont normalisées : « PDF » et « pdf » sont le même. */
    public function testLesClesDeFormatSontNormalisees(): void
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn((new PlateformeParametres())->setDocumentFormats([' PDF ' => 2.0]));

        self::assertSame(2.0, (new ParametresTokenService($repo))->documentMultiplicateur('pdf'));
    }

    /**
     * DIVERGENCE ASSUMÉE, verrouillée ici pour qu'on ne « l'harmonise » pas dans
     * six mois : writeWeights REMPLACE (une entité qu'un agent retire de la carte
     * doit rester retirée — sa suppression y est un acte de sens), là où les
     * formats FUSIONNENT (énumération technique fermée, miroir de l'enum servie).
     */
    public function testWriteWeightsConserveSaSemantiqueDeRemplacement(): void
    {
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn(
            (new PlateformeParametres())->setWriteWeights(['App\\Entity\\Cotation' => 75]),
        );

        self::assertSame(['App\\Entity\\Cotation' => 75], (new ParametresTokenService($repo))->writeWeights());
    }

    /** Le barème console est bien celui qui facture, bout en bout. */
    public function testLeBaremeConsoleEstCeluiQuiFacture(): void
    {
        $parametres = (new PlateformeParametres())
            ->setDocumentBase(80)->setDocumentParPage(40)->setDocumentCaracteresParPage(2000);

        // 100 caractères → 1 page → (80 + 40) × 1,0 = 120.
        self::assertSame(120, $this->tarificateur($parametres)->chiffrer(str_repeat('a', 100), DocumentFormat::Txt)->cout);
    }
}
