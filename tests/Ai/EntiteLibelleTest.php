<?php

namespace App\Tests\Ai;

use App\Ai\Tool\EntiteLibelle;
use App\Entity\Client;
use App\Entity\Risque;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Détection du champ de LIBELLÉ d'une entité pour l'assistant (affichage + filtre
 * texte des outils de lecture). Verrou de non-régression : un Risque doit être
 * étiqueté par son `nomComplet` (nom métier), jamais par sa `description` — sans
 * quoi il devenait illisible en liste ET introuvable au filtre texte.
 */
class EntiteLibelleTest extends KernelTestCase
{
    private EntiteLibelle $libelleur;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->libelleur = static::getContainer()->get(EntiteLibelle::class);
    }

    public function testRisqueEtiqueteParNomCompletPasDescription(): void
    {
        $this->assertSame('nomComplet', $this->libelleur->displayField(Risque::class));
    }

    public function testLibelleRisqueLitLeNomComplet(): void
    {
        $risque = (new Risque())->setNomComplet('Responsabilité Civile Automobile')->setDescription('<p>lorem ipsum</p>');

        $this->assertSame(
            'Responsabilité Civile Automobile',
            $this->libelleur->libelle($risque, $this->libelleur->displayField(Risque::class)),
        );
    }

    public function testClientResteEtiqueteParNom(): void
    {
        // Non-régression : les entités disposant d'un vrai champ `nom` ne changent pas.
        $this->assertSame('nom', $this->libelleur->displayField(Client::class));
    }
}
