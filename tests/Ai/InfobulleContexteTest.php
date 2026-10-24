<?php

namespace App\Tests\Ai;

use App\Service\Infobulle\InfobulleContexte;
use PHPUnit\Framework\TestCase;

/**
 * L'INFOBULLE D'UNE PUCE DE CONTEXTE DU CHAT DE KET, déclarée par le serveur (chantier 3, 3c).
 *
 * Elle montre la fiche EXACTE capturée par l'assistant (même source que le prompt). Le navigateur
 * la mettait en forme ; c'est désormais le serveur, avec les mêmes règles : booléens en clair,
 * structures résumées, textes bornés à 120 caractères, et au plus 20 lignes — le reste annoncé,
 * jamais rogné en silence (la fiche COMPLÈTE part à l'assistant).
 */
class InfobulleContexteTest extends TestCase
{
    public function testUneFicheDevientDesLignesLisibles(): void
    {
        $sections = InfobulleContexte::sections('Client', 'Alpha SARL', [
            'nom' => 'Alpha SARL',
            'exonere' => false,
            'actif' => true,
            'polices' => [1, 2, 3],
            'portefeuille' => ['nom' => 'Kinshasa'],
            'gestionnaire' => ['id' => 7],
            'vide' => '',
            'absent' => null,
            'note' => str_repeat('é', 130),
            'prime' => 1200.5,
        ]);

        self::assertSame(['titre' => 'Client', 'lignes' => [['Nom', 'Alpha SARL']]], $sections[0]);
        self::assertSame('Fiche capturée par l’assistant', $sections[1]['titre']);
        $lignes = array_column($sections[1]['lignes'], 1, 0);
        self::assertSame('Non', $lignes['exonere']);
        self::assertSame('Oui', $lignes['actif']);
        self::assertSame('3 élément(s)', $lignes['polices']);
        self::assertSame('Kinshasa', $lignes['portefeuille']);
        self::assertSame('7', $lignes['gestionnaire']);
        self::assertSame('—', $lignes['vide']);
        self::assertSame('—', $lignes['absent']);
        self::assertSame(str_repeat('é', 120) . '…', $lignes['note'], 'Borné à 120 CARACTÈRES (pas octets).');
        self::assertSame('1200.5', $lignes['prime']);
    }

    /**
     * Un indicateur calculé peut être un objet (date, énumération, entité) ou une structure
     * imbriquée : la bulle le lit, ou le dit « — » — jamais une erreur qui ferait tomber le chat.
     */
    public function testUnObjetOuUneStructureNeFaitPasTomberLeChat(): void
    {
        $sections = InfobulleContexte::sections('Avenant', 'POL-1', [
            'echeance' => new \DateTimeImmutable('2026-03-05 14:30'),
            'statut' => EtatDeTest::Valide,
            'unite' => UniteDeTest::Mois,
            'lisible' => new class () implements \Stringable {
                public function __toString(): string
                {
                    return 'Texte de l’objet';
                }
            },
            'opaque' => new \stdClass(),
            'nomImbrique' => ['nom' => ['a' => 1]],
            'nomLong' => ['libelle' => str_repeat('ü', 130)],
            'zero' => 0,
        ]);
        $lignes = array_column($sections[1]['lignes'], 1, 0);

        self::assertSame('05/03/2026 14:30', $lignes['echeance']);
        self::assertSame('valide', $lignes['statut']);
        self::assertSame('Mois', $lignes['unite']);
        self::assertSame('Texte de l’objet', $lignes['lisible']);
        self::assertSame('—', $lignes['opaque']);
        self::assertSame('—', $lignes['nomImbrique']);
        self::assertSame(str_repeat('ü', 120) . '…', $lignes['nomLong'], 'Le nom imbriqué est borné, lui aussi.');
        self::assertSame('0', $lignes['zero']);
    }

    public function testAuDelaDeVingtLignesLeResteEstAnnonce(): void
    {
        $fiche = [];
        for ($i = 1; $i <= 23; ++$i) {
            $fiche["indicateur $i"] = $i;
        }
        $sections = InfobulleContexte::sections('Avenant', 'POL-1', $fiche);

        self::assertCount(20, $sections[1]['lignes']);
        self::assertSame('… et 3 autres indicateurs : la fiche COMPLÈTE est transmise à l’assistant.', $sections[1]['texte']);
        self::assertSame('tip-libelle', $sections[1]['classe']);

        $un = InfobulleContexte::sections('Avenant', 'POL-1', $fiche + ['indicateur 24' => 24])[1]['texte'];
        self::assertStringContainsString('4 autres indicateurs', $un);
        $fiche = array_slice($fiche, 0, 21, true);
        self::assertSame('… et 1 autre indicateur : la fiche COMPLÈTE est transmise à l’assistant.', InfobulleContexte::sections('Avenant', 'POL-1', $fiche)[1]['texte']);
    }

    public function testSansFicheLObjetEstDitIndisponible(): void
    {
        $sections = InfobulleContexte::sections('Piste', 'Incendie', null);

        self::assertCount(2, $sections);
        self::assertSame('Détails indisponibles (objet supprimé ou hors de votre périmètre).', $sections[1]['texte']);
        self::assertSame('tip-libelle', $sections[1]['classe']);
    }

    public function testUnLibelleVideReste(): void
    {
        self::assertSame([['Nom', '—']], InfobulleContexte::sections('', '', [])[0]['lignes']);
        self::assertSame('Objet', InfobulleContexte::sections('', '', [])[0]['titre']);
    }
}

enum EtatDeTest: string
{
    case Valide = 'valide';
}

enum UniteDeTest
{
    case Mois;
}
