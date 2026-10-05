<?php

namespace App\Tests\Workspace;

use App\Services\Canvas\Autocomplete\Chiffre;
use App\Services\Canvas\Autocomplete\RenduOptionAutocomplete;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CE QUI N'A RIEN À DIRE NE S'AFFICHE PAS — ET SON LIBELLÉ NON PLUS.
 *
 * ── LE DÉFAUT ───────────────────────────────────────────────────────────────────────
 * Un assureur sans contact affichait `Email: N/A | Tél: N/A` : deux mots pour dire qu'il
 * n'y a rien à dire, et une ligne entière occupée à ne rien apprendre. Chacun des dix-huit
 * champs de relation écrivait ses propres `?? 'N/A'`, son propre balisage, et son propre
 * `number_format($v, 2, ',', ' ')` — un format FRANÇAIS en dur, dans une application
 * bilingue.
 *
 * ── CE QUE CE TEST TIENT ────────────────────────────────────────────────────────────
 * Les règles que les dix-huit appelants n'ont plus à connaître. Chacune a été écrite
 * parce qu'elle manquait quelque part.
 */
class RenduOptionAutocompleteTest extends KernelTestCase
{
    private RenduOptionAutocomplete $rendu;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rendu = static::getContainer()->get(RenduOptionAutocomplete::class);
    }

    public function testSansContactAucuneLigneDeContactNEstEmise(): void
    {
        $html = $this->rendu->libelle('RAWSUR', [null, null], [Chiffre::montant('Prime', 10250.0)]);

        self::assertStringNotContainsString('jsb-autocomplete-context', $html,
            'Sans contact, la ligne ne doit pas exister du tout — et surtout pas exister vide.');
        self::assertStringNotContainsString('N/A', $html,
            "`Email: N/A | Tél: N/A` : deux mots pour dire qu'il n'y a rien à dire.");
    }

    /**
     * Les mots qui disaient « rien » sont écartés — mais à l'ÉGALITÉ STRICTE.
     *
     * Un `str_contains('N/A')` mangerait « N/Able », qui est un nom de société
     * parfaitement valide : le remède serait pire que le mal.
     */
    public function testUnPlaceholderHeriteEstEcarteMaisPasUnNomQuiLuiRessemble(): void
    {
        $avecPlaceholder = $this->rendu->libelle('ACTIVA', ['N/A', '—']);
        self::assertStringNotContainsString('jsb-autocomplete-context', $avecPlaceholder);

        $avecVraiNom = $this->rendu->libelle('ACTIVA', ['N/Able Solutions']);
        self::assertStringContainsString('N/Able Solutions', $avecVraiNom,
            '« N/Able » est un nom de société : le filtrer serait pire que le défaut corrigé.');
    }

    /** Un séparateur orphelin est une ponctuation qui annonce une information absente. */
    public function testLeSeparateurNApparaitQuEntreDeuxElementsPresents(): void
    {
        $unSeul = $this->rendu->libelle('ACTIVA', ['contact@activa.cd', null]);

        self::assertStringContainsString('contact@activa.cd', $unSeul);
        self::assertStringNotContainsString('jsb-context-separator', $unSeul,
            'Un seul élément présent : aucun séparateur ne doit être écrit.');
    }

    /**
     * ZÉRO N'EST PAS « RIEN », et c'est la distinction qui compte.
     *
     * `null` signifie « non calculé » ; `0.0` signifie « calculé, et il vaut zéro ». Un
     * solde à zéro est précisément ce qu'un utilisateur cherche : le faire disparaître
     * reviendrait à cacher la bonne nouvelle.
     */
    public function testUnZeroSAfficheMaisPasUneValeurNonCalculee(): void
    {
        $avecZero = $this->rendu->libelle('ACTIVA', [], [Chiffre::solde('Comm. due', 0.0)]);
        self::assertStringContainsString('0,00', $avecZero, 'Un solde à zéro est une information.');
        self::assertStringContainsString('jsb-chiffre--solde', $avecZero);

        $avecNull = $this->rendu->libelle('ACTIVA', [], [Chiffre::solde('Comm. due', null)]);
        self::assertStringNotContainsString('jsb-autocomplete-aide', $avecNull,
            'Une valeur non calculée ne doit pas s\'afficher — ce n\'est pas un zéro.');
        self::assertStringNotContainsString('Comm. due', $avecNull,
            'Et son libellé non plus : annoncer un chiffre absent est pire que se taire.');
    }

    /** Un solde non nul se distingue d'un solde soldé, sans que la couleur soit seule à le dire. */
    public function testLesTroisEtatsDUnSolde(): void
    {
        self::assertStringContainsString('jsb-chiffre--solde',
            $this->rendu->libelle('A', [], [Chiffre::solde('Reste', 0.0)]));
        self::assertStringContainsString('jsb-chiffre--du',
            $this->rendu->libelle('A', [], [Chiffre::solde('Reste', 412.5)]));

        $tropPercu = $this->rendu->libelle('A', [], [Chiffre::solde('Reste', -45.0)]);
        self::assertStringContainsString('jsb-chiffre--du', $tropPercu);
        self::assertStringContainsString('-45,00', $tropPercu,
            'Le signe moins porte le trop-perçu : la couleur n\'est jamais seule (WCAG 1.4.1).');
    }

    /** Un montant n'a pas d'état : le peindre ne dirait rien de plus que le nombre. */
    public function testUnMontantNEstPasColore(): void
    {
        $html = $this->rendu->libelle('A', [], [Chiffre::montant('Prime', 0.0)]);

        self::assertStringNotContainsString('jsb-chiffre--', $html);
        self::assertStringContainsString('class="jsb-chiffre-valeur"', $html,
            'Sans teinte, l\'attribut class ne doit pas traîner d\'espace.');
    }

    /**
     * AU PLUS TROIS CHIFFRES, et le plafond est tenu par le service.
     *
     * Le laisser à la discipline de dix-huit appelants, c'est le voir franchi par le
     * dix-neuvième. C'est ainsi qu'on était arrivé à cinq tuiles, puis à quinze métriques
     * sur la Tranche.
     */
    public function testAuPlusTroisChiffres(): void
    {
        $html = $this->rendu->libelle('A', [], [
            Chiffre::montant('Un', 1.0), Chiffre::montant('Deux', 2.0),
            Chiffre::montant('Trois', 3.0), Chiffre::montant('Quatre', 4.0),
            Chiffre::montant('Cinq', 5.0),
        ]);

        self::assertSame(3, substr_count($html, 'jsb-chiffre-libelle'));
        self::assertStringNotContainsString('Quatre', $html);
    }

    /**
     * TRONQUER AVANT D'ÉCHAPPER.
     *
     * Couper après l'échappement trancherait une entité en deux — `&amp;` devenant
     * `&am` —, ce qui donne un caractère invalide dans la page.
     */
    public function testLaTroncatureSeFaitAvantLEchappement(): void
    {
        $contexte = str_repeat('a', 79) . '&';
        $html = $this->rendu->libelle('A', [$contexte]);

        self::assertStringNotContainsString('&am<', $html, 'Une entité HTML coupée en deux.');
        self::assertStringNotContainsString('&am"', $html);
        self::assertMatchesRegularExpression('/(&amp;|…)/', $html,
            'Soit l\'esperluette est échappée entière, soit elle est tombée hors de la troncature.');
    }

    public function testLeTitreEtSonSuffixe(): void
    {
        $html = $this->rendu->libelle('ACTIVA', [], [], 'Réf: BX-2026-04');

        self::assertStringContainsString('>ACTIVA<', $html);
        self::assertStringContainsString('jsb-autocomplete-title-suffix', $html);
        self::assertStringContainsString('Réf: BX-2026-04', $html);
    }

    /** Rien d'injectable ne traverse le rendu. */
    public function testToutEstEchappe(): void
    {
        $html = $this->rendu->libelle('<script>alert(1)</script>', ['"onmouseover="x']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('onmouseover="x', $html);
    }
}
