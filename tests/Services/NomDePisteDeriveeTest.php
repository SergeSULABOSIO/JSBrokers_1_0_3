<?php

namespace App\Tests\Services;

use App\Entity\Piste;
use App\Form\PisteType;
use App\Services\Piste\NomDePisteDerivee;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE NOM D'UNE OPPORTUNITÉ DÉRIVÉE NE PORTE QU'UN PRÉFIXE.
 *
 * Chaque mouvement préfixait le nom de base, déjà préfixé : « Renouvellement —
 * Renouvellement — X » la deuxième année. Ces tests protègent la règle, et son
 * identité avec celle du navigateur (contrôleur Stimulus « piste-name-sync ») :
 * mêmes libellés, même séparateur.
 */
class NomDePisteDeriveeTest extends KernelTestCase
{
    public function testTousLesPrefixesConnusSontRetiresEnBoucle(): void
    {
        $this->assertSame('X', NomDePisteDerivee::sansPrefixe('Prorogation — Renouvellement — X'));
        $this->assertSame('X', NomDePisteDerivee::sansPrefixe('Renouvellement — Renouvellement — Renouvellement — X'));
        $this->assertSame('X', NomDePisteDerivee::sansPrefixe('X'));
    }

    public function testUnSeulPrefixeCeluiDuMouvement(): void
    {
        $this->assertSame('Renouvellement — X', NomDePisteDerivee::nommer('Renouvellement', 'Prorogation — Renouvellement — X'));
        $this->assertSame('Prorogation — X', NomDePisteDerivee::nommer('Prorogation', 'X'));
        $this->assertSame('Renouvellement', NomDePisteDerivee::nommer('Renouvellement', null));
    }

    /** Un nom personnalisé n'est pas touché : seul un libellé CONNU suivi du séparateur est un préfixe. */
    public function testUnNomPersonnaliseResteIntact(): void
    {
        $this->assertSame('Chantier — lot 2', NomDePisteDerivee::sansPrefixe('Chantier — lot 2'));
        $this->assertSame('Renouvellement-X', NomDePisteDerivee::sansPrefixe('Renouvellement-X'));
    }

    public function testLeNomResteDansLaLongueurDeLaColonne(): void
    {
        $this->assertSame(255, mb_strlen(NomDePisteDerivee::nommer('Renouvellement', str_repeat('é', 400))));
    }

    /**
     * PARITÉ AVEC LE NAVIGATEUR. piste-name-sync reçoit ses libellés de l'attribut posé par
     * PisteType sur le <form> : ils doivent être EXACTEMENT ceux que la règle serveur reconnaît,
     * et le séparateur du JS doit être celui du serveur.
     */
    public function testLesLibellesEtLeSeparateurSontCeuxDePisteNameSync(): void
    {
        self::bootKernel();
        $vue = static::getContainer()->get('form.factory')->create(PisteType::class, new Piste())->createView();

        $this->assertSame('piste-name-sync', $vue->vars['attr']['data-controller'] ?? null);
        $libellesJs = json_decode((string) $vue->vars['attr']['data-piste-name-sync-labels-value'], true);
        $this->assertSame(NomDePisteDerivee::libellesConnus(), array_values($libellesJs));

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/controllers/piste-name-sync_controller.js');
        $this->assertStringContainsString(sprintf("static SEP = '%s';", NomDePisteDerivee::SEPARATEUR), $js, 'Même séparateur des deux côtés.');
        $this->assertStringContainsString('Object.values(this.labelsValue', $js, 'Le JS lit ses libellés de l’attribut, sans liste à lui.');
    }
}
