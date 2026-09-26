<?php

namespace App\Tests\Ai\Corpus;

use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE CORPUS EST-IL ENCORE UTILISABLE ? — et rien d'autre.
 *
 * ── CE QUE CE TEST N'ASSERTE PAS, DÉLIBÉRÉMENT ───────────────────────────────────
 *
 * Il ne vérifie PAS que Ket choisit l'outil attendu. L'écart entre l'attendu et
 * l'obtenu EST l'indicateur du chantier : le verrouiller en test rendrait vert ce
 * qu'on cherche à faire monter, et le premier lot qui dégraderait le choix d'outil
 * passerait pour une régression de test plutôt que pour ce qu'il est. La mesure
 * s'imprime (`app:ket:justesse`), elle ne se fige pas.
 *
 * ── CE QU'IL ASSERTE ─────────────────────────────────────────────────────────────
 *
 *  1. ANONYMAT — aucun nom réel relevé dans les 826 questions d'origine ne réapparaît,
 *     et aucun libellé d'entité de la base non plus. C'est la seule assertion dont
 *     l'échec est grave : un corpus versionné qui nomme un client publie le
 *     portefeuille d'un courtier.
 *  2. EXISTENCE — chaque outil attendu existe au catalogue. Sans quoi le corpus
 *     mesurerait contre une cible qui n'est plus là, et le premier renommage du lot 4
 *     rendrait tout le corpus faux en silence.
 *  3. FRONTIÈRE — un cas dont la trousse attendue est la lecture n'attend aucun outil
 *     d'écriture. C'est la propriété que ni un renommage ni une fusion ne doivent
 *     franchir.
 *  4. UNICITÉ des libellés — ils servent de clé aux comparaisons avant/après.
 */
class CorpusFigeTest extends KernelTestCase
{
    public function testChaqueLibelleEstUnique(): void
    {
        $libelles = array_map(static fn (CasDuCorpus $c): string => $c->libelle, CorpusDeReference::tous());

        self::assertSame(
            \count($libelles),
            \count(array_unique($libelles)),
            'Deux cas partagent un libellé : les rapports avant/après ne se compareraient plus ligne à ligne. '
            . 'Doublons : ' . implode(', ', array_keys(array_filter(array_count_values($libelles), static fn (int $n) => $n > 1))),
        );
    }

    /**
     * ⚠ L'ASSERTION QUI COMPTE. Les noms sont ceux réellement relevés dans les
     * questions d'origine : clients, assureurs, intermédiaires, personnes, références
     * de police, numéros de téléphone.
     *
     * Ce n'est PAS le mécanisme d'anonymisation — celui-là est manuel, cas par cas.
     * C'est le filet qui rattrape une reprise malheureuse, par exemple un cas recopié
     * d'un journal sans être relu.
     */
    public function testAucunNomReelNeSurvitDansLeCorpus(): void
    {
        foreach (CorpusDeReference::tous() as $cas) {
            $texte = mb_strtolower($cas->question . ' ' . $cas->note);
            foreach (CorpusDeReference::INTERDITS as $interdit) {
                self::assertStringNotContainsString(
                    mb_strtolower($interdit),
                    $texte,
                    sprintf('Le cas « %s » porte encore une donnée réelle : « %s ».', $cas->libelle, $interdit),
                );
            }
        }
    }

    /**
     * LE SECOND FILET : aucun libellé de client, d'assureur ou de partenaire présent
     * en base ne doit apparaître dans le corpus.
     *
     * ⚠ SA PORTÉE EST LIMITÉE, ET IL FAUT LE SAVOIR. Ce test tourne dans
     * l'environnement de test, donc contre `bdm_test` — pas contre le portefeuille
     * réel du courtier. Il n'attrapera un nom que si ce nom existe aussi dans les
     * jeux de test. LE FILET DUR RESTE LA LISTE `INTERDITS` du test précédent, et
     * avant elle l'anonymisation manuelle, cas par cas.
     *
     * Il garde tout de même son utilité : il attrape ce qu'une reprise depuis une
     * fixture ferait entrer sans qu'on y pense, et il coûte une requête.
     */
    public function testAucunLibelleDeLaBaseNApparaitDansLeCorpus(): void
    {
        self::bootKernel();
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        $noms = [];
        foreach ([['client', 'nom'], ['assureur', 'nom'], ['partenaire', 'nom']] as [$table, $colonne]) {
            try {
                $noms = array_merge($noms, $connexion->fetchFirstColumn(
                    sprintf('SELECT DISTINCT %s FROM %s WHERE %s IS NOT NULL', $colonne, $table, $colonne),
                ));
            } catch (\Throwable) {
                // Table absente de ce schéma de test : le filet précédent reste en place.
                continue;
            }
        }

        // ⚠ LES NOMS COURTS SONT ÉCARTÉS, et il le faut. Un client nommé « SA » ou
        // « Val » ferait échouer le test sur des mots français ordinaires, et on
        // finirait par désactiver le garde-fou entier pour un faux positif.
        $noms = array_filter($noms, static fn (string $n): bool => mb_strlen(trim($n)) >= 5);

        $corpus = mb_strtolower(implode(' ', array_map(
            static fn (CasDuCorpus $c): string => $c->question . ' ' . $c->note,
            CorpusDeReference::tous(),
        )));

        $trouves = [];
        foreach ($noms as $nom) {
            if (str_contains($corpus, mb_strtolower(trim($nom)))) {
                $trouves[] = $nom;
            }
        }

        self::assertSame([], $trouves, 'Des libellés de la base apparaissent dans le corpus : ' . implode(', ', $trouves));
    }

    public function testChaqueOutilAttenduExisteAuCatalogue(): void
    {
        self::bootKernel();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);
        $noms = array_map(static fn ($o): string => $o->name(), $catalogue->tous());

        foreach (CorpusDeReference::tous() as $cas) {
            foreach ($cas->outils as $outil) {
                self::assertContains(
                    $outil,
                    $noms,
                    sprintf(
                        'Le cas « %s » attend « %s », qui n\'existe plus au catalogue. '
                        . 'Un renommage a été livré sans mettre le corpus à jour : la mesure porterait sur une cible absente.',
                        $cas->libelle,
                        $outil,
                    ),
                );
            }
        }
    }

    /**
     * LA FRONTIÈRE, ÉPROUVÉE PAR LE CORPUS LUI-MÊME.
     *
     * Un cas attendu en lecture ne peut pas attendre un outil d'écriture : ce serait
     * demander à la mesure de valider un franchissement que le code interdit.
     */
    public function testUnCasDeLectureNAttendAucunOutilDEcriture(): void
    {
        self::bootKernel();
        $catalogue = static::getContainer()->get(TrousseCatalogue::class);

        foreach (CorpusDeReference::tous() as $cas) {
            if ($cas->trousse !== Trousse::LECTURE) {
                continue;
            }
            foreach ($cas->outils as $outil) {
                self::assertFalse(
                    $catalogue->estOutilDEcriture($outil),
                    sprintf(
                        'Le cas « %s » est attendu en LECTURE mais réclame « %s », un outil d\'écriture. '
                        . 'La frontière ne se franchit pas, pas même dans un jeu de mesure.',
                        $cas->libelle,
                        $outil,
                    ),
                );
            }
        }
    }

    public function testChaqueCasPorteUneFamilleEtUnContexteConnus(): void
    {
        foreach (CorpusDeReference::tous() as $cas) {
            self::assertContains($cas->famille, CasDuCorpus::FAMILLES, sprintf('Famille inconnue sur « %s ».', $cas->libelle));
            self::assertContains($cas->contexte, CasDuCorpus::CONTEXTES, sprintf('Contexte inconnu sur « %s ».', $cas->libelle));
        }
    }

    /**
     * LA COUVERTURE MINIMALE EXIGÉE PAR LE CHANTIER : lecture simple, lecture
     * multi-outils, écriture, conversation pure sans outil, cas ambigus.
     *
     * Le seuil est bas exprès. Ce test n'a pas à juger la qualité du corpus, seulement
     * à empêcher qu'une famille entière disparaisse sans qu'on s'en aperçoive — auquel
     * cas la mesure « bon outil au premier tour » deviendrait la moyenne d'autre chose.
     */
    public function testLaCouvertureMinimaleEstTenue(): void
    {
        $parFamille = [];
        $multiOutils = 0;
        foreach (CorpusDeReference::tous() as $cas) {
            $parFamille[$cas->famille] = ($parFamille[$cas->famille] ?? 0) + 1;
            if (\count($cas->outils) > 1) {
                ++$multiOutils;
            }
        }

        foreach (CasDuCorpus::FAMILLES as $famille) {
            self::assertGreaterThanOrEqual(
                3,
                $parFamille[$famille] ?? 0,
                sprintf('La famille « %s » n\'a plus assez de cas pour que sa moyenne veuille dire quelque chose.', $famille),
            );
        }

        self::assertGreaterThanOrEqual(1, $multiOutils, 'Aucun cas de lecture multi-outils : une forme réelle disparaîtrait de la mesure.');
        self::assertGreaterThanOrEqual(100, \count(CorpusDeReference::tous()), 'Le corpus est descendu sous cent cas.');
    }
}
