<?php

namespace App\Tests\Twig;

use App\Entity\ModelePieceSinistre;
use App\Form\PieceSinistreType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * UNE DONNÉE SAISIE NE DEVIENT PAS DU HTML CÔTÉ SERVEUR.
 *
 * Deux sites où le serveur composait lui-même du HTML autour d'une donnée saisie, hors
 * de l'échappement automatique de Twig : la pièce d'une écriture du journal de la console
 * (`('<code>' ~ e.piece ~ '</code>')|raw`), et le libellé d'un modèle de pièce de sinistre
 * (`label_html`, nom et description concaténés).
 */
class EchappementRenduServeurTest extends KernelTestCase
{
    private const CHARGE = '<img src=x onerror=alert(1)>';

    public function testLaPieceDUneEcritureDuJournal(): void
    {
        self::bootKernel();
        $html = static::getContainer()->get(Environment::class)->render('console/document/_journal.html.twig', [
            'documents' => [
                'exercice' => 2026,
                'journal' => [
                    'ecritures' => [[
                        'date' => new \DateTimeImmutable('2026-10-16'),
                        'piece' => 'DEP-' . self::CHARGE,
                        'libelle' => 'Dépense',
                        'lignes' => [['compte' => '601', 'libelle' => 'Achats', 'debit' => 10, 'credit' => 0]],
                    ]],
                    'totalDebit' => 10,
                    'totalCredit' => 10,
                ],
            ],
        ]);

        self::assertStringNotContainsString('<img src=x', $html, 'La référence saisie sort en HTML.');
        self::assertStringContainsString('<code>DEP-&lt;img src=x', $html);
    }

    public function testLeLibelleDUnModeleDePiece(): void
    {
        $modele = (new ModelePieceSinistre())
            ->setNom('Constat ' . self::CHARGE)
            ->setDescription('<p>Procès-verbal &amp; photos ' . self::CHARGE . '</p>');

        $libelle = PieceSinistreType::libelleDuModele($modele);

        self::assertStringNotContainsString('<img src=x', $libelle, 'Le nom ou la description saisis sortent en HTML.');
        self::assertStringContainsString('<strong>Constat &lt;img src=x', $libelle);
        // La description riche se lit comme du texte : ni <p> visible, ni entité doublée.
        self::assertStringContainsString('Procès-verbal &amp; photos', $libelle);
        self::assertStringNotContainsString('&lt;p&gt;', $libelle);
    }
}
