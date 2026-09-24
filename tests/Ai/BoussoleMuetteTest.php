<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\Boussole\BoussoleService;
use PHPUnit\Framework\TestCase;

/**
 * QUAND LA BOUSSOLE N'A RIEN À DIRE, KET SE TAIT.
 *
 * ── CE QUI ÉTAIT SERVI ──────────────────────────────────────────────────────
 * Le prompt imposait un rappel de fin de réponse à CHAQUE réponse substantielle.
 * Quand aucun axe ne réclamait d'action, il restait donc une consigne : « encourage
 * simplement à saturer davantage ». Ket terminait ainsi chaque message par une
 * formule sans contenu.
 *
 * Répétée à chaque échange, cette phrase devient du bruit : l'utilisateur apprend à
 * sauter la dernière ligne — et il la sautera le jour où elle portera une vraie
 * urgence. Un rappel vide ne coûte pas seulement une ligne, il dévalue tous les
 * suivants.
 *
 * ── ET ON N'ANNONCE PAS UN ZÉRO ─────────────────────────────────────────────
 * « 30 renouvellement(s) à anticiper (30 échu(s), 0 sous 30 j) » fait lire un chiffre
 * pour apprendre qu'il n'y a rien à cet endroit : la parenthèse ne détaille plus,
 * elle répète le total et ajoute un vide.
 *
 * Aucune base de données ici : on éprouve la RÉDACTION de la section, à partir d'un
 * état de boussole fabriqué — c'est exactement ce que le modèle reçoit.
 */
final class BoussoleMuetteTest extends TestCase
{
    /** Rend la section de boussole telle que le prompt la porte. */
    private function section(array $boussole): string
    {
        $methode = new \ReflectionMethod(AiContextBuilder::class, 'sectionBoussole');
        $methode->setAccessible(true);

        return (string) $methode->invoke(
            (new \ReflectionClass(AiContextBuilder::class))->newInstanceWithoutConstructor(),
            $boussole,
            false,
        );
    }

    /** @return array<string, mixed> un état où tout est au vert */
    private function toutAuVert(): array
    {
        return [
            'items' => [
                ['axe' => 'renouvellements', 'libelle' => 'Aucun renouvellement imminent', 'actionnable' => false],
                ['axe' => 'saturation', 'libelle' => 'Portefeuille saturé', 'actionnable' => false],
            ],
            'prioritaire' => null,
        ];
    }

    public function testSansPrioriteLaSectionInterditToutRappel(): void
    {
        $section = $this->section($this->toutAuVert());

        self::assertStringContainsString('RIEN À SIGNALER', $section);
        self::assertStringContainsString('AUCUN rappel de fin de réponse', $section);
    }

    /**
     * ⚠ LE POINT EXACT DE LA CORRECTION. L'ancienne consigne demandait un
     * encouragement générique faute de priorité : c'est elle qu'il ne faut pas voir
     * revenir.
     */
    public function testSansPrioriteAucunEncouragementGeneriqueNEstDemande(): void
    {
        $section = $this->section($this->toutAuVert());

        self::assertStringNotContainsString(
            'encourage simplement',
            $section,
            'Une consigne d’encouragement générique fait produire une coquille vide à chaque message.'
        );
    }

    /** Avec une priorité réelle, le rappel reste demandé — on n'a rien cassé. */
    public function testAvecUnePrioriteLeRappelResteDemande(): void
    {
        $section = $this->section([
            'items' => [['axe' => 'fiscal', 'libelle' => 'TVA à reverser', 'actionnable' => true]],
            'prioritaire' => ['libelle' => 'TVA à reverser'],
        ]);

        self::assertStringContainsString('PRIORITÉ ACTUELLE', $section);
        self::assertStringContainsString('TVA à reverser', $section);
        self::assertStringNotContainsString('RIEN À SIGNALER', $section);
    }

    /**
     * LE LIBELLÉ DES RENOUVELLEMENTS NE CITE QUE CE QUI EXISTE.
     *
     * On éprouve le format lui-même plutôt que le service, qui exigerait une douzaine
     * de doublures : ce qui est en cause ici est la PHRASE, et elle est vérifiable
     * telle quelle.
     */
    public function testLeLibelleDesRenouvellementsNAnnoncePasDeZero(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/Ai/Boussole/BoussoleService.php'
        );

        // La ventilation complète n'est plus le cas par défaut : elle est désormais
        // gardée par la présence des DEUX comptes.
        self::assertStringContainsString(
            '$echus > 0 && $imminents > 0',
            $source,
            'La ventilation « (x échus, y sous 30 j) » doit être réservée au cas où les deux existent.'
        );
        self::assertStringContainsString('renouvellement(s) échu(s) à traiter', $source);
        self::assertStringContainsString('à échéance sous 30 jours', $source);
    }

    /** Le barème d'urgence reste la source unique, partagé avec le programme du jour. */
    public function testLeBaremeResteUnique(): void
    {
        self::assertArrayHasKey('renouvellements', BoussoleService::URGENCE);
    }
}
