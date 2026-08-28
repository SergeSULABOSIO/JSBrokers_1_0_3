<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * LE PICKER DE RATTACHEMENT, RENDU POUR DE VRAI — dans ses DEUX modes.
 *
 * Les tests fonctionnels l'ouvrent sur des fixtures réelles, ce qui prouve les routes et
 * les règles. Ils ne disent rien du GABARIT lui-même : ses deux branches (« rattacher » /
 * « détacher »), la colonne qui nomme la famille, l'état des affaires affiché à la place du
 * refus calculé d'avance. Tout cela peut se casser sans qu'aucune route ne bronche.
 *
 * On rend donc le gabarit avec des données fabriquées, sans base : ce qui est vérifié ici,
 * c'est ce que l'utilisateur LIT avant de cliquer — et le clic vaut accord.
 */
class PickerPartageRenduTest extends KernelTestCase
{
    private function condition(string $nom, Invite|Partenaire $beneficiaire, float $taux): ConditionPartage
    {
        $condition = (new ConditionPartage())->setNom($nom)
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux($taux)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES);

        return $beneficiaire instanceof Invite
            ? $condition->setAgent($beneficiaire)
            : $condition->setPartenaire($beneficiaire);
    }

    private function rendre(string $mode = 'rattacher', array $occupations = null): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $alice = (new Invite())->setNom('Alice Apporteuse');
        $sunu = (new Partenaire())->setNom('SUNU Courtage')->setPart(20.0);

        $conditions = $mode === 'detacher'
            ? [$this->condition('Apport SUNU 20%', $sunu, 20.0)]
            : [
                $this->condition('Prime Alice 15%', $alice, 15.0),
                $this->condition('Apport SUNU 20%', $sunu, 20.0),
            ];

        return $twig->render('components/partage/_conditions_picker.html.twig', [
            'entite'      => 'avenant',
            'ids'         => [41, 42],
            'pistes'      => [
                (new Piste())->setNom('Affaire Kibali'),
                (new Piste())->setNom('Affaire Tenke'),
            ],
            'mode'        => $mode,
            'occupations' => $occupations ?? [
                ['affaire' => 'Affaire Kibali', 'partage' => null, 'apporteur' => null],
                ['affaire' => 'Affaire Tenke', 'partage' => 'Apporteur : SUNU Courtage', 'apporteur' => 'SUNU Courtage'],
            ],
            'conditions'  => $conditions,
            'submitUrl'   => '/admin/partage/avenant/' . ($mode === 'detacher' ? 'detacher' : 'rattacher'),
            'standalone'  => true,
        ]);
    }

    /**
     * LES DEUX FAMILLES SONT PROPOSÉES, ET CHACUNE DIT LAQUELLE ELLE EST.
     *
     * Rattacher un apporteur n'a pas les mêmes conséquences que rattacher un agent : le
     * premier désigne aussi l'intermédiaire de l'affaire. Une liste qui ne dirait pas la
     * famille ferait poser ce geste à l'aveugle.
     */
    public function testLesDeuxFamillesSontNomeesDansLaListe(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('Prime Alice 15%', $html);
        self::assertStringContainsString('Apport SUNU 20%', $html);
        // Et en mode rattacher, le titre et le pied disent CE geste-là.
        self::assertStringContainsString('Rattacher une condition de partage', $html);
        self::assertStringContainsString('Le rattachement s', $html);
        self::assertStringContainsString('Agent interne', $html);
        self::assertStringContainsString('Apporteur externe', $html);
        // Le taux se lit en POINTS dans tout le logiciel : 20 se lit 20 %.
        self::assertStringContainsString('20,00 %', $html);
    }

    /**
     * L'ÉTAT DE CHAQUE AFFAIRE EST MONTRÉ AVANT LE CLIC.
     *
     * Le picker annonçait le refus calculé d'avance. Ce n'est plus possible : il dépend de
     * la FAMILLE de la condition choisie, et une affaire prise par un agent reste libre
     * pour un apporteur. Montrer l'occupation laisse voir le conflit sans le deviner.
     */
    public function testLOccupationDeChaqueAffaireEstLisible(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('Affaire Kibali', $html);
        self::assertStringContainsString('au cabinet seul', $html);
        self::assertStringContainsString('Affaire Tenke', $html);
        self::assertStringContainsString('Apporteur : SUNU Courtage', $html);
    }

    /** Une affaire apportée SANS condition rattachée le dit — c'est un état à part. */
    public function testUneAffaireApporteeSansConditionLeDit(): void
    {
        $html = $this->rendre(occupations: [
            ['affaire' => 'Affaire Kibali', 'partage' => null, 'apporteur' => 'ASCOMA'],
        ]);

        self::assertStringContainsString('apportée par ASCOMA', $html);
        self::assertStringContainsString('sans condition rattachée', $html);
    }

    /**
     * LE MODE « DÉTACHER » CHANGE LE GESTE, PAS LE PICKER.
     *
     * Un seul chemin pour les deux : le contrôleur Stimulus poste la même charge utile, et
     * c'est l'URL de destination qui décide. Deux pickers auraient divergé au premier
     * ajustement de l'un des deux.
     */
    public function testLeModeDetacherChangeLeGesteEtLaDestination(): void
    {
        $html = $this->rendre('detacher');

        self::assertStringContainsString('Détacher ici', $html);
        self::assertStringNotContainsString('Rattacher ici', $html);

        // LE TITRE ET LE PIED DISENT LE GESTE. Ils annonçaient « Rattacher » quel que
        // soit le mode : l'utilisateur cliquait « Détacher » et lisait le contraire dans
        // l'en-tête de la fenêtre. Défaut invisible à toute assertion de route, et vu
        // seulement en regardant l'écran.
        self::assertStringContainsString('Détacher une condition de partage', $html);
        self::assertStringNotContainsString('Rattacher une condition de partage', $html);
        self::assertStringContainsString('Le détachement s', $html, 'Le pied aussi.');
        self::assertStringContainsString('/admin/partage/avenant/detacher', $html);
        // Et il DIT ce que le détachement ne fait pas : l'apporteur reste désigné.
        self::assertStringContainsString('reste désigné', $html);
    }

    /** En mode rattacher, l'intro annonce la règle « un par camp » et la désignation. */
    public function testLIntroAnnonceLaRegleEtSesConsequences(): void
    {
        $html = mb_strtolower($this->rendre(), 'UTF-8');

        self::assertStringContainsString('apporteur externe', $html);
        self::assertStringContainsString('agent interne', $html);
        // Le balisage coupe la phrase (« par camp » est en gras) : on cherche donc le
        // texte SANS balises, ce qui est aussi ce que l'utilisateur lit.
        // Les espaces du gabarit (retours à la ligne, indentation, insécables) coupent les
        // phrases : on les normalise, comme le navigateur le fait à l'affichage.
        $texte = preg_replace('/\s+/u', ' ', mb_strtolower(strip_tags($this->rendre()), 'UTF-8'));
        $texte = str_replace(" ", ' ', $texte);
        self::assertStringContainsString('un bénéficiaire par camp', $texte);
        self::assertStringContainsString('ne pourra plus être défait', $texte);
    }

    /** Sans aucune condition, le picker le dit — différemment selon le geste. */
    public function testUneListeVideSeDitSelonLeGeste(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $contexte = [
            'entite' => 'avenant', 'ids' => [41], 'pistes' => [(new Piste())->setNom('Affaire Kibali')],
            'occupations' => [], 'conditions' => [], 'submitUrl' => '/x', 'standalone' => true,
        ];

        $rattacher = $twig->render('components/partage/_conditions_picker.html.twig', $contexte + ['mode' => 'rattacher']);
        self::assertStringContainsString('Aucune condition de partage n', $rattacher);

        $detacher = $twig->render('components/partage/_conditions_picker.html.twig', ['mode' => 'detacher'] + $contexte);
        self::assertStringContainsString('il n', $detacher);
        self::assertStringContainsString('rien à détacher', $detacher);
    }
}
