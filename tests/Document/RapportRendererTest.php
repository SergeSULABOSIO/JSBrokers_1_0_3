<?php

namespace App\Tests\Document;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportAssembleur;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\Renderer\RapportRendererResolver;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LES SIX RENDUS PRODUISENT-ILS UN FICHIER QU'ON PEUT VRAIMENT OUVRIR ?
 *
 * La question n'est pas rhétorique : un .docx corrompu ne lève aucune exception à
 * l'écriture — c'est Word qui refuse de l'ouvrir, chez l'utilisateur, une fois le
 * document payé. On vérifie donc les octets eux-mêmes (signature de format,
 * relecture par la bibliothèque) et la présence des six parties imposées.
 *
 * Le cas de test embarque volontairement les pièges réels : caractères accentués,
 * esperluette et chevron (qui corrompent un .docx sans échappement), tableau GFM,
 * bloc ```chart, montants à séparateurs de milliers.
 */
class RapportRendererTest extends KernelTestCase
{
    private function spec(): RapportSpec
    {
        return new RapportSpec(
            titre: 'Commissions exigibles — exercice 2026',
            problematique: 'Quelles commissions sont exigibles, et pour quel montant total ?',
            introduction: "Une commission devient exigible dès lors que la prime a été réglée à l'assureur.",
            definitions: [
                ['terme' => 'Prime nette', 'explication' => "Assiette de la commission, hors chargements."],
                ['terme' => 'Exigibilité', 'explication' => 'Moment où le courtier peut réclamer sa commission.'],
            ],
            sections: [
                [
                    'titre' => 'Détail par client',
                    // Esperluette & chevron < : sans échappement, le .docx est corrompu.
                    'corps' => "Trois tranches, dont Bolloré & Cie < seuil.\n\n"
                        . "| Client | Police | Montant |\n| --- | --- | ---: |\n"
                        . "| KIN AVIA | 12002-330 | 12 345,67 |\n| CHEMAF SA | 12002-331 | 8 100,00 |\n\n"
                        . "- Première puce\n- Seconde puce",
                ],
                [
                    'titre' => 'Synthèse',
                    'corps' => "Total : **281 002,44 USD**.\n\n```chart\n"
                        . '{"type":"bar","titre":"Par client","unite":"USD","labels":["KIN AVIA","CHEMAF"],'
                        . '"series":[{"label":"Exigible","data":[12345.67,8100]}]}' . "\n```",
                ],
            ],
            conclusion: "Le total des commissions exigibles s'élève à 281 002,44 USD.",
        );
    }

    private function pied(): PiedDePage
    {
        return new PiedDePage(
            'ACME Courtage SARL',
            'Serge SULA',
            'Commissions exigibles — exercice 2026',
            new \DateTimeImmutable('2026-08-11 10:30'),
            'Ket',
        );
    }

    /** @return array{0: string, 1: RapportSpec} octets rendus + la spec */
    private function rendre(DocumentFormat $format): array
    {
        static::bootKernel();
        $conteneur = static::getContainer();
        $spec = $this->spec();

        $octets = $conteneur->get(RapportRendererResolver::class)
            ->pour($format)
            ->rendre($spec, $conteneur->get(RapportAssembleur::class)->sections($spec), $this->pied());

        return [$octets, $spec];
    }

    /** @return iterable<string, array{0: DocumentFormat}> */
    public static function formats(): iterable
    {
        foreach (DocumentFormat::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    /**
     * Le socle : chaque format rend des octets, et un rendu vide n'existe pas.
     *
     * @dataProvider formats
     */
    public function testChaqueFormatRendUnFichierNonVide(DocumentFormat $format): void
    {
        [$octets] = $this->rendre($format);

        self::assertNotSame('', $octets, sprintf('Le rendu %s est vide.', $format->value));
        self::assertGreaterThan(200, strlen($octets), sprintf('Le rendu %s est suspicieusement court.', $format->value));
    }

    /** Un rendu doit couvrir TOUS les formats déclarés : aucun trou dans le catalogue. */
    public function testChaqueFormatDeclareAUnRendu(): void
    {
        static::bootKernel();
        $resolver = static::getContainer()->get(RapportRendererResolver::class);

        $sans = [];
        foreach (DocumentFormat::cases() as $format) {
            if (!$resolver->couvre($format)) {
                $sans[] = $format->value;
            }
        }

        self::assertSame([], $sans, 'Formats déclarés sans rendu : ' . implode(', ', $sans));
    }

    /** Signature binaire : un PDF commence par %PDF-, une archive OOXML par PK. */
    public function testLesFormatsBinairesPortentLeurSignature(): void
    {
        [$pdf] = $this->rendre(DocumentFormat::Pdf);
        self::assertStringStartsWith('%PDF-', $pdf);

        foreach ([DocumentFormat::Docx, DocumentFormat::Xlsx] as $format) {
            [$octets] = $this->rendre($format);
            self::assertStringStartsWith("PK\x03\x04", $octets, sprintf('%s doit être une archive OOXML.', $format->value));
        }
    }

    /**
     * LE .docx EST-IL RÉELLEMENT VALIDE ? On le rouvre comme le ferait Word :
     * l'archive doit porter word/document.xml, et le texte doit s'y trouver.
     *
     * C'est ce test qui attrape l'oubli de setOutputEscapingEnabled : l'esperluette
     * de « Bolloré & Cie » produirait un XML mal formé, et le chargement échouerait.
     */
    public function testLeWordEstUneArchiveOoxmlValideEtEchappee(): void
    {
        [$octets, $spec] = $this->rendre(DocumentFormat::Docx);

        $chemin = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($chemin, $octets);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($chemin) === true, 'Le .docx ne s’ouvre pas comme une archive.');

            $xml = $zip->getFromName('word/document.xml');
            self::assertIsString($xml, 'word/document.xml est absent : ce n’est pas un .docx.');
            $zip->close();

            // XML BIEN FORMÉ — le vrai verdict de l'échappement.
            $document = new \DOMDocument();
            self::assertTrue(
                $document->loadXML($xml),
                'word/document.xml est mal formé : l’échappement de sortie (Settings::setOutputEscapingEnabled) '
                . 'est probablement désactivé — un « & » ou un « < » suffit alors à corrompre le fichier.',
            );

            $texte = strip_tags($xml);
            self::assertStringContainsString('Commissions exigibles', $texte);
            self::assertStringContainsString('Bolloré & Cie', html_entity_decode($texte, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            // Les six parties imposées.
            self::assertStringContainsString('Objet du document', $texte);
            self::assertStringContainsString('Introduction', $texte);
            self::assertStringContainsString('Prime nette', $texte);
            self::assertStringContainsString('Résultat', $texte);
            self::assertStringContainsString('Conclusion', $texte);
            // Le pied de page vit dans son propre fragment, pas dans document.xml.
            self::assertStringContainsString('ACME Courtage SARL', $spec->titre . $texte . $this->piedDuDocx($chemin));
        } finally {
            @unlink($chemin);
        }
    }

    private function piedDuDocx(string $chemin): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($chemin) !== true) {
            return '';
        }
        $pied = '';
        for ($i = 1; $i <= 3; $i++) {
            $fragment = $zip->getFromName(sprintf('word/footer%d.xml', $i));
            if (is_string($fragment)) {
                $pied .= strip_tags($fragment);
            }
        }
        $zip->close();

        return $pied;
    }

    /**
     * LE CLASSEUR A-T-IL SES DEUX FEUILLES, et la feuille « Données » contient-elle
     * de VRAIES cellules ? C'est l'exigence explicite du propriétaire : sans la
     * coercition numérique, « 12 345,67 » resterait du texte et la feuille ne
     * servirait à rien.
     */
    public function testLExcelPorteSesDeuxFeuillesEtDeVraiesCellulesNumeriques(): void
    {
        [$octets] = $this->rendre(DocumentFormat::Xlsx);

        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($chemin, $octets);

        try {
            $classeur = SpreadsheetIOFactory::load($chemin);

            self::assertSame(['Rapport', 'Données'], $classeur->getSheetNames());

            $rapport = $classeur->getSheetByName('Rapport');
            self::assertStringContainsString('Commissions exigibles', (string) $rapport->getCell('A1')->getValue());

            $donnees = $classeur->getSheetByName('Données');
            $valeurs = [];
            // toArray(formatData: false) : on veut la valeur STOCKÉE, pas son
            // rendu. Par défaut PhpSpreadsheet applique le format d'affichage et
            // renverrait « 12 345,67 » — une chaîne, qui masquerait justement le
            // défaut que ce test cherche.
            foreach ($donnees->toArray(null, true, false) as $ligne) {
                foreach ($ligne as $cellule) {
                    if ($cellule !== null && $cellule !== '') {
                        $valeurs[] = $cellule;
                    }
                }
            }

            self::assertContains('KIN AVIA', $valeurs, 'La feuille Données doit porter les lignes du tableau.');

            // Le montant doit être un NOMBRE, pas la chaîne « 12 345,67 ».
            $nombres = array_values(array_filter($valeurs, 'is_numeric'));
            self::assertContains(12345.67, array_map('floatval', $nombres),
                'Les montants doivent être écrits en cellules NUMÉRIQUES (sinon : ni somme, ni tri, ni graphique).');

            // La référence de police, elle, doit rester du TEXTE.
            self::assertContains('12002-330', $valeurs, 'Une référence ne doit jamais être convertie en nombre.');

            $classeur->disconnectWorksheets();
        } finally {
            @unlink($chemin);
        }
    }

    /** Les formats textuels portent les six parties et le pied de page, en clair. */
    public function testLesFormatsTextuelsPortentLaStructureImposee(): void
    {
        foreach ([DocumentFormat::Md, DocumentFormat::Txt, DocumentFormat::Html] as $format) {
            [$octets] = $this->rendre($format);
            $message = sprintf('Format %s : ', $format->value);

            self::assertStringContainsString('Commissions exigibles', $octets, $message . 'titre absent.');
            self::assertStringContainsString('Prime nette', $octets, $message . 'définitions absentes.');
            self::assertStringContainsString('KIN AVIA', $octets, $message . 'contenu du tableau absent.');
            self::assertStringContainsString('281 002,44', $octets, $message . 'conclusion absente.');
            // Note de bas de page : entreprise, utilisateur, date de production.
            self::assertStringContainsString('ACME Courtage SARL', $octets, $message . 'pied de page absent.');
            self::assertStringContainsString('Serge SULA', $octets, $message . 'utilisateur absent du pied.');
            self::assertStringContainsString('11/08/2026', $octets, $message . 'date de production absente.');
        }
    }

    /** Le HTML est une page autonome, et son contenu reste échappé. */
    public function testLeHtmlEstUnePageAutonomeEtEchappee(): void
    {
        [$octets] = $this->rendre(DocumentFormat::Html);

        self::assertStringStartsWith('<!DOCTYPE html>', $octets);
        self::assertStringContainsString('<meta charset="UTF-8">', $octets);
        // Aucune ressource externe : le fichier doit s'ouvrir hors ligne.
        self::assertDoesNotMatchRegularExpression('/<(?:script|link)\b/i', $octets);
        // Le chevron du contenu est ÉCHAPPÉ, jamais réinjecté comme balise.
        self::assertStringContainsString('Bolloré &amp; Cie &lt; seuil', $octets);
    }

    /**
     * UN GRAPHIQUE DEVIENT UN TABLEAU. On ne peut pas peindre un canvas dans un
     * document ; le transcrire en données est sans perte, et supérieur à une image.
     */
    public function testUnBlocGraphiqueEstTranscritEnTableauDeDonnees(): void
    {
        static::bootKernel();
        $blocs = static::getContainer()->get(RapportAssembleur::class)->blocs(
            "```chart\n" . '{"type":"bar","titre":"Par client","unite":"USD","labels":["KIN AVIA"],'
            . '"series":[{"label":"Exigible","data":[12345.67]}]}' . "\n```",
        );

        self::assertCount(1, $blocs);
        self::assertSame('tableau', $blocs[0]['type']);
        self::assertSame('Par client', $blocs[0]['titre']);
        self::assertSame(['', 'Exigible (USD)'], $blocs[0]['entetes']);
        self::assertSame([['KIN AVIA', '12345.67']], $blocs[0]['lignes']);
    }

    /** L'aplatissement conserve tous les MOTS, y compris ceux des pastilles. */
    public function testLAplatissementNePerdAucunMot(): void
    {
        static::bootKernel();
        $blocs = static::getContainer()->get(RapportAssembleur::class)
            ->blocs('Une prime **nette** de 100 est [Payée](#success) selon le `barème`.');

        self::assertSame('paragraphe', $blocs[0]['type']);
        self::assertSame('Une prime nette de 100 est Payée selon le barème.', $blocs[0]['texte']);
    }
}
