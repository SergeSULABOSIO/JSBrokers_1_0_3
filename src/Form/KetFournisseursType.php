<?php

namespace App\Form;

use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * La politique des fournisseurs de Ket, une famille par champ caché.
 *
 * POURQUOI DES CHAMPS CACHÉS ET UN ÉDITEUR, plutôt que des champs de formulaire.
 * Une famille porte un mode, une liste ORDONNÉE et des réglages par fournisseur :
 * ce n'est pas une grille de cases à cocher, c'est un petit objet qu'on réordonne.
 * Le projet a déjà tranché cette question pour les paquets de jetons, les poids
 * d'écriture et les formats de document — un `HiddenType` non mappé, alimenté par
 * le contrôleur en JSON lisible, et un contrôleur Stimulus qui l'édite. On suit.
 *
 * LES VALEURS SONT LES VALEURS EFFECTIVES, jamais la seule personnalisation.
 * C'est le piège documenté dans PlanTarifaireController : afficher ce qui est en
 * base montrerait des champs vides là où la plateforme tourne très bien sur ses
 * défauts, et l'agent croirait devoir tout ressaisir.
 */
class KetFournisseursType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (PolitiqueDesFournisseurs::FAMILLES as $famille) {
            $builder->add($famille . 'Json', HiddenType::class, [
                'mapped' => false,
                'data'   => json_encode(
                    $options['politique'][$famille] ?? [],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'politique'  => [],
        ]);
        $resolver->setAllowedTypes('politique', 'array');
    }
}
