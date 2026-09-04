<?php

namespace App\Tests\Echange;

use App\Echange\Service\Progression;
use PHPUnit\Framework\TestCase;

/**
 * LA PROGRESSION EST MESURÉE, JAMAIS SIMULÉE.
 *
 * C'est la seule chose qui distingue une barre utile d'une décoration. Une barre qui
 * avance toute seule promet une échéance qu'elle ne connaît pas ; l'utilisateur y croit
 * une fois, puis cesse de la regarder — et l'application a perdu le seul moyen qu'elle
 * avait de le rassurer.
 *
 * Ces tests tiennent donc trois choses : le pourcentage vient d'un comptage réel,
 * l'estimation de temps se tait tant qu'elle ne sait rien, et le flux ne se noie pas
 * sous ses propres lignes.
 */
class ProgressionTest extends TestCase
{
    /** Le pourcentage est le rapport de ce qui est fait à ce qui était annoncé. */
    public function testLePourcentageEstUnComptageReel(): void
    {
        $progression = new Progression(200);

        self::assertSame(0.0, $progression->pourcentage(), 'Rien de fait, rien d\'affiché.');

        $progression->avancer(50);
        self::assertSame(25.0, $progression->pourcentage());

        $progression->avancer(150);
        self::assertSame(100.0, $progression->pourcentage());
    }

    /**
     * Un total sous-estimé ne doit pas afficher 137 % : mieux vaut rester à cent que
     * d'avouer qu'on avait mal compté.
     */
    public function testLePourcentageNeDepasseJamaisCent(): void
    {
        $progression = new Progression(10);
        $progression->avancer(25);

        self::assertSame(100.0, $progression->pourcentage());
    }

    /** Sans total, aucun pourcentage : on ne divise pas par une inconnue. */
    public function testSansTotalLePourcentageResteAZero(): void
    {
        $progression = new Progression(0);
        $progression->avancer(10);

        self::assertSame(0.0, $progression->pourcentage());
        self::assertNull($progression->secondesRestantes());
    }

    /**
     * ⚠ L'ESTIMATION SE TAIT TANT QU'ELLE NE SAIT RIEN.
     *
     * Annoncer « 3 secondes » sur la foi de la première ligne, puis « 4 minutes » à la
     * deuxième, détruit la confiance plus sûrement que de ne rien annoncer. Null n'est
     * pas un échec : c'est l'aveu honnête qu'à ce stade, tout chiffre serait au hasard.
     */
    public function testLEstimationSeTaitTantQuElleNaPasAssezVu(): void
    {
        $progression = new Progression(1000);

        $progression->avancer(1);
        self::assertNull($progression->secondesRestantes(), 'Une seule unité ne dit rien du débit.');

        $progression->avancer(3);
        self::assertNull($progression->secondesRestantes(), 'Quatre non plus.');

        // Passé le seuil d'échantillonnage, elle se prononce.
        $progression->avancer(10);
        self::assertNotNull($progression->secondesRestantes(), 'Avec assez d\'échantillons, elle doit estimer.');
        self::assertGreaterThan(0.0, $progression->secondesRestantes());
    }

    /** Une opération terminée n'a plus de temps restant à annoncer. */
    public function testUneOperationTermineeNAnnoncePlusDeDelai(): void
    {
        $progression = new Progression(20);
        $progression->avancer(20);

        self::assertSame(100.0, $progression->pourcentage());
        self::assertNull($progression->secondesRestantes());
    }

    /**
     * Le total peut être corrigé en cours de route : un import ne connaît son volume
     * qu'après avoir ouvert ses feuilles.
     */
    public function testLeTotalPeutEtreAjusteEnCoursDeRoute(): void
    {
        $progression = new Progression(0);
        $progression->avancer(5);

        $progression->totaliser(10);
        self::assertSame(50.0, $progression->pourcentage(), 'Le total posé tard vaut mieux qu\'un pourcentage faux.');
    }

    /**
     * ⚠ LE FLUX NE DOIT PAS SE NOYER SOUS SES PROPRES LIGNES.
     *
     * Sans pas minimal, un import de deux mille lignes produirait deux mille lignes de
     * progression : le navigateur passerait plus de temps à les lire que le serveur à
     * travailler, et l'affichage n'en serait pas plus précis.
     */
    public function testLeFluxEstEspaceDansLeTemps(): void
    {
        $lignes = [];
        $progression = new Progression(5000, static function (array $etat) use (&$lignes): void {
            $lignes[] = $etat;
        });

        for ($i = 0; $i < 5000; ++$i) {
            $progression->avancer();
        }

        self::assertLessThan(
            60,
            count($lignes),
            'Cinq mille avancements ne doivent pas produire cinq mille lignes de flux.',
        );
    }

    /** Nommer l'étape publie TOUJOURS : l'utilisateur doit savoir ce qui avance. */
    public function testNommerUneEtapePublieToujours(): void
    {
        $lignes = [];
        $progression = new Progression(100, static function (array $etat) use (&$lignes): void {
            $lignes[] = $etat;
        });

        $progression->etape('Clients');
        $progression->etape('Polices');
        $progression->etape('Échéanciers');

        self::assertCount(3, $lignes, 'Un changement d\'étape n\'est jamais escamoté par le pas minimal.');
        self::assertSame('Échéanciers', $lignes[2]['libelle']);
    }

    /** L'état publié porte tout ce dont l'écran a besoin, et rien de plus. */
    public function testLEtatPublieCeQueLEcranAttend(): void
    {
        $progression = new Progression(50);
        $progression->etape('Clients');
        $progression->avancer(25);

        $etat = $progression->etat();

        self::assertSame('progres', $etat['type']);
        self::assertSame(25, $etat['fait']);
        self::assertSame(50, $etat['total']);
        self::assertSame(50.0, $etat['pct']);
        self::assertSame('Clients', $etat['libelle']);
        self::assertArrayHasKey('restant', $etat);
    }

    /** Une progression muette ne publie rien : les tests et les commandes n'ont pas d'écran. */
    public function testUneProgressionMuetteNePublieRien(): void
    {
        $progression = Progression::muette();

        $progression->etape('Peu importe');
        $progression->avancer(10);
        $progression->terminer();

        // Aucun rappel n'a été fourni : la seule preuve utile est qu'on n'a pas levé.
        self::assertSame(0, $progression->total());
    }
}
