<?php

namespace App\Tests\Workspace;

use App\Entity\Contact;
use App\Services\Canvas\Provider\Entity\ContactEntityCanvasProvider;
use App\Services\Canvas\Provider\List\ContactListCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA LISTE DES CONTACTS NE PORTE AUCUN MONTANT — mais la fiche les garde.
 *
 * La liste affichait « Prime Totale », « Comm. Totale », « Comm. Pure » et « Réserve ». Ces
 * montants ne sont pas ceux du contact : ce sont ceux de SON CLIENT, répétés à l'identique
 * sur chacune de ses lignes. Quatre colonnes qui disent la même chose autant de fois qu'il y
 * a d'interlocuteurs, et qui laissent croire qu'une personne produirait du chiffre.
 *
 * L'équilibre à tenir, et que ce test verrouille : les retirer de la LISTE ne doit pas les
 * retirer de la FICHE, où ils sont explicitement présentés comme ceux du client — et où un
 * attribut retiré emporterait aussi le filtre de recherche correspondant.
 */
class ContactListeSansColonnesNumeriquesTest extends KernelTestCase
{
    private const MONTANTS = ['primeTotale', 'montantTTC', 'montantPur', 'reserve'];

    protected function setUp(): void
    {
        static::bootKernel();
    }

    public function testLaListeNAffichePlusAucunMontant(): void
    {
        $canvas = static::getContainer()->get(ContactListCanvasProvider::class)->getCanvas();

        self::assertArrayNotHasKey(
            'colonnes_numeriques',
            $canvas,
            'Un contact est une personne : la liste montre qui il est, pas ce que son client rapporte.',
        );
        // La colonne principale, elle, reste entière — c'est ce qu'on vient chercher.
        self::assertSame('Contacts', $canvas['colonne_principale']['titre_colonne']);
        self::assertSame('nom', $canvas['colonne_principale']['texte_principal']['attribut_code']);
    }

    public function testLaFicheGardeLesMontantsDuClient(): void
    {
        $canvas = static::getContainer()->get(ContactEntityCanvasProvider::class)->getCanvas(new Contact(), null);
        $codes = array_column($canvas['liste'] ?? [], 'code');

        foreach (self::MONTANTS as $code) {
            self::assertContains(
                $code,
                $codes,
                "La fiche doit garder « {$code} » : c'est là qu'il est nommé comme un montant DU CLIENT, "
                . "et un attribut retiré du canevas emporte aussi son filtre de recherche.",
            );
        }
    }
}
