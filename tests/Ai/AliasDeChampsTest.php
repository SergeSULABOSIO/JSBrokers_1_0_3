<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\AliasDeChamps;
use PHPUnit\Framework\TestCase;

/**
 * UN CHAMP DICTÉ QUI N'EXISTE PAS — tests purs.
 *
 * L'INCIDENT (2026-08-13, sur la tâche la plus simple : créer un risque). Ket a
 * dicté « nom : Assurance Voyage ». Or `Risque` est la SEULE entité du périmètre à
 * appeler ce champ `nomComplet` : le champ inconnu a été jeté sans un mot,
 * `nomComplet` est revenu « obligatoire », et Ket a redemandé un nom donné trois
 * fois. Le plan, lui, AFFICHAIT « Nom : Assurance Voyage » — un champ qui n'allait
 * pas être écrit.
 */
class AliasDeChampsTest extends TestCase
{
    /** Les champs réels d'un Risque : ni « nom », ni « libelle ». */
    private const RISQUE = ['code', 'nomComplet', 'description', 'branche', 'imposable', 'pourcentageCommissionSpecifiqueHT'];

    public function testUnChampInconnuDesignantUnSeulChampReelEstRattache(): void
    {
        $issue = (new AliasDeChamps())->normaliser(
            ['code' => 'VOY', 'nom' => 'Assurance Voyage'],
            self::RISQUE,
        );

        $this->assertSame(['code' => 'VOY', 'nomComplet' => 'Assurance Voyage'], $issue['champs']);
        $this->assertSame(['« nom » lu comme « nomComplet ».'], $issue['alias']);
        $this->assertSame([], $issue['ignores']);
    }

    /**
     * ON NE TRANCHE PAS UNE AMBIGUÏTÉ. « date » face à `dateDebut` ET `dateFin` ne
     * désigne rien : deviner écrirait une donnée fausse dans le dossier d'un client.
     */
    public function testUnChampAmbiguNestJamaisRattache(): void
    {
        $issue = (new AliasDeChamps())->normaliser(
            ['date' => '2026-09-14'],
            ['dateDebut', 'dateFin', 'nom'],
        );

        $this->assertSame([], $issue['champs']);
        $this->assertSame([], $issue['alias']);
        $this->assertSame(['date'], $issue['ignores']);
    }

    /**
     * CE QU'ON NE SAIT PAS RATTACHER EST RETIRÉ, ET DIT. Le laisser dans les champs
     * le ferait figurer au tableau du plan comme s'il allait être écrit — c'est
     * précisément le mensonge qu'on corrige.
     */
    public function testUnChampSansEquivalentEstRetireEtSignale(): void
    {
        $issue = (new AliasDeChamps())->normaliser(
            ['code' => 'VOY', 'couleurPreferee' => 'bleu'],
            self::RISQUE,
        );

        $this->assertArrayNotHasKey('couleurPreferee', $issue['champs']);
        $this->assertSame(['couleurPreferee'], $issue['ignores']);
    }

    /** Un champ réel n'est jamais touché, même s'il préfixe un autre. */
    public function testUnChampReelPasseIntact(): void
    {
        $issue = (new AliasDeChamps())->normaliser(
            ['nom' => 'Mme Marlette'],
            ['nom', 'nomComplet'],
        );

        $this->assertSame(['nom' => 'Mme Marlette'], $issue['champs']);
        $this->assertSame([], $issue['alias']);
    }

    /** Le champ RÉEL l'emporte : un alias ne renverse jamais une valeur bien nommée. */
    public function testLAliasNecraseJamaisLeChampBienNomme(): void
    {
        $issue = (new AliasDeChamps())->normaliser(
            ['nom' => 'À jeter', 'nomComplet' => 'Assurance Voyage'],
            self::RISQUE,
        );

        $this->assertSame('Assurance Voyage', $issue['champs']['nomComplet']);
        $this->assertSame(['nom'], $issue['ignores']);
    }

    /** Un nom trop court ne désigne rien : « id » s'accrocherait à n'importe quoi. */
    public function testUnNomTropCourtNeSeRattacheAAucunChamp(): void
    {
        $issue = (new AliasDeChamps())->normaliser(['id' => 4], ['idnat', 'nom']);

        $this->assertSame([], $issue['champs']);
        $this->assertSame(['id'], $issue['ignores']);
    }

    /**
     * SANS INVENTAIRE, ON NE TOUCHE À RIEN. Mieux vaut le comportement d'avant qu'un
     * rattrapage à l'aveugle sur une liste vide.
     */
    public function testSansChampsConnusRienNestModifie(): void
    {
        $champs = ['nom' => 'X', 'inconnu' => 'Y'];
        $issue = (new AliasDeChamps())->normaliser($champs, []);

        $this->assertSame($champs, $issue['champs']);
        $this->assertSame([], $issue['ignores']);
    }
}
