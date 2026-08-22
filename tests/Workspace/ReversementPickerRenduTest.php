<?php

namespace App\Tests\Workspace;

use App\Entity\Invite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * LE PICKER DE REVERSEMENT, RENDU POUR DE VRAI.
 *
 * Les tests fonctionnels de ce picker ne l'ouvrent que pour un agent SANS solde
 * exigible : le bloc « Le versement » n'y est jamais rendu, et tout ce qu'il contient
 * — champs, icônes, valeurs par défaut — pouvait donc casser sans qu'un test bronche.
 *
 * On rend ici le gabarit avec des données fabriquées, sans base : ce qui est vérifié,
 * c'est la MISE EN FORME, et elle ne dépend d'aucune donnée réelle.
 */
class ReversementPickerRenduTest extends KernelTestCase
{
    private function rendre(array $comptes = [['id' => 7, 'intitule' => 'AIB RDC', 'banque' => 'Equity BCDC']]): string
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('components/retro_agent/_reversement_picker.html.twig', [
            'agent'   => (new Invite())->setNom('Administrateur (Serge SULA)'),
            'monnaie' => 'USD',
            'comptes' => $comptes,
            'submitUrl' => '/admin/retro-agent/1/reversement',
            // Ce qu'il faudra rafraîchir après l'écriture : le picker s'ouvre depuis le
            // rapport, qui n'est pas une liste.
            'rapportUrl' => '/admin/retro-agent/1/rapport',
            'referenceParDefaut' => 'RETRO-21082026-141359',
            'compteProposeId' => $comptes[0]['id'] ?? null,
            // La zone de dépôt est celle des fiches : on lui passe le MÊME contexte que
            // la boîte « Attacher des pièces », d'où qu'il vienne.
            'limites' => \App\Ai\Fichier\FichierAttachePolicy::limitesFront(),
            'famillesParExtension' => \App\Service\Soa\SoaPoliceDocumentsCollector::famillesParExtension(),
            'attacherUrlPattern' => '/admin/document/api/attacher/reversementRetroAgent/0',
            'lignes'  => [[
                'avenant' => ['id' => 3],
                'client' => 'Kibali Goldmines SA',
                'reference' => '12002-33002-0021-111-00071014-2025',
                'risque' => 'GIT,MAR,MOC,MARINE',
                'retroAgentExigible' => 5.99,
            ]],
        ]);
    }

    /**
     * Les trois champs suivent le pattern maison, celui du picker de mouvements et de
     * celui des destinataires : icône incrustée et largeur bornée.
     *
     * Ce test existe parce que ces champs avaient d'abord été écrits en Bootstrap nu —
     * trois `form-control` étirés sur toute la fenêtre, sans icône. Rien ne l'avait vu.
     */
    public function testLesChampsSuiventLePatternDesAutresPickers(): void
    {
        $html = $this->rendre();

        // Une icône incrustée par champ : date, référence, compte.
        self::assertSame(3, substr_count($html, 'class="jsb-picker-field"'));
        self::assertSame(3, substr_count($html, 'jsb-picker-field-icon'));

        // Les icônes sont RENDUES, pas seulement demandées : un alias inconnu passerait
        // l'analyse du gabarit et n'échouerait qu'à l'affichage.
        self::assertGreaterThanOrEqual(3, substr_count($html, '<svg'));

        // Largeur bornée : un champ pleine fenêtre ne dit rien de ce qu'on attend.
        self::assertStringContainsString('max-width:15rem', $html);
        self::assertStringContainsString('max-width:22rem', $html);
        self::assertStringContainsString('max-width:26rem', $html);
    }

    /** Deux structures différentes, deux cartes — pas un seul long formulaire. */
    public function testChaqueSectionEstUneCarteDistincte(): void
    {
        $html = $this->rendre();

        // Affaires à régler, le versement, ce qui sera enregistré.
        // Le guillemet fermant est délibéré : sans lui on compterait aussi le
        // conteneur `jsb-picker-cartes` et le titre `jsb-picker-carte-titre`.
        // Quatre : les affaires, le versement, la pièce justificative, et l'aperçu.
        self::assertSame(4, substr_count($html, 'jsb-picker-carte"'));
        self::assertStringContainsString('jsb-picker-cartes', $html);

        // Une carte SANS bordure n'est pas une carte : `border-0` pose
        // `border: 0 !important` et l'effaçait, ne laissant s'encadrer que la
        // section voisine, qui ne portait pas l'utilitaire.
        self::assertStringNotContainsString('border-0 jsb-picker-carte', $html);
    }

    /**
     * Le titre d'une carte-fieldset doit rester DANS la carte.
     *
     * Une `legend` n'est pas une boîte ordinaire : le navigateur la pose sur la bordure
     * haute du fieldset, hors du cadre, et la dimensionne au contenu. Le
     * `float: left; width: 100%` de Reboot est ce qui la rend à une mise en page
     * normale — l'annuler sortait le titre de la carte et réduisait son filet à la
     * largeur du mot. Rien dans le HTML ne le dit : c'est la règle qu'on garde.
     */
    public function testLaLegendeDUneCarteResteDansLaCarte(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../assets/styles/app.css');

        $regle = strstr($css, '.jsb-picker-carte > legend {');
        self::assertNotFalse($regle, 'La règle de légende des cartes a disparu.');

        $corps = substr($regle, 0, (int) strpos($regle, '}'));
        self::assertStringContainsString('float: left', $corps);
        self::assertStringContainsString('width: 100%', $corps);
    }
    /** Date, référence et compte s'ouvrent renseignés. */
    public function testLesTroisChampsArriventRemplis(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('value="' . date('Y-m-d') . '"', $html);
        self::assertStringContainsString('value="RETRO-21082026-141359"', $html);
        // Le compte, et non la caisse : un reversement passe par la banque dans la règle.
        self::assertStringContainsString('<option value="7" selected>', $html);
        self::assertStringContainsString('<option value="">Caisse', $html);
    }

    /**
     * LA ZONE DE DÉPÔT EST CELLE DES FICHES, pas une zone maison.
     *
     * C'est la vérification qui manquait la première fois : les champs du versement
     * avaient été écrits en Bootstrap nu alors que le pattern existait. Ici, ce qu'on
     * exige est la RÉUTILISATION — mêmes crochets `data-attach-*` que la boîte
     * « Attacher des pièces », donc même socle JavaScript, donc mêmes refus.
     */
    public function testLaPieceSeDeposeDansLaZoneHabituelle(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('Pièce justificative', $html);
        // Les crochets du socle partagé (assets/controllers/attach-selection.js).
        foreach (['data-attach-drop', 'data-attach-input', 'data-attach-liste', 'data-attach-vide'] as $crochet) {
            self::assertStringContainsString($crochet, $html, "Crochet manquant : {$crochet}.");
        }
        // Les gabarits d'icônes de format viennent avec, sinon les lignes de la liste
        // s'afficheraient sans icône.
        self::assertStringContainsString('data-attach-icone="pdf"', $html);
        self::assertStringContainsString('data-attach-icone-retirer', $html);

        // Le bouton d'enregistrement part DÉSARMÉ : sans pièce, le serveur refusera.
        self::assertMatchesRegularExpression('/data-picker-executer[^>]*\n?[^>]*disabled/', $html);
    }
    /** Sans aucun compte enregistré, la caisse reste le choix retenu. */
    public function testSansCompteLaCaisseResteRetenue(): void
    {
        $html = $this->rendre(comptes: []);

        self::assertStringContainsString('<option value="" selected>Caisse', $html);
    }
}
