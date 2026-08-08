<?php

namespace App\Tests\Ai\Debit;

use App\Ai\Debit\BudgetDebit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Compteur de débit du fournisseur d'IA. Classe à horloge injectée : aucun test
 * ne dort, et la fenêtre glissante se vérifie à la seconde près.
 *
 * L'enjeu de ces tests n'est pas cosmétique. C'est ce compteur qui a remplacé un
 * plafond PAR MESSAGE, lequel refusait 10 messages sur 23 alors que le quota du
 * fournisseur — qui se compte PAR MINUTE et se partage entre tous les invités —
 * était largement disponible.
 */
class BudgetDebitTest extends TestCase
{
    private int $instant = 1_000_000;

    private function budget(int $plafond = 250000, float $marge = 0.0): BudgetDebit
    {
        return new BudgetDebit(
            new ArrayAdapter(),
            $plafond,
            $marge,
            function (): int { return $this->instant; },
        );
    }

    public function testUneFenetreVideOffreLePlafondEntier(): void
    {
        $this->assertSame(250000, $this->budget()->restant('gemini-flash-latest'));
    }

    public function testLaMargeEstDeduiteDuPlafond(): void
    {
        // 15 % de réserve : la séquence lire-modifier-écrire n'étant pas atomique,
        // deux requêtes simultanées peuvent sous-compter. Mieux vaut renoncer à
        // quelques tokens qu'encaisser un 429, déjà facturé à l'utilisateur.
        $this->assertSame(212500, $this->budget(250000, 0.15)->plafondUtile());
    }

    public function testLesToursConsommesSeCumulentSurLaMinute(): void
    {
        $budget = $this->budget();
        $budget->enregistrer('gemini-flash-latest', 40000);
        $budget->enregistrer('gemini-flash-latest', 60000);

        $this->assertSame(150000, $budget->restant('gemini-flash-latest'));
    }

    public function testUnTourSortDeLaFenetreApresUneMinute(): void
    {
        $budget = $this->budget();
        $budget->enregistrer('gemini-flash-latest', 200000);
        $this->assertSame(50000, $budget->restant('gemini-flash-latest'));

        $this->instant += 61;

        $this->assertSame(
            250000,
            $budget->restant('gemini-flash-latest'),
            'Passé 60 s, un tour ne pèse plus sur le quota : c\'est tout le sens d\'une fenêtre glissante.',
        );
    }

    /**
     * Chez Gemini, « flash » et « flash-lite » ont des compteurs SÉPARÉS au même
     * plafond. Les additionner ferait refuser des tours parfaitement autorisés.
     */
    public function testChaqueModeleACompteurSepare(): void
    {
        $budget = $this->budget();
        $budget->enregistrer('gemini-flash-latest', 200000);

        $this->assertSame(50000, $budget->restant('gemini-flash-latest'));
        $this->assertSame(250000, $budget->restant('gemini-flash-lite-latest'));
    }

    public function testAucuneAttenteQuandLaPlaceEstDejaDisponible(): void
    {
        $budget = $this->budget();
        $budget->enregistrer('gemini-flash-latest', 100000);

        $this->assertSame(0, $budget->secondesAvantLiberation('gemini-flash-latest', 50000));
    }

    /**
     * Le cœur du correctif : quand il manque de la place, dire dans COMBIEN de
     * secondes elle revient — c'est-à-dire quand le tour le plus ancien sortira
     * de la fenêtre. Sans ce délai, le moteur ne pourrait que refuser.
     */
    public function testLAttenteCorrespondALaSortieDuTourLePlusAncien(): void
    {
        $budget = $this->budget();
        $budget->enregistrer('gemini-flash-latest', 150000); // à T
        $this->instant += 50;
        $budget->enregistrer('gemini-flash-latest', 50000);  // à T+50

        // Il faut 100 000 : il manque la place du premier tour, qui sort de la
        // fenêtre 60 s après avoir été enregistré, soit dans 10 s (+1 seconde de
        // sûreté, la fenêtre étant strictement glissante).
        $this->assertSame(11, $budget->secondesAvantLiberation('gemini-flash-latest', 100000));
    }

    /**
     * Demander plus que le plafond entier ne se résout par aucune attente : même
     * une fenêtre vide n'y suffirait pas. Le moteur doit alors conclure et dire
     * la vérité (fil trop lourd), pas faire patienter pour rien.
     */
    public function testUneDemandeSuperieureAuPlafondNeSeraJamaisSatisfaite(): void
    {
        $this->assertNull($this->budget()->secondesAvantLiberation('gemini-flash-latest', 300000));
    }

    /** Le compteur est partagé : deux instances sur le même pool voient le même débit. */
    public function testLeCompteurEstPartageEntreProcessus(): void
    {
        $pool = new ArrayAdapter();
        $horloge = function (): int { return $this->instant; };

        $premier = new BudgetDebit($pool, 250000, 0.0, $horloge);
        $second = new BudgetDebit($pool, 250000, 0.0, $horloge);

        $premier->enregistrer('gemini-flash-latest', 200000);

        $this->assertSame(
            50000,
            $second->restant('gemini-flash-latest'),
            'Le quota du fournisseur est partagé par tous les invités : le compteur doit l\'être aussi.',
        );
    }
}
