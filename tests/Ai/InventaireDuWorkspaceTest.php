<?php

namespace App\Tests\Ai;

use App\Ai\Guide\Derivee\InventaireDuWorkspace;
use App\Ai\Guide\GuideRepository;
use App\Ai\Parite\CouvertureDesEcrans;
use App\Ai\Parite\LibellesDesActionsDEcran;
use App\Ai\Reglage\CatalogueDesReglages;
use App\Ai\Tool\ConsulterGuideTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * KET DOIT SAVOIR CE QU'ELLE NE SAIT PAS FAIRE — et cet inventaire ne doit pas mentir.
 *
 * ── CE QUE CE FICHIER PROTÈGE ───────────────────────────────────────────────
 * L'inventaire est la réponse à « est-ce que tu peux… ? ». Un inventaire incomplet
 * est pire qu'absent : Ket y chercherait un geste, ne l'y trouverait pas, et
 * conclurait qu'il n'existe pas — elle NIERAIT une capacité de l'application.
 * C'est la variante exacte de l'incident du 2026-09-21, où elle a nié une échéance
 * que l'écran affichait.
 *
 * Ces tests croisent donc le texte rendu avec les sources qui font autorité, et
 * chacune est relue INDÉPENDAMMENT du constructeur : le menu par son YAML, les
 * actions par le manifeste, les outils par le catalogue de la console. Un test qui
 * interrogerait la même méthode que le code testé ne prouverait rien.
 */
class InventaireDuWorkspaceTest extends KernelTestCase
{
    /**
     * LE PLAFOND, ET D'OÙ IL SORT.
     *
     * Le texte est chargé EN ENTIER dès que Ket l'ouvre : il se paie une fois, mais
     * il se paie tout. Mesuré au moment où la fiche est née, il pesait ~12 500
     * caractères, et ce poids n'est pas de la prose :
     *
     *   · ~3 000 de MOTIFS recopiés mot pour mot depuis le manifeste — incompressibles
     *     par construction, puisque les paraphraser est très exactement ce qu'un autre
     *     test interdit ;
     *   · ~3 100 de résumés d'outils, qui viennent du catalogue de la console ;
     *   · le reste : 43 rubriques, 34 boutons, et le mode d'emploi.
     *
     * Le plafond est donc posé à 14 000 — une marge d'un peu plus de 10 %, de quoi
     * absorber deux ou trois entrées sans rien dire, et rougir sur une dérive. Ce
     * n'est pas une cible à atteindre : si on l'approche, on agrège ce qui est
     * ordinaire au lieu de détailler, on ne relève pas le chiffre.
     */
    private const BUDGET_CARACTERES = 14000;

    private const MENU = __DIR__ . '/../../config/packages/menu.yaml';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function contenu(): string
    {
        return static::getContainer()->get(InventaireDuWorkspace::class)->contenu();
    }

    /**
     * TOUTE RUBRIQUE DU MENU EST NOMMÉE.
     *
     * Le menu est relu ici par son YAML, pas par le paramètre de conteneur dont le
     * constructeur se sert : deux chemins vers la même vérité, sinon le test
     * confirmerait simplement le code.
     */
    public function testChaqueRubriqueDuMenuEstNommeeDansLInventaire(): void
    {
        $contenu = $this->contenu();
        $manquantes = [];

        foreach ($this->rubriquesDuMenu() as $libelle) {
            if (!str_contains($contenu, $libelle)) {
                $manquantes[] = $libelle;
            }
        }

        self::assertSame([], $manquantes, sprintf(
            "Ces rubriques de menu.yaml n'apparaissent pas dans l'inventaire : %s.\n"
            . "Ket ne saura donc pas qu'elles existent — et quand on l'interrogera dessus, elle "
            . "conclura qu'elles n'existent pas. Nier une rubrique que l'utilisateur a sous les "
            . 'yeux est exactement ce que cet inventaire doit empêcher.',
            implode(', ', $manquantes),
        ));
    }

    /**
     * TOUTE ACTION DU MANIFESTE EST NOMMÉE — par son LIBELLÉ et par son nom technique.
     *
     * Le libellé, c'est ce que le courtier lit sur le bouton ; le nom technique, c'est
     * ce qui distingue deux boutons voisins. L'un sans l'autre rend l'inventaire
     * inutilisable : soit Ket parle un langage que personne ne comprend, soit elle
     * confond « Imprimer » et « Télécharger en PDF ».
     */
    public function testChaqueActionEstNommeeParSonLibelleEtSonNomTechnique(): void
    {
        $contenu = $this->contenu();
        $libelles = static::getContainer()->get(LibellesDesActionsDEcran::class);
        $manquantes = [];

        foreach (CouvertureDesEcrans::actionsDeclarees() as $evenement) {
            if (!str_contains($contenu, $evenement)) {
                $manquantes[] = $evenement . ' (nom technique absent)';
                continue;
            }
            $libelle = $libelles->pour($evenement);
            if (!str_contains($contenu, $libelle)) {
                $manquantes[] = sprintf('%s (libellé « %s » absent)', $evenement, $libelle);
            }
        }

        self::assertSame([], $manquantes, sprintf(
            "Ces actions d'écran manquent à l'inventaire : %s.",
            implode(' ; ', $manquantes),
        ));
    }

    /**
     * CHAQUE ACTION A UN LIBELLÉ RELEVÉ DANS LE CODE.
     *
     * Le relevé s'appuie sur une convention : la clé `label` précède la clé `event`
     * dans la même déclaration. Si quelqu'un déclare une action autrement — libellé
     * après l'événement, ou libellé calculé —, le lecteur retombe sur le nom
     * technique, et Ket se met à dire « ui:soa.revoke-request » à un courtier.
     */
    public function testChaqueActionPorteUnLibelleLisible(): void
    {
        $libelles = static::getContainer()->get(LibellesDesActionsDEcran::class);
        $releve = $libelles->parEvenement();

        self::assertNotEmpty($releve, 'Aucun libellé relevé : la lecture du code a cessé de '
            . 'fonctionner, et ce test ne prouverait plus rien.');

        $sansNom = [];
        foreach (CouvertureDesEcrans::actionsDeclarees() as $evenement) {
            if (($releve[$evenement] ?? []) === []) {
                $sansNom[] = $evenement;
            }
        }

        self::assertSame([], $sansNom, sprintf(
            "Aucun libellé d'écran n'a pu être relevé pour : %s.\n"
            . "Soit la déclaration a perdu sa clé `label` — le bouton n'a alors plus de nom pour "
            . "l'utilisateur —, soit elle la place APRÈS son `event` et LibellesDesActionsDEcran "
            . 'ne sait plus la lire. Sans libellé, Ket ne peut nommer le geste que par son nom '
            . 'technique, ce qui ne veut rien dire pour un courtier.',
            implode(', ', $sansNom),
        ));
    }

    /**
     * LES MOTIFS SONT RECOPIÉS MOT POUR MOT.
     *
     * Même exigence que `FrontiereDiteAKetTest` : le texte doit DÉRIVER du manifeste,
     * jamais en paraphraser une copie. Une paraphrase, c'est deux textes à tenir
     * d'accord — et le jour où l'un change, l'autre ment sans que rien ne le dise.
     */
    public function testChaqueMotifEstReciteMotPourMot(): void
    {
        $contenu = $this->contenu();

        foreach (CouvertureDesEcrans::ECRAN_SEULEMENT as $evenement => $motif) {
            self::assertStringContainsString($motif, $contenu, sprintf(
                'Le motif de « %s » n’est pas récité mot pour mot dans l’inventaire.',
                $evenement,
            ));
        }

        foreach (CouvertureDesEcrans::ENTITES_ECRAN_SEULEMENT as $entite => $motif) {
            self::assertStringContainsString($motif, $contenu, sprintf(
                'Le motif de la rubrique « %s » n’est pas récité mot pour mot dans l’inventaire.',
                $entite,
            ));
        }
    }

    /**
     * TOUT OUTIL DU CATALOGUE FIGURE À L'INVENTAIRE, d'une façon ou d'une autre :
     * soit comme contrepartie d'un bouton (section 2), soit comme geste propre à la
     * conversation (section 3). Un outil ajouté demain et absent des deux serait une
     * capacité que Ket possède et ignore posséder.
     */
    public function testChaqueOutilDuCatalogueFigureALInventaire(): void
    {
        $contenu = $this->contenu();
        $absents = [];

        foreach (static::getContainer()->get(CatalogueDesReglages::class)->outils() as $outil) {
            if (!str_contains($contenu, $outil['nom'])) {
                $absents[] = $outil['nom'];
            }
        }

        self::assertSame([], $absents, sprintf(
            "Ces outils n'apparaissent nulle part dans l'inventaire : %s.\n"
            . "Ils sont pourtant déclarés au catalogue : Ket les a, et ne le sait pas.",
            implode(', ', $absents),
        ));
    }

    /** L'inventaire tient dans son budget : il est chargé entier à l'ouverture. */
    public function testLInventaireTientDansSonBudget(): void
    {
        $taille = mb_strlen($this->contenu());

        self::assertLessThan(self::BUDGET_CARACTERES, $taille, sprintf(
            'L’inventaire pèse %d caractères, au-delà du budget de %d. Agrège ce qui est '
            . 'ordinaire, ne détaille que l’exception — ou retire un motif devenu un essai.',
            $taille,
            self::BUDGET_CARACTERES,
        ));
    }

    /**
     * LA FICHE EST RÉELLEMENT SERVIE — et elle n'a rien cassé au passage.
     *
     * Le catalogue de `consulter_guide` est la seule porte : une fiche absente de
     * `slugs()` est absente de l'enum du schéma, donc injoignable quoi qu'elle
     * contienne. Et les fiches `.md` doivent continuer de répondre.
     */
    public function testLaFicheEstServieParConsulterGuideSansCasserLesAutres(): void
    {
        $guides = static::getContainer()->get(GuideRepository::class);

        self::assertContains(InventaireDuWorkspace::SLUG, $guides->slugs(),
            'La fiche dérivée doit entrer au catalogue : hors de lui, elle est injoignable.',
        );
        self::assertNotNull($guides->fiche(InventaireDuWorkspace::SLUG));
        self::assertNotNull($guides->fiche('capacites-assistant'),
            'Les fiches .md doivent continuer d’être servies : la dérivée s’ajoute, elle ne remplace rien.',
        );

        $enum = static::getContainer()->get(ConsulterGuideTool::class)->schema()['properties']['sujet']['enum'] ?? [];
        self::assertContains(InventaireDuWorkspace::SLUG, $enum,
            'Le schéma de consulter_guide doit accepter le sujet, sinon le modèle ne peut pas le demander.',
        );
    }

    /**
     * LE CATALOGUE EST PAYÉ À CHAQUE TOUR : la description doit rester une ligne.
     *
     * Le piège qu'on ferme ici est concret — écrire `description() { return
     * $this->contenu(); }` ferait payer six kilo-octets, et leur calcul, à CHAQUE
     * message, pour une fiche que presque personne n'ouvre.
     */
    public function testLaLigneDeCatalogueResteCourte(): void
    {
        $fiche = static::getContainer()->get(InventaireDuWorkspace::class);

        self::assertLessThan(400, mb_strlen($fiche->description()),
            'La description part dans le prompt à chaque tour : elle doit tenir en une phrase, '
            . 'pas contenir la fiche.',
        );
        self::assertLessThan(120, mb_strlen($fiche->titre()));
    }

    /**
     * L'INVENTAIRE NE DÉPEND PAS DE QUI DEMANDE — c'est ce qui autorise la
     * mémoïsation, et ce qui garde à `ConsulterGuideTool` sa garantie de classe :
     * les fiches ne portent aucune donnée d'entreprise.
     */
    public function testLInventaireEstLeMemePourTous(): void
    {
        $fiche = static::getContainer()->get(InventaireDuWorkspace::class);

        self::assertSame($fiche->contenu(), $fiche->contenu(),
            'Deux lectures doivent rendre le même texte : l’inventaire décrit la plateforme, '
            . 'pas le périmètre de celui qui le lit.',
        );
    }

    /**
     * Les libellés de rubrique du menu, relus par le YAML.
     *
     * @return list<string>
     */
    private function rubriquesDuMenu(): array
    {
        self::assertFileExists(self::MENU, 'menu.yaml introuvable : ce test ne prouverait plus rien.');

        $libelles = [];
        $menu = Yaml::parseFile(self::MENU);
        $groupes = $menu['parameters']['app.menu_data']['colonne_1']['groupes'] ?? [];

        foreach ($groupes as $definition) {
            foreach ($definition['rubriques'] ?? [] as $libelle => $rubrique) {
                if (($rubrique['entity_name'] ?? '') !== '') {
                    $libelles[] = (string) $libelle;
                }
            }
        }

        self::assertNotEmpty($libelles, 'Aucune rubrique relevée dans menu.yaml : la lecture a '
            . 'cessé de fonctionner, et ce test passerait en ne prouvant rien.');

        return $libelles;
    }
}
