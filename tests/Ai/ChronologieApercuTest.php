<?php

namespace App\Tests\Ai;

use App\Ai\Presentation\Colonnes;
use App\Ai\Presentation\TableauMarkdown;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le rendu FINAL d'une chronologie, tel que le courtier le lit dans sa bulle.
 *
 * Ce test relie les deux chantiers : l'outil chronologie produit les faits, et le
 * contrat de présentation les met en forme. Ce qu'il vérifie n'est pas la donnée — les
 * autres tests s'en chargent — mais la LISIBILITÉ du résultat : les deux dates côte à
 * côte en jj/mm/aaaa, aucune colonne totalisée (additionner des dates n'a aucun sens),
 * et l'ordre du récit préservé.
 */
class ChronologieApercuTest extends KernelTestCase
{
    public function testUneChronologieSeRendEnTableauLisible(): void
    {
        static::bootKernel();
        $rendu = static::getContainer()->get(TableauMarkdown::class);

        $lignes = [
            ['date' => '2026-01-12', 'fait' => 'Compte client créé', 'objet' => 'MIC-RC', 'saisiLe' => '2026-01-12', 'par' => 'Serge'],
            ['date' => '2026-02-28', 'fait' => 'Police enregistrée', 'objet' => 'POL-130', 'saisiLe' => '2026-02-28', 'par' => 'Serge'],
            ['date' => '2026-03-01', 'fait' => 'Police prend effet', 'objet' => 'POL-130', 'saisiLe' => '2026-02-28', 'par' => 'Serge'],
            ['date' => '2026-03-15', 'fait' => 'Prime réglée par l\'assuré', 'objet' => 'PRIME-001', 'saisiLe' => '2026-03-17', 'par' => 'Serge'],
            ['date' => '2027-02-28', 'fait' => 'Police arrive à échéance', 'objet' => 'POL-130', 'saisiLe' => '2026-02-28', 'par' => 'Serge'],
        ];

        $tableau = $rendu->rendre($lignes, Colonnes::de([
            'date' => Colonnes::DATE,
            'fait' => Colonnes::TEXTE,
            'objet' => Colonnes::TEXTE,
            'saisiLe' => Colonnes::DATE,
            'par' => Colonnes::TEXTE,
        ], []));

        // Les deux dates se lisent, et aucune n'est restée au format d'échange.
        $this->assertStringContainsString('| 01/03/2026 | Police prend effet | POL-130 | 28/02/2026 |', $tableau);
        $this->assertStringNotContainsString('2026-03-01', $tableau);

        // Aucune colonne n'est alignée à droite ni totalisée : ce tableau ne compte rien.
        $this->assertStringContainsString('| --- | --- | --- | --- | --- |', $tableau);
        $this->assertStringNotContainsString('TOTAL', $tableau);

        // L'ordre du récit est celui des dates métier, échéance future comprise.
        $this->assertLessThan(
            mb_strpos($tableau, '28/02/2027'),
            mb_strpos($tableau, '12/01/2026'),
            'La chronologie se lit de l’ouverture du compte vers l’avenir.',
        );
    }
}
