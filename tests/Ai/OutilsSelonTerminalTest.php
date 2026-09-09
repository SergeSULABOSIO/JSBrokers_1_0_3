<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolConditionnel;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Terminal\Terminal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * KET NE PROMET PAS UN ÉCRAN QUI N'EXISTE PAS.
 *
 * Sur téléphone et tablette, la conversation est la seule surface : ni menu, ni
 * onglet, ni colonne de fiche. Trois outils font BOUGER une colonne
 * (`ouvrir_rubrique`, `fermer_rubrique`, `visualiser_fiche`) et ne sont donc pas
 * déclarés au modèle dans ce mode — cf. `App\Ai\Tool\ExigeLesColonnes`.
 *
 * ── POURQUOI RETIRER L'OUTIL PLUTÔT QUE NEUTRALISER SON EFFET ────────────────────
 * Laisser l'outil déclaré donnerait le pire des deux mondes : Ket annoncerait
 * « j'ouvre la liste de vos clients », et rien ne se passerait. Privée de l'outil,
 * elle répond avec ce qu'elle a — le tableau, le chiffre, la liste dans le fil.
 *
 * ── CE QUE CE TEST PROTÈGE, DANS LES DEUX SENS ───────────────────────────────────
 *  - LE RETRAIT DOIT AVOIR LIEU. Un trait oublié sur l'un des trois outils rendrait
 *    la promesse impossible à tenir, et seulement sur un appareil que le
 *    développeur n'a pas sous la main.
 *  - LE RETRAIT NE DOIT PAS DÉBORDER. C'est le risque le plus grave : `ouvrir_dialogue`
 *    ne dépend PAS des colonnes (`dialog-manager` vit sur le `<body>`). Le retirer
 *    par excès de zèle enlèverait TOUTE l'écriture métier en ambulatoire — c'est-à-dire
 *    la raison d'être du mode Ket. Ce test compte donc les outils, et vérifie que
 *    l'écart entre les deux modes se limite exactement à ces trois-là.
 *  - LA CLÉ DE CACHE. `TrousseCatalogue` mémorise sa liste d'outils. Le worker VIT et
 *    enchaîne les tâches dans le même processus : sans le terminal dans la clé, la
 *    seconde question d'un invité recevrait la liste calculée pour la première. Un
 *    défaut intermittent, dépendant de l'ordre d'arrivée — introuvable en pratique.
 */
class OutilsSelonTerminalTest extends KernelTestCase
{
    /** Les trois outils qui n'ont de sens que devant une interface à colonnes. */
    private const OUTILS_DECRAN = ['ouvrir_rubrique', 'fermer_rubrique', 'visualiser_fiche'];

    private function scope(Terminal $terminal): AiScope
    {
        // Entités NUES : ce test ne touche pas la base. `estDisponible()` ne lit que
        // le terminal, et `TrousseCatalogue` n'utilise l'invité que pour sa clé de
        // cache — un identifiant `null` y vaut 0, ce qui suffit.
        return new AiScope(new Entreprise(), new Invite(), null, $terminal);
    }

    /**
     * @return list<string> les noms d'outils déclarés pour ce terminal, dans la
     *                      trousse la plus large (celle qui porte l'écriture)
     */
    private function outilsPour(Terminal $terminal): array
    {
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);

        return $catalogue->nomsDe(Trousse::ECRITURE, $this->scope($terminal));
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * L'ÉCART ENTRE LES DEUX MODES EST EXACTEMENT DE TROIS OUTILS.
     */
    public function testLeModeKetRetireExactementLesTroisOutilsDEcran(): void
    {
        $surOrdinateur = $this->outilsPour(Terminal::ORDINATEUR);
        $surTelephone = $this->outilsPour(Terminal::MOBILE);

        $retires = array_values(array_diff($surOrdinateur, $surTelephone));
        sort($retires);
        $attendus = self::OUTILS_DECRAN;
        sort($attendus);

        self::assertSame($attendus, $retires, sprintf(
            "Le mode Ket doit retirer les trois outils d'écran, et EUX SEULS.\nRetirés : %s",
            implode(', ', $retires) ?: '(aucun)',
        ));

        // Rien ne doit APPARAÎTRE sur téléphone : le mode Ket retire, il n'ajoute pas.
        self::assertSame(
            [],
            array_values(array_diff($surTelephone, $surOrdinateur)),
            "Aucun outil ne doit être réservé au téléphone : le mode Ket retire, il n'ajoute rien.",
        );
    }

    /**
     * L'ÉCRITURE MÉTIER RESTE ENTIÈRE EN AMBULATOIRE.
     *
     * C'est la vérification qui compte le plus : le mode Ket n'a d'intérêt que si le
     * courtier peut encore faire créer et corriger ses enregistrements depuis son
     * téléphone. Ces outils-là ne dépendent d'aucune colonne.
     *
     * ⚠ La liste ne retient que des outils SANS condition de périmètre : ce test
     * travaille sur des entités nues, et un outil conditionné à un droit (par
     * exemple `preparer_envoi_soa`, qui exige la lecture des clients) serait absent
     * ici pour une raison qui n'a rien à voir avec l'appareil. Le fait qu'aucun
     * autre outil ne dépende du terminal est vérifié séparément, sur les objets
     * eux-mêmes, par testAucunAutreOutilNeDependDuTerminal().
     */
    public function testLesOutilsDEcritureEtDeSaisieRestentDisponiblesSurTelephone(): void
    {
        $surTelephone = $this->outilsPour(Terminal::MOBILE);

        foreach ([
            'ouvrir_dialogue',      // le formulaire standard, via dialog-manager (sur le <body>)
            'preparer_operations',  // le plan d'écriture, validé par l'utilisateur
            'parcours_saisie',      // le parcours guidé multi-entités
            'exporter_etat',        // une ouverture d'URL : indépendante de la mise en page
        ] as $outil) {
            self::assertContains($outil, $surTelephone, sprintf(
                "« %s » ne dépend d'aucune colonne et doit rester disponible sur téléphone : "
                . "sans lui, le mode Ket ne permettrait plus de travailler, seulement de lire.",
                $outil,
            ));
        }
    }

    /**
     * UNE TABLETTE EST TRAITÉE COMME UN TÉLÉPHONE.
     */
    public function testLaTabletteRecoitLeMemeCatalogueQueLeTelephone(): void
    {
        self::assertSame(
            $this->outilsPour(Terminal::MOBILE),
            $this->outilsPour(Terminal::TABLETTE),
        );
    }

    /**
     * LE CACHE DE LA TROUSSE DISTINGUE LES TERMINAUX.
     *
     * Appelé dans cet ordre depuis le MÊME conteneur — donc le même service, avec son
     * cache déjà chaud —, le catalogue doit encore répondre juste. C'est la situation
     * exacte du worker Messenger, qui enchaîne les tâches de plusieurs appareils dans
     * un seul processus.
     */
    public function testLeCacheNeMelangePasLesTerminaux(): void
    {
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);

        $telephoneDAbord = $catalogue->nomsDe(Trousse::ECRITURE, $this->scope(Terminal::MOBILE));
        $puisOrdinateur = $catalogue->nomsDe(Trousse::ECRITURE, $this->scope(Terminal::ORDINATEUR));
        $etDeNouveauTelephone = $catalogue->nomsDe(Trousse::ECRITURE, $this->scope(Terminal::MOBILE));

        self::assertSame($telephoneDAbord, $etDeNouveauTelephone);
        foreach (self::OUTILS_DECRAN as $outil) {
            self::assertNotContains($outil, $telephoneDAbord);
            self::assertContains(
                $outil,
                $puisOrdinateur,
                "Le cache a servi la liste du téléphone à un ordinateur : le terminal manque à la clé.",
            );
        }
    }

    /**
     * LES TROIS OUTILS D'ÉCRAN, ET EUX SEULS, PORTENT LA CONDITION DE TERMINAL.
     *
     * Vérifié sur les objets eux-mêmes et non sur le catalogue : un outil qui
     * répondrait `false` sur ordinateur serait invisible partout, et le test
     * précédent — qui compare deux modes — ne le verrait pas.
     */
    public function testAucunAutreOutilNeDependDuTerminal(): void
    {
        $ordinateur = $this->scope(Terminal::ORDINATEUR);
        $mobile = $this->scope(Terminal::MOBILE);

        // `tous()` expose le catalogue COMPLET, sans filtre de trousse ni de
        // périmètre : c'est le seul point de vue qui permette d'interroger un outil
        // que la trousse aurait déjà écarté pour une autre raison.
        $outils = static::getContainer()->get(TrousseCatalogue::class)->tous();
        self::assertNotEmpty($outils, "Catalogue d'outils vide : le test ne prouverait rien.");

        foreach ($outils as $outil) {
            if (!$outil instanceof AiToolConditionnel) {
                continue;
            }

            $sensibleAuTerminal = $outil->estDisponible($ordinateur) !== $outil->estDisponible($mobile);
            $devraitLEtre = in_array($outil->name(), self::OUTILS_DECRAN, true);

            self::assertSame($devraitLEtre, $sensibleAuTerminal, sprintf(
                "« %s » %s selon le terminal. Seuls les outils qui font BOUGER une colonne "
                . "doivent en dépendre (cf. ExigeLesColonnes) — les autres conditions "
                . "(droits, état du fil) ne regardent pas l'appareil.",
                $outil->name(),
                $sensibleAuTerminal ? 'varie' : 'ne varie pas',
            ));
        }
    }
}
