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

    /**
     * UNE POLICE SE DÉSIGNE PAR SA RÉFÉRENCE, JAMAIS PAR LE NUMÉRO D'AVENANT.
     *
     * L'INCIDENT DU 2026-08-14. « Attache ces fichiers à la police SURDCVO00018389 » :
     * la référence existait bien en base. Mais `numero` était retenu avant
     * `referencePolice`, si bien que la recherche portait sur « 0 », « 1 », « 3 » et ne
     * trouvait rien — puis la question de désambiguïsation renvoyait ces mêmes numéros,
     * tous identiques et illisibles. Ket en concluait, à l'écran, que « cette police n'a
     * pas pu être trouvée dans votre portefeuille » : non pas « je n'ai pas su
     * chercher », mais « cela n'existe pas ».
     */
    public function testAvenantEtiqueteParSaReferenceDePolicePasParSonNumero(): void
    {
        $this->assertSame('referencePolice', $this->libelleur->displayField(\App\Entity\Avenant::class));
    }

    /**
     * Deux entités n'avaient AUCUN champ de libellé — elles ne portent que
     * `referencePolice` — et étaient donc parfaitement introuvables par leur nom :
     * `displayField()` rendait null, et le résolveur abandonnait avant même de chercher.
     */
    public function testSinistreEtOperationDeviennentDesignablesParLeurReference(): void
    {
        $this->assertSame('referencePolice', $this->libelleur->displayField(\App\Entity\NotificationSinistre::class));
        $this->assertSame('referencePolice', $this->libelleur->displayField(\App\Entity\Operation::class));
    }

    /**
     * Le rang de `referencePolice` est choisi, pas subi : APRÈS `reference`, pour qu'un
     * Paiement garde la sienne, et AVANT `numero`.
     */
    public function testUnPaiementGardeSaProprePropreReference(): void
    {
        $this->assertSame('reference', $this->libelleur->displayField(\App\Entity\Paiement::class));
    }

    /**
     * AFFICHER ET DÉSIGNER NE SONT PAS LA MÊME CHOSE. Un avenant s'affiche par sa
     * référence de police, mais « l'avenant 3 » reste une façon légitime de le nommer :
     * les deux champs doivent rester interrogeables, dans cet ordre de précision.
     */
    public function testUnAvenantResteDesignableParSonNumeroApresSaReference(): void
    {
        $champs = $this->libelleur->champsDeResolution(\App\Entity\Avenant::class);

        $this->assertSame('referencePolice', $champs[0] ?? null, 'Le plus précis en premier.');
        $this->assertContains('numero', $champs, '« L’avenant 3 » doit continuer de se résoudre.');
        $this->assertLessThan(
            array_search('numero', $champs, true),
            array_search('referencePolice', $champs, true),
            'La référence doit être essayée AVANT le numéro, sans quoi « 0 » capterait tout.',
        );
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
