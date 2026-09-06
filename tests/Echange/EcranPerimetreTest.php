<?php

namespace App\Tests\Echange;

use App\Echange\Canevas\CanevasDEchange;
use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'ÉCRAN DE LA RUBRIQUE, RENDU POUR DE VRAI.
 *
 * Les autres tests de ce dossier prouvent que le serveur calcule juste. Celui-ci prouve
 * que l'écran s'affiche — ce qui n'est pas la même chose, et ce qui manquait : une route
 * nommée à côté (`admin_echange_gabarit` au lieu de `admin.echange.gabarit`) passe toutes
 * les vérifications de service et casse la page entière au premier affichage.
 *
 * Il vérifie aussi que les trois gestes ajoutés sont bien LÀ, atteignables, et pas
 * seulement implémentés quelque part.
 */
class EcranPerimetreTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-echange-ecran@test.local';
    private const ENT = 'PHPUnit Écran SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le groupement par module
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ AUTANT DE GROUPES QUE DE MODULES, ET TOUTES LES DONNÉES DEDANS.
     *
     * Le regroupement n'a d'intérêt que s'il est EXHAUSTIF : une donnée oubliée par les
     * groupes serait invisible à l'écran alors qu'elle sortirait dans le fichier — un
     * export qui contient plus que ce qu'on a coché.
     */
    public function testLExportPresenteLesDonneesGroupeesParModule(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $ressources = static::getContainer()->get(CanevasDEchange::class)->toutes();
        $modules = array_unique(array_map(static fn ($r) => $r->module, $ressources));

        self::assertCount(
            \count($modules),
            $crawler->filter('details.ech-groupe'),
            'Il doit y avoir exactement un groupe repliable par module.',
        );

        // Chaque donnée du périmètre a sa case, dans un groupe.
        foreach ($ressources as $code => $ressource) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('input[data-echange-code-param="%s"]', $code)),
                sprintf('La donnée « %s » n%sapparaît dans aucun groupe.', $ressource->libelle, "'"),
            );
        }
    }

    /**
     * La case d'un module et les cases de ses lignes se désignent par le MÊME nom de
     * module. Sans cela, cocher l'en-tête ne toucherait rien : le geste de confort
     * échouerait en silence, ce qui est pire que de ne pas l'offrir.
     */
    public function testChaqueEnTeteDeModuleDesigneDesLignes(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $enTetes = $crawler->filter('button.jsb-preset-chip[data-echange-target="module"]');
        self::assertGreaterThan(0, $enTetes->count());

        foreach ($enTetes as $noeud) {
            $module = $noeud->getAttribute('data-echange-module-param');
            self::assertNotSame('', (string) $module);

            $lignes = $crawler->filter(sprintf(
                'input[data-echange-target="donnee"][data-echange-module-param="%s"]',
                $module,
            ));
            self::assertGreaterThan(
                0,
                $lignes->count(),
                sprintf('Le groupe « %s » ne commande aucune ligne.', $module),
            );
        }
    }

    /**
     * ⚠ LES CHIPS SONT CEUX DES LISTES, PAS DES SOSIES.
     *
     * Un gabarit qui ressemble au modèle sans en être un se met à en diverger dès la
     * première retouche de la charte, et l'écran finit par avoir sa propre grammaire.
     * On vérifie donc la structure canonique : barre, pilule, titre purement textuel.
     */
    public function testLesFamillesSuiventLeGabaritDeChipsDesListes(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $groupe = $crawler->filter('.jsb-preset-filters-bar .jsb-preset-filters.jsb-control-pill[aria-label="Familles de données"]');
        self::assertCount(1, $groupe, 'La barre de familles doit suivre le gabarit des listes.');
        self::assertSame('familles', trim($groupe->filter('.jsb-preset-filters__titre')->text()));

        // Trois états possibles, et aucun autre : un chip qui n'annonce rien laisserait
        // un lecteur d'écran muet sur ce qui va sortir du cabinet.
        foreach ($groupe->filter('button.jsb-preset-chip[data-echange-target="module"]') as $chip) {
            self::assertContains(
                $chip->getAttribute('aria-pressed'),
                ['true', 'false', 'mixed'],
                'Un chip de famille doit annoncer son état.',
            );
            self::assertNotSame('', trim($chip->textContent));
        }
    }

    /**
     * ⚠ L'EXPORT ARRIVE SUR LA PRODUCTION, PAS SUR TOUT.
     *
     * Exporter les quarante-deux données est rarement ce qu'on veut : le geste courant
     * est de sortir son activité, pas ses taxes ni ses types d'absence. Proposer tout
     * revenait à faire décocher trente lignes à chaque fois — donc, en pratique, à ne
     * rien décocher du tout.
     */
    public function testLExportArriveSurLesDonneesDeProduction(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $canevas = static::getContainer()->get(CanevasDEchange::class);
        $attendus = $canevas->codesParDefaut($canevas->toutes());
        self::assertNotEmpty($attendus);

        foreach ($canevas->toutes() as $code => $ressource) {
            $case = $crawler->filter(sprintf('input[data-echange-code-param="%s"]', $code));
            self::assertSame(
                \in_array($code, $attendus, true),
                $case->getNode(0)->hasAttribute('checked'),
                sprintf('« %s » n%sest pas dans l%sétat attendu à l%sarrivée.', $ressource->libelle, "'", "'", "'"),
            );
        }

        // Et ce n'est pas « tout » déguisé : des familles entières restent dehors.
        self::assertLessThan(\count($canevas->toutes()), \count($attendus));
    }

    /**
     * ⚠ LE DÉFAUT EST FERMÉ SUR SES DÉPENDANCES.
     *
     * Une police a besoin de la piste dont elle est née, laquelle vit dans une autre
     * famille. Un défaut qui s'arrêterait au module produirait un fichier renvoyant vers
     * des lignes absentes — donc un fichier qu'on ne peut pas réimporter.
     */
    public function testLeDefautTireCeQueLaProductionAppelle(): void
    {
        $canevas = static::getContainer()->get(CanevasDEchange::class);
        $toutes = $canevas->toutes();
        $defaut = $canevas->codesParDefaut($toutes);

        foreach ($defaut as $code) {
            foreach ($toutes[$code]->dependances as $dep) {
                if (!isset($toutes[$dep])) {
                    continue; // hors périmètre lisible : le droit prime sur la complétude
                }
                self::assertContains(
                    $dep,
                    $defaut,
                    sprintf('« %s » a besoin de « %s », qui n%sest pas retenu.', $code, $dep, "'"),
                );
            }
        }
    }

    /**
     * ⚠ L'IMPORT ARRIVE RESTREINT — ET C'EST DIT AVANT LE DÉPÔT.
     *
     * Écarter d'office des feuilles d'un fichier que l'utilisateur vient de déposer, sans
     * le lui dire, ce serait en sauter une part à son insu : le volet du périmètre est
     * replié, et il n'ouvrirait jamais un réglage dont il ignore qu'il est actif. La
     * restriction et son annonce ne se séparent pas — ce test les tient ensemble.
     */
    public function testLImportArriveSurLaProductionEtLeDit(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $canevas = static::getContainer()->get(CanevasDEchange::class);
        $attendus = $canevas->codesParDefaut($canevas->toutes());

        $cases = $crawler->filter('details.ech-perimetre-volet input[data-echange-target="donnee"]');
        self::assertGreaterThan(0, $cases->count());

        foreach ($cases as $case) {
            $code = $case->getAttribute('data-echange-code-param');
            self::assertSame(
                \in_array($code, $attendus, true),
                $case->hasAttribute('checked'),
                sprintf('« %s » n%sest pas dans l%sétat attendu au dépôt.', $code, "'", "'"),
            );
        }

        // ⚠ L'ANNONCE, AVANT LE DÉPÔT. Sans elle, la restriction serait une trahison.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('seules vos <strong>données de production</strong> seront reprises', $html);
        self::assertLessThan(
            strpos($html, '<div class="ech-depot">'),
            strpos($html, 'seront reprises'),
            "L'annonce doit précéder le dépôt : après, il est trop tard pour en tenir compte.",
        );
    }

    /**
     * Le décompte du volet se lit VOLET FERMÉ. C'est ce qui permet de ne pas ouvrir
     * d'office un panneau haut de deux écrans à chaque visite.
     */
    public function testLeResumeDuVoletPorteLeDecompte(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $resume = $crawler->filter('summary.ech-perimetre-tete [data-echange-target="resumePerimetre"]');
        self::assertCount(1, $resume);

        $canevas = static::getContainer()->get(CanevasDEchange::class);
        $attendus = \count($canevas->codesParDefaut($canevas->toutes()));
        self::assertStringContainsString((string) $attendus, $resume->text());
        self::assertStringContainsString((string) \count($canevas->toutes()), $resume->text());
    }

    /**
     * ⚠ LE RAPPEL D'UN CHOIX RESTAURÉ EST PRÉSENT, ET MASQUÉ.
     *
     * Il ne peut pas être rendu par le serveur : le choix vit dans le navigateur, et PHP
     * ne le connaît pas. Le gabarit doit donc le poser masqué pour que le contrôleur ait
     * quelque chose à démasquer — s'il manque, la restauration se fait en SILENCE, et
     * quelqu'un qui reprend le poste le lendemain exporte une partie de son cabinet en
     * croyant tout exporter.
     */
    public function testLeRappelDUnChoixRestaureExisteEtEstMasque(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $rappel = $crawler->filter('[data-echange-target="rappelRestauration"]');
        self::assertCount(1, $rappel, "Le contrôleur n'aurait rien à démasquer.");
        self::assertTrue($rappel->getNode(0)->hasAttribute('hidden'), 'Le rappel doit partir masqué.');
        self::assertStringContainsString('Tout cocher', $rappel->text());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les trois gestes
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ LE GABARIT VIT DANS LES DEUX ONGLETS, ET SON LIEN SUIT LES CASES.
     *
     * C'est un outil d'IMPORT : on le remplit pour le déposer. Ne l'offrir que dans
     * « Exporter » obligeait à passer par un écran qui commande autre chose. Et son lien
     * doit porter la cible que le contrôleur réécrit, sinon il rendrait les quarante-deux
     * feuilles quoi qu'on ait coché — c'est-à-dire le contraire de ce qu'il promet.
     *
     * @dataProvider ongletsPortantLePerimetre
     */
    public function testLeGabaritEstProposeDansLesDeuxOnglets(string $onglet): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=%s', $entreprise->getId(), $onglet));
        self::assertResponseIsSuccessful();

        $lien = $crawler->filter('a[data-echange-target="lienGabarit"]');
        self::assertCount(1, $lien, sprintf('Le gabarit doit être atteignable depuis « %s ».', $onglet));
        self::assertSame(sprintf('/admin/echange/gabarit/%d', $entreprise->getId()), $lien->attr('href'));

        // Le libellé est réécrit par le contrôleur : il lui faut sa cible.
        self::assertCount(1, $lien->filter('[data-echange-target="libelleGabarit"]'));

        // ⚠ Le mot « gratuit » n'est pas décoratif : à côté d'un export facturé, un geste
        // dont on ne dit pas le prix est un geste qu'on n'ose pas faire.
        self::assertStringContainsString('Gratuit', $crawler->filter('.ech-gabarit')->text());
    }

    /**
     * ⚠ LE NOM DU FICHIER DIT SON PÉRIMÈTRE.
     *
     * Deux gabarits du même cabinet posés côte à côte sur un bureau ne se distinguaient
     * que par l'heure de génération — et l'un portait les taxes, l'autre non. On remplit
     * alors le mauvais, et on ne s'en aperçoit qu'au contrôle.
     */
    public function testLeNomDuGabaritDitCeQuIlContient(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $canevas = static::getContainer()->get(CanevasDEchange::class);
        $lisibles = $canevas->ressourcesLisibles($invite);

        // Une seule donnée : la famille est coupée en deux, le nom doit le dire.
        $this->client->request('GET', sprintf('/admin/echange/gabarit/%d?donnees=Client', $entreprise->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '_partiel',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );

        // Le périmètre d'office : la production ENTIÈRE, plus ce qu'elle appelle
        // ailleurs. Le nom doit porter les deux — « production » seul mentirait par
        // omission, « partiel » perdrait la seule information utile.
        $production = [];
        foreach ($lisibles as $code => $ressource) {
            if ($ressource->module === CanevasDEchange::MODULE_PAR_DEFAUT) {
                $production[] = $code;
            }
        }
        self::assertNotEmpty($production);

        $this->client->request('GET', sprintf(
            '/admin/echange/gabarit/%d?donnees=%s',
            $entreprise->getId(),
            implode(',', $canevas->codesParDefaut($lisibles)),
        ));
        self::assertResponseIsSuccessful();
        $entete = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('production-et-liens', $entete);

        // Tout le périmètre : rien à préciser, le nom reste celui d'avant.
        $this->client->request('GET', sprintf('/admin/echange/gabarit/%d', $entreprise->getId()));
        self::assertResponseIsSuccessful();
        $entier = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringNotContainsString('_partiel', $entier);
        self::assertStringNotContainsString('_production', $entier);
    }

    /**
     * ⚠ LE GABARIT N'EST PAS ENFERMÉ DANS LE VOLET DE RÉGLAGE.
     *
     * Il y a vécu, et c'était une panne d'usage plus qu'un défaut d'affichage : à
     * l'import, le périmètre est un panneau replié, et le gabarit disparaissait avec lui.
     * Or c'est le jour de la PREMIÈRE reprise qu'on en a besoin — quand on n'a encore
     * rien à restreindre, donc aucune raison d'ouvrir un panneau intitulé « choisir ce
     * qu'on reprend ». Le seul outil qui rendait la reprise possible était caché derrière
     * le geste qu'on ne fait pas.
     *
     * @dataProvider ongletsPortantLePerimetre
     */
    public function testLeGabaritNEstPasEnfermeDansLeVoletDeReglage(string $onglet): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=%s', $entreprise->getId(), $onglet));
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[data-echange-target="lienGabarit"]'));
        self::assertCount(
            0,
            $crawler->filter('details.ech-perimetre-volet a[data-echange-target="lienGabarit"]'),
            "Un livrable enfermé dans un réglage replié n'existe pas pour qui n'ouvre pas le réglage.",
        );

        // ⚠ L'ORDRE PAR RAPPORT AU DÉPÔT NE VAUT QU'À L'IMPORT : l'onglet Exporter n'a
        // pas de dépôt, et l'y chercher rendrait `false`, qu'une comparaison numérique
        // avalerait sans rien prouver.
        if ($onglet === 'importer') {
            $html = (string) $this->client->getResponse()->getContent();
            self::assertLessThan(
                strpos($html, '<div class="ech-depot">'),
                strpos($html, 'data-echange-target="lienGabarit"'),
                'Le gabarit doit précéder le dépôt : on le remplit pour le déposer.',
            );
        }
    }

    /**
     * ⚠ LE RÉGLAGE FERME LA MARCHE, DES DEUX CÔTÉS.
     *
     * Il barrait le chemin entre l'annonce et le bouton qu'on venait chercher. Sa place
     * dans la PAGE a changé ; sa place dans l'ENCHAÎNEMENT, non : il reste avant le
     * contrôle, qui est le compte rendu de ce qui sera écrit.
     */
    public function testLeReglageFermeLaMarcheALImport(): void
    {
        [$entreprise] = $this->fixture();

        $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, '<details class="ech-perimetre-volet">'),
            strpos($html, 'Le contrôle est gratuit'),
            'Le volet vient après le dépôt et son explication, pas en travers du chemin.',
        );
    }

    /**
     * ⚠ LES DEUX SORTIES DU CABINET SONT À LA MÊME BARRE, ET LE RÉGLAGE EST EN DESSOUS.
     *
     * « Générer l'export » et « Gabarit vierge » produisent tous deux un .xlsx : les
     * séparer obligeait l'œil à chercher le second plus bas, au milieu d'un réglage. Le
     * volet, lui, ferme la marche — c'est ce qu'on ne touche pas la plupart du temps.
     */
    public function testLesDeuxSortiesSontALaMemeBarreEtLeReglageEnDessous(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=exporter', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        $barre = $crawler->filter('.ech-actions.ech-actions--tete');
        self::assertCount(1, $barre);
        self::assertCount(1, $barre->filter('[data-echange-target="boutonExport"]'));
        self::assertCount(
            1,
            $barre->filter('a[data-echange-target="lienGabarit"]'),
            "Le gabarit doit se tenir à la même barre que l'export.",
        );

        // Le réglage ferme la marche.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, '<details class="ech-perimetre-volet">'),
            strpos($html, 'data-echange-target="lienGabarit"'),
            'Le volet du périmètre vient après les deux boutons, pas entre eux.',
        );
    }

    /**
     * ⚠ LE PÉRIMÈTRE EST REPLIÉ DANS LES DEUX ONGLETS, ET SON RÉSUMÉ DIT L'ESSENTIEL.
     *
     * Cinq groupes et quarante-deux lignes déroulés d'office, c'était trois écrans de
     * hauteur avant d'atteindre le bouton — pour un réglage que la plupart des exports
     * ne touchent jamais. Mais replier sans résumer aurait été pire : l'écran aurait
     * caché ce qu'il fait. Les deux vont ensemble, et ce test les tient ensemble.
     *
     * @dataProvider ongletsPortantLePerimetre
     */
    public function testLeVoletDuPerimetreEstReplieEtResume(string $onglet): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=%s', $entreprise->getId(), $onglet));
        self::assertResponseIsSuccessful();

        $volet = $crawler->filter('details.ech-perimetre-volet');
        self::assertCount(1, $volet, sprintf('Le périmètre de « %s » doit être un volet.', $onglet));
        self::assertFalse(
            $volet->getNode(0)->hasAttribute('open'),
            "Un réglage que la plupart des exports ne touchent pas ne doit pas occuper l'écran.",
        );

        // Le résumé porte le décompte : c'est ce qui rend la restriction lisible SANS
        // ouvrir le volet — sinon l'économie d'espace se paierait d'un écran qui ment.
        $canevas = static::getContainer()->get(CanevasDEchange::class);
        $resume = $volet->filter('summary [data-echange-target="resumePerimetre"]');
        self::assertCount(1, $resume);
        self::assertStringContainsString(
            (string) \count($canevas->codesParDefaut($canevas->toutes())),
            $resume->text(),
        );

        // ⚠ LE TITRE DIT CE QUE LE VOLET GOUVERNE VRAIMENT. À l'import, ce qu'on reprend
        // du fichier. À l'export, PLUS l'export lui-même — l'état a une forme fixe — mais
        // le seul gabarit vierge. Un titre resté sur « ce qu'on exporte » laisserait
        // croire que ces cases filtrent un fichier qu'elles ne touchent pas.
        $titre = $volet->filter('summary span')->first()->text();
        self::assertStringContainsString($onglet === 'importer' ? 'reprend' : 'gabarit', $titre);
    }

    /** @return iterable<string, array{0: string}> */
    public static function ongletsPortantLePerimetre(): iterable
    {
        yield 'exporter' => ['exporter'];
        yield 'importer' => ['importer'];
    }

    /**
     * Le choix de ce qu'on reprend est offert, et ce qu'il vaut est ANNONCÉ avant le dépôt.
     *
     * ⚠ CE N'EST PLUS LE VOLET QUI DOIT PRÉCÉDER LE DÉPÔT — il ferme désormais la marche,
     * pour ne pas barrer le chemin vers le bouton qu'on vient chercher. Ce qui doit le
     * précéder, c'est l'ANNONCE de ce qui sera repris : sans elle, on déposerait un
     * classeur complet en ignorant qu'il n'en sera pris qu'une part. Le réglage peut
     * attendre ; l'information, non.
     */
    public function testLOngletImporterOffreLeChoixDesDonnees(): void
    {
        [$entreprise] = $this->fixture();

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('details.ech-perimetre-volet'));
        self::assertGreaterThan(
            0,
            $crawler->filter('details.ech-perimetre-volet button.jsb-preset-chip[data-echange-target="module"]')->count(),
        );

        // ⚠ On cherche les BALISES, pas les noms de classes : la feuille de style, posée
        // en tête du composant, contient les deux sélecteurs et fausserait la comparaison.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, '<div class="ech-depot">'),
            strpos($html, 'seront reprises'),
            "L'annonce de ce qui sera repris doit précéder le dépôt.",
        );
    }

    /**
     * Le fichier annoté n'est proposé QUE lorsqu'il y a quelque chose à corriger — et il
     * l'est même quand le contrôle est confirmable : un avertissement mérite d'être vu en
     * place avant qu'on ne l'accepte.
     */
    public function testLeFichierAnnoteEstProposeQuandLeRapportPorteDesAnomalies(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->controleEnAttente($entreprise, $invite, [
            'anomalies' => [
                ['gravite' => 'AVERTISSEMENT', 'feuille' => 'Clients', 'ligne' => 47, 'colonne' => 'M', 'message' => 'Valeur inattendue.'],
            ],
        ]);

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter(sprintf('a[href="/admin/echange/importer/%d/%d/anomalies"]', $entreprise->getId(), $run->getId())),
            'Le fichier annoté doit être téléchargeable dès qu une anomalie est signalée.',
        );
    }

    /** Sans anomalie, pas de bouton : proposer de corriger un fichier juste est du bruit. */
    public function testAucunFichierAnnoteQuandLeRapportEstVierge(): void
    {
        [$entreprise, $invite] = $this->fixture();

        $run = $this->controleEnAttente($entreprise, $invite, ['anomalies' => []]);

        $crawler = $this->client->request('GET', sprintf('/admin/echange/workspace/%d?onglet=importer', $entreprise->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter(sprintf('a[href="/admin/echange/importer/%d/%d/anomalies"]', $entreprise->getId(), $run->getId())),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $rapport */
    private function controleEnAttente(Entreprise $entreprise, Invite $invite, array $rapport): EchangeImportRun
    {
        $em = $this->em();

        $run = new EchangeImportRun();
        $run->setNomFichier('depot.xlsx');
        $run->setStatut(EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION);
        $run->setExpireLe(new \DateTimeImmutable('+1 hour'));
        $run->setRapport($rapport);
        $run->setEntreprise($entreprise);
        $run->setInvite($invite);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function fixture(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Ecran')->setVerified(true)->setPassword('x');
        $owner->setPaidTokens(500000);
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);
        $em->flush();

        $proprietaire = (new Invite())->setNom('Le Patron')->setEmail(self::OWNER_EMAIL);
        $proprietaire->setProprietaire(true);
        $proprietaire->setEntreprise($entreprise);
        $proprietaire->setUtilisateur($owner);
        $em->persist($proprietaire);

        $admin = (new RolesEnAdministration())->setNom('Admin');
        $admin->setAccessEchange([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE]);
        $admin->setEntreprise($entreprise);
        $admin->setInvite($proprietaire);
        $em->persist($admin);
        $proprietaire->addRolesEnAdministration($admin);

        $em->flush();

        $this->client->loginUser($owner);

        return [$entreprise, $proprietaire];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Purge dérivée du schéma — cf. ExportJsbxTest, même raison. */
    private function nettoyer(): void
    {
        $cnx = $this->em()->getConnection();
        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['echange_import_run', 'echange_occurrence', 'token_consumption', 'roles_en_administration', 'invite', 'entreprise', 'utilisateur'] as $table) {
            $cnx->executeStatement(sprintf('DELETE FROM `%s` WHERE 1', $table));
        }
        $cnx->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
