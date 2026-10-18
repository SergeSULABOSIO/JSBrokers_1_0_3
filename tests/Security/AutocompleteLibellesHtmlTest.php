<?php

namespace App\Tests\Security;

use App\Entity\Partenaire;
use App\Form\PartenaireAutocompleteField;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;

/**
 * LES OPTIONS D'AUTOCOMPLÉTION SONT DU HTML — ET SEULEMENT DU HTML ÉCHAPPÉ.
 *
 * symfony/ux-autocomplete 2.36 (CVE-2026-49216) échappe désormais les libellés reçus en
 * AJAX, sauf `options_as_html`. Nos dix-huit champs composent des libellés RICHES
 * (RenduOptionAutocomplete : titre, contact, chiffres), dont chaque donnée est déjà
 * échappée : sans `options_as_html`, ils s'affichaient en balises brutes.
 *
 * Mais réactiver le HTML expose tout libellé qui NE passe PAS par ce rendu : un
 * `'choice_label' => 'nom'` posé dans un FormType (PisteType en avait deux) rendrait le
 * nom tel quel, en HTML — exactement la faille que la mise à jour corrige. Le filet :
 * un libellé donné comme simple propriété est échappé (AutocompleteLibelleHtmlExtension).
 */
class AutocompleteLibellesHtmlTest extends KernelTestCase
{
    /** @return list<class-string> */
    private function champsDAutocompletion(): array
    {
        $champs = [];
        foreach (glob(__DIR__ . '/../../src/Form/*.php') as $fichier) {
            $classe = 'App\\Form\\' . basename($fichier, '.php');
            if (class_exists($classe) && (new \ReflectionClass($classe))->getAttributes(AsEntityAutocompleteField::class) !== []) {
                $champs[] = $classe;
            }
        }

        return $champs;
    }

    public function testChaqueChampRendSesOptionsEnHtml(): void
    {
        self::bootKernel();
        $registre = static::getContainer()->get(FormRegistryInterface::class);
        $champs = $this->champsDAutocompletion();
        self::assertGreaterThanOrEqual(15, \count($champs), 'La recherche des champs est cassée : trop peu trouvés.');

        $sansHtml = [];
        foreach ($champs as $classe) {
            $options = $registre->getType($classe)->getOptionsResolver()->resolve([]);
            if (($options['options_as_html'] ?? false) !== true) {
                $sansHtml[] = $classe;
            }
        }

        self::assertSame([], $sansHtml, 'Ces champs afficheraient leurs options riches en balises brutes.');
    }

    /**
     * LE LIBELLÉ DE CHAQUE CHAMP, CALCULÉ SUR UNE ENTITÉ PIÉGÉE. Le test précédent prouve
     * que le HTML est activé ; celui-ci prouve qu'il n'y a QUE du HTML échappé : un champ
     * nouveau écrit `fn ($x) => $x->getNom()` le ferait échouer.
     */
    public function testLeLibelleDeChaqueChampEchappeSesDonnees(): void
    {
        self::bootKernel();
        $registre = static::getContainer()->get(FormRegistryInterface::class);
        $charge = '<img src=x onerror=1>';

        $fautifs = [];
        $eprouves = 0;
        foreach ($this->champsDAutocompletion() as $classe) {
            $options = $registre->getType($classe)->getOptionsResolver()->resolve([]);
            $entite = (new \ReflectionClass($options['class']))->newInstance();
            foreach ((new \ReflectionClass($entite))->getProperties() as $propriete) {
                $type = $propriete->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                    $propriete->setValue($entite, $charge);
                }
            }
            $libelle = \is_callable($options['choice_label']) ? ($options['choice_label'])($entite) : null;
            if (!\is_string($libelle)) {
                $fautifs[] = "$classe : libellé non calculable";
                continue;
            }
            // La charge doit ARRIVER au libellé (échappée) : sinon le test ne prouve rien.
            if (str_contains($libelle, '&lt;img')) {
                ++$eprouves;
            }
            if (str_contains($libelle, '<img')) {
                $fautifs[] = "$classe : $libelle";
            }
        }

        self::assertSame([], $fautifs, 'Libellés qui rendent une donnée saisie en HTML.');
        self::assertGreaterThanOrEqual(15, $eprouves);
    }

    public function testUnLibelleDonneCommeProprieteEstEchappe(): void
    {
        self::bootKernel();
        $resolver = static::getContainer()->get(FormRegistryInterface::class)
            ->getType(PartenaireAutocompleteField::class)->getOptionsResolver();

        $libelle = $resolver->resolve(['choice_label' => 'nom'])['choice_label'];
        self::assertIsCallable($libelle);
        $partenaire = (new Partenaire())->setNom('<img src=x onerror="window.__xss=1">');

        self::assertSame('&lt;img src=x onerror=&quot;window.__xss=1&quot;&gt;', $libelle($partenaire));
    }
}
