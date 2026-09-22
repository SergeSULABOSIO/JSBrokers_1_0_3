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

    /**
     * Compteur avec des plafonds propres à certains préfixes de clé — ce qui
     * permet à deux fournisseurs qui ne comptent pas la même chose de partager la
     * même fenêtre glissante.
     */
    private function budgetMultiFournisseur(): BudgetDebit
    {
        return new BudgetDebit(
            new ArrayAdapter(),
            250000, // Gemini, palier gratuit
            0.0,
            function (): int { return $this->instant; },
            ['anthropic:in:' => 2000000, 'anthropic:out:' => 400000],
        );
    }

    public function testUneFenetreVideOffreLePlafondEntier(): void
    {
        $this->assertSame(250000, $this->budget()->restant('gemini-flash-latest'));
    }

    /**
     * LE PLAFOND SUIT LE FOURNISSEUR, PAS LE CODE. Anthropic n'inclut pas les
     * tokens lus en cache dans son décompte : son plafond d'entrée est huit fois
     * celui du palier gratuit de Google. Lui opposer celui de Google ferait
     * patienter Ket devant une porte grande ouverte.
     */
    public function testUnPrefixeConnuUtiliseSonProprePlafond(): void
    {
        $budget = $this->budgetMultiFournisseur();

        $this->assertSame(2000000, $budget->restant('anthropic:in:claude-haiku-4-5'));
        $this->assertSame(400000, $budget->restant('anthropic:out:claude-haiku-4-5'));
    }

    public function testUnPrefixeInconnuRetombeSurLePlafondParDefaut(): void
    {
        // Tous les modèles Gemini : aucun préfixe, donc rien ne change pour eux.
        $this->assertSame(250000, $this->budgetMultiFournisseur()->restant('gemini-3.1-flash-lite'));
    }

    /**
     * Entrée et sortie sont DEUX fenêtres : consommer l'une ne doit pas entamer
     * l'autre, sans quoi une réponse longue ferait croire l'entrée saturée.
     */
    public function testEntreeEtSortieSontDeuxFenetresIndependantes(): void
    {
        $budget = $this->budgetMultiFournisseur();
        $budget->enregistrer('anthropic:in:claude-haiku-4-5', 1_500_000);

        $this->assertSame(500000, $budget->restant('anthropic:in:claude-haiku-4-5'));
        $this->assertSame(400000, $budget->restant('anthropic:out:claude-haiku-4-5'),
            'La fenêtre de sortie est intacte : elle a son propre plafond et son propre compteur.');
    }

    /**
     * Le plafond par préfixe doit aussi gouverner l'ATTENTE, pas seulement le
     * solde affiché — sinon le moteur patienterait sur un quota qui n'est pas le
     * sien.
     */
    public function testLAttenteSeCalculeSurLePlafondDuPrefixe(): void
    {
        $budget = $this->budgetMultiFournisseur();
        $budget->enregistrer('anthropic:in:claude-haiku-4-5', 300000);

        // 300 000 consommés dépasseraient le plafond de Google, pas celui d'Anthropic.
        $this->assertSame(0, $budget->secondesAvantLiberation('anthropic:in:claude-haiku-4-5', 100000));
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
