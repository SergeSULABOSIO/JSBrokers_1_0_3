<?php

namespace App\Tests\Workspace;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * LA FENÊTRE DE FACTURATION, RENDUE POUR DE VRAI.
 *
 * ── POURQUOI UN BANC SANS BASE ──────────────────────────────────────────────
 * Le test fonctionnel n'ouvre cette fenêtre que sur le cas simple : un assureur, une
 * échéance facturable. Tout le reste — le bloc qui annonce les destinataires suivants,
 * celui qui nomme la note bloquante, les comptes bancaires, la signature — pouvait
 * donc casser sans qu'un test bronche. C'est exactement ce qui était arrivé au picker
 * de reversement, et pourquoi son propre banc de rendu existe.
 *
 * Ce qui est vérifié ici, c'est la MISE EN FORME et les VALEURS PROPOSÉES : elles ne
 * dépendent d'aucune donnée réelle.
 */
class FacturationPickerRenduTest extends KernelTestCase
{
    private const LIGNE = [
        'trancheId' => 74,
        'revenuId' => 12,
        'libelle' => 'Commission Ordinaire',
        'police' => 'XCDDD41457845-2026',
        'echeance' => '12/09/2026',
        'montant' => 11.60,
    ];

    private function rendre(array $surcharges = []): string
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('components/note/_facturation_picker.html.twig', $surcharges + [
            'groupe' => [
                'cible' => 3,
                'nom' => 'ACTIVA',
                'lignes' => [self::LIGNE],
                'ecartes' => [],
            ],
            'suivants' => [],
            'destinataire' => 'assureur',
            'idsDemandes' => [74],
            'objet' => 'Commission — Police XCDDD41457845-2026',
            'monnaie' => 'USD',
            'comptes' => [],
            'signataire' => 'Serge SULA',
            'submitUrl' => '/admin/note/facturation',
            'apercuUrlPattern' => '/admin/note/api/get-preview-url/0',
        ]);
    }

    /**
     * TOUT EST COCHÉ D'OFFICE. Ces échéances viennent d'être choisies dans la liste :
     * les faire recocher serait redemander ce que l'utilisateur vient de dire, et
     * c'est très exactement le clic que ce chantier supprime.
     */
    public function testLesLignesArriventCochees(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('data-facturation-picker-target="coche"', $html);
        self::assertMatchesRegularExpression(
            '/type="checkbox" checked\s+data-facturation-picker-target="coche"/',
            $html,
            'Une ligne proposée doit arriver cochée : le courtier l\'a déjà choisie dans sa liste.',
        );
    }

    /** La ligne porte ce qu'il faut pour être postée, et ce qu'il faut pour être lue. */
    public function testLaLignePorteSonIdentiteEtSonMontant(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('data-tranche-id="74"', $html);
        self::assertStringContainsString('data-revenu-id="12"', $html);
        self::assertStringContainsString('data-montant="11.6"', $html);
        self::assertStringContainsString('XCDDD41457845-2026', $html, 'La police se lit sur la ligne.');
        self::assertStringContainsString('11,60', $html, 'Le montant s\'affiche en français.');
    }

    /**
     * LE FORMULAIRE ARRIVE REMPLI. C'est la demande centrale : un clic doit suffire.
     */
    public function testObjetSignataireEtDestinataireArriventRemplis(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('value="Commission — Police XCDDD41457845-2026"', $html,
            'L\'objet est déduit de la police : le redemander serait redemander ce que le dossier porte.',
        );
        self::assertStringContainsString('value="Serge SULA"', $html, 'Le signataire est prérempli.');
        self::assertStringContainsString('<option value="assureur" selected>', $html,
            'Le destinataire ordinaire d\'une commission de courtage est l\'assureur.',
        );
    }

    /**
     * LES COMPTES SONT TOUS COCHÉS. Sans eux, le PDF ne dit pas à l'assureur où virer,
     * et il faut le rappeler pour encaisser.
     */
    public function testLesComptesBancairesArriventTousCoches(): void
    {
        $html = $this->rendre(['comptes' => [
            ['id' => 7, 'intitule' => 'AIB RDC', 'numero' => 'CD-77'],
            ['id' => 9, 'intitule' => 'Compte USD', 'numero' => 'CD-99'],
        ]]);

        self::assertStringContainsString('AIB RDC', $html);
        self::assertStringContainsString('CD-99', $html);
        self::assertSame(
            2,
            substr_count($html, 'data-facturation-picker-target="compte"'),
            'Les deux comptes doivent être proposés.',
        );
        self::assertSame(
            2,
            preg_match_all('/checked[^>]*id="jsb-fact-compte-/', $html),
            'Chaque compte arrive coché : une note qui ne dit pas où payer oblige l\'assureur '
            . 'à rappeler pour régler.',
        );
    }

    /** Sans compte enregistré, on le DIT, et on donne le chemin. */
    public function testSansCompteBancaireOnLeDitEtOnDonneLeChemin(): void
    {
        $html = $this->rendre(['comptes' => []]);

        self::assertStringContainsString('Aucun compte bancaire enregistré', $html);
        self::assertStringContainsString('Finances → Comptes bancaires', $html,
            'Un manque se dit avec le chemin pour le combler.',
        );
    }

    /**
     * UNE NOTE, UN DESTINATAIRE. Une sélection qui en mêle plusieurs doit l'annoncer :
     * sans quoi le courtier croirait avoir tout facturé.
     */
    public function testLeMelangeDAssureursEstAnnonce(): void
    {
        $html = $this->rendre(['suivants' => [
            ['nom' => 'SUNU', 'compte' => 2, 'ids' => [80, 81]],
        ]]);

        self::assertStringContainsString('Une note par destinataire', $html);
        self::assertStringContainsString('SUNU', $html);
        self::assertStringContainsString('reste à facturer séparément', $html);
    }

    /**
     * CE QUI EST ÉCARTÉ EST NOMMÉ, AVEC LA PIÈCE QUI LE RETIENT.
     *
     * Répondre « rien à facturer » à quelqu'un dont la liste annonce une commission
     * exigible est une énigme : il rouvrira le dossier, cherchera, et redemandera.
     */
    public function testUneEcheanceEcarteeNommeLaNoteQuiLaBloque(): void
    {
        $html = $this->rendre([
            'groupe' => [
                'cible' => 3,
                'nom' => 'ACTIVA',
                'lignes' => [self::LIGNE],
                'ecartes' => [[
                    'police' => 'XCDDD41457845-2026',
                    'noteId' => 512,
                    'noteReference' => 'N1758123456',
                    'noteDate' => '12/09/2026',
                ]],
            ],
        ]);

        self::assertStringContainsString('Déjà facturé', $html);
        self::assertStringContainsString('N1758123456', $html, 'La note qui bloque est nommée.');
        self::assertStringContainsString('du 12/09/2026', $html);
        self::assertStringContainsString('data-facturation-picker-note-id="512"', $html,
            'Sa référence est un lien : le courtier doit pouvoir aller la vérifier.',
        );
    }

    /**
     * LE GESTE N'EST PAS FINI À L'ENREGISTREMENT. Une note qu'on ne peut pas ouvrir
     * oblige à la retrouver dans sa rubrique pour l'envoyer à l'assureur.
     */
    public function testLePiedDeSuccesPorteLOuvertureDuPdf(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('data-facturation-picker-target="footSucces"', $html);
        self::assertStringContainsString('Ouvrir la note (PDF)', $html);
        self::assertStringContainsString('facturation-picker#ouvrirLePdf', $html);
        self::assertStringContainsString('facturation-picker#facturerLesSuivantes', $html);
    }

    /** La fenêtre annonce ce qu'elle fait : la note part validée, donc au recouvrement. */
    public function testLaFenetreAnnonceQueLaNotePartValidee(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('validée', $html);
        self::assertStringContainsString('suivi du recouvrement', $html);
    }

    /** Rien à facturer : on le dit clairement, sans bouton d'enregistrement. */
    public function testSansLigneAucunBoutonDEnregistrement(): void
    {
        $html = $this->rendre(['groupe' => null]);

        self::assertStringContainsString('Rien à facturer sur cette sélection', $html);
        self::assertStringNotContainsString('data-picker-executer', $html,
            'Un bouton qui ne peut qu\'échouer ne doit pas être proposé.',
        );
    }
}
