<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyPathInterface;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * LES OPTIONS D'AUTOCOMPLÉTION SONT DU HTML — ET SEULEMENT DU HTML ÉCHAPPÉ.
 *
 * Nos champs d'autocomplétion composent des libellés riches par RenduOptionAutocomplete,
 * qui échappe chaque donnée. Depuis ux-autocomplete 2.36 (CVE-2026-49216), un libellé
 * reçu en AJAX est échappé sauf `options_as_html` : sans ce réglage, ils s'affichaient
 * en balises brutes. On le pose donc pour tous.
 *
 * LE FILET : un `choice_label` donné comme simple PROPRIÉTÉ (`'nom'`) ne passe pas par ce
 * rendu — son texte, rendu en HTML, rouvrirait la faille corrigée. Il est échappé ici.
 * Un libellé calculé (closure) reste de la responsabilité du champ, qui passe par
 * RenduOptionAutocomplete (AutocompleteLibellesHtmlTest).
 */
final class AutocompleteLibelleHtmlExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [BaseEntityAutocompleteType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('options_as_html', true);

        $resolver->addNormalizer('choice_label', static function (Options $options, mixed $libelle): mixed {
            // Aucun libellé : le bundle retomberait sur `(string) $entite`, rendu en HTML.
            if ($libelle === null) {
                return static fn (object $choix): string => htmlspecialchars((string) $choix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (!\is_string($libelle) && !$libelle instanceof PropertyPathInterface) {
                return $libelle;
            }
            $lecteur = PropertyAccess::createPropertyAccessor();

            return static fn (object $choix): string => htmlspecialchars(
                (string) $lecteur->getValue($choix, $libelle),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
        });
    }
}
