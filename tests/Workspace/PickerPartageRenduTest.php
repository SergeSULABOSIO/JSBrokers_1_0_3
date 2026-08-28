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

    /**
     * Rend le picker avec DEUX affaires et trois conditions dans les trois états possibles.
     *
     * `$couvertures` dit, par condition, combien des deux affaires elle couvre déjà — c'est
     * cet état, et lui seul, qui décide du verbe de la ligne.
     */
    private function rendre(array $couvertures = ['Prime Alice 15%' => 0, 'Apport SUNU 20%' => 2, 'Effort Bruno 10%' => 1], array $occupations = null): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $alice = (new Invite())->setNom('Alice Apporteuse');
        $bruno = (new Invite())->setNom('Bruno Kalala');
        $sunu = (new Partenaire())->setNom('SUNU Courtage')->setPart(20.0);

        $catalogue = [
            'Prime Alice 15%'   => $this->condition('Prime Alice 15%', $alice, 15.0),
            'Apport SUNU 20%'   => $this->condition('Apport SUNU 20%', $sunu, 20.0),
            'Effort Bruno 10%'  => $this->condition('Effort Bruno 10%', $bruno, 10.0),
        ];

        $total = 2;
        $lignes = [];
        foreach ($couvertures as $nom => $couvertes) {
            $lignes[] = [
                'condition' => $catalogue[$nom],
                'couvertes' => $couvertes,
                'total'     => $total,
                'etat'      => match (true) {
                    $couvertes === 0 => 'libre',
                    $couvertes >= $total => 'rattachee',
                    default => 'partielle',
                },
            ];
        }

        return $twig->render('components/partage/_conditions_picker.html.twig', [
            'entite'      => 'avenant',
            'ids'         => [41, 42],
            'pistes'      => [
                (new Piste())->setNom('Affaire Kibali'),
                (new Piste())->setNom('Affaire Tenke'),
            ],
            'occupations' => $occupations ?? [
                ['affaire' => 'Affaire Kibali', 'partage' => null, 'apporteur' => null],
                ['affaire' => 'Affaire Tenke', 'partage' => 'Apporteur : SUNU Courtage', 'apporteur' => 'SUNU Courtage'],
            ],
            'conditions'   => $lignes,
            'urlRattacher' => '/admin/partage/avenant/rattacher',
            'urlDetacher'  => '/admin/partage/avenant/detacher',
            'standalone'   => true,
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
        // Le titre ne nomme plus un geste : la vue les porte tous les deux.
        self::assertStringContainsString('Gérer le partage', $html);
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
     * CHAQUE LIGNE PORTE SON VERBE, ET SA DESTINATION.
     *
     * Le picker proposait « Rattacher ici » sur une condition DÉJÀ rattachée à l'affaire.
     * Le clic revenait avec un refus qui nommait cette même condition — « détachez la
     * condition en place avant d'en rattacher une autre », où la condition en place ÉTAIT
     * celle qu'on venait de choisir. Le geste ne doit pas être offert.
     *
     * Le mode « détacher » a disparu avec ce défaut : il n'y a plus qu'une vue, et c'est
     * l'état de chaque ligne qui décide.
     */
    public function testChaqueLignePorteSonVerbeEtSaDestination(): void
    {
        $html = $this->rendre();

        // Libre → rattacher ; déjà posée sur les deux affaires → détacher.
        self::assertStringContainsString('Rattacher ici', $html);
        self::assertStringContainsString('Détacher', $html);
        self::assertStringContainsString('Déjà rattachée', $html);

        // Et chaque bouton porte SA route : c'est ce qui permet un seul picker.
        self::assertStringContainsString('data-action-url="/admin/partage/avenant/rattacher"', $html);
        self::assertStringContainsString('data-action-url="/admin/partage/avenant/detacher"', $html);
    }

    /**
     * L'ÉTAT PARTIEL PROPOSE DE COMPLÉTER, et dit combien il reste.
     *
     * C'est le seul des trois cas qui demandait une décision : une condition posée sur une
     * partie de la sélection. Compléter est le geste utile — et c'est déjà ce que fait le
     * serveur, le rattachement étant idempotent affaire par affaire.
     */
    public function testUneConditionPartiellementPoseeProposeDeCompleter(): void
    {
        $html = $this->rendre();

        // Le SINGULIER a sa propre tournure : « Rattacher aux 1 restante » ne se dit pas.
        // Twig échappe l'apostrophe en « &#039; » : on décode plutôt que d'écrire
        // l'entité, qui lierait le test à la stratégie d'échappement.
        self::assertStringContainsString(
            'Rattacher à l\'affaire restante',
            html_entity_decode($html, ENT_QUOTES, 'UTF-8'),
        );
        self::assertStringContainsString('Rattachée à 1 sur 2', $html);
    }

    /** Le picker ne prétend plus faire UN geste : il gère le partage. */
    public function testLeTitreEtLePiedNAnnoncentPlusUnSeulGeste(): void
    {
        $html = $this->rendre();

        self::assertStringContainsString('Gérer le partage', $html);
        self::assertStringNotContainsString('Détacher une condition de partage', $html);
        self::assertStringContainsString('Rattachement comme détachement', $html);
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
        self::assertStringContainsString('plus rien ne se défait', $texte);
        // Et l'intro annonce que le détachement se fait d'ici, sans second écran.
        self::assertStringContainsString('se détache d\'ici même', $texte);
    }

    /** Sans aucune condition, le picker le dit — et renvoie là où on en crée. */
    public function testUneListeVideRenvoieVersLaRubrique(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $contexte = [
            'entite' => 'avenant', 'ids' => [41], 'pistes' => [(new Piste())->setNom('Affaire Kibali')],
            'occupations' => [], 'conditions' => [],
            'urlRattacher' => '/admin/partage/avenant/rattacher',
            'urlDetacher' => '/admin/partage/avenant/detacher',
            'standalone' => true,
        ];

        $rattacher = $twig->render('components/partage/_conditions_picker.html.twig', $contexte);
        self::assertStringContainsString('Aucune condition de partage n', $rattacher);

        // Un seul message désormais : il n'y a plus deux vues à distinguer.
        self::assertStringContainsString('revenez ici', $rattacher);
    }
}
