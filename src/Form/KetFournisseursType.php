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
                    self::lisiblePourLEditeur($options['politique'][$famille] ?? []),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }
    }

    /**
     * UN DICTIONNAIRE VIDE DOIT RESTER UN DICTIONNAIRE, PAS DEVENIR UNE LISTE.
     *
     * `json_encode([])` rend `[]`. Or l'éditeur relit ce JSON et y range les
     * réglages par nom de fournisseur : en JavaScript, poser une propriété nommée
     * sur un TABLEAU fonctionne… mais `JSON.stringify` la jette en silence. Un
     * modèle saisi dans la console repartait donc vide, sans la moindre erreur —
     * et comme `reglages` est vide sur toute plateforme qui n'a rien personnalisé,
     * c'était le cas GÉNÉRAL. Constaté le 2026-09-22 en relisant la politique
     * réellement enregistrée en base.
     *
     * Le forcer en objet ici règle la question à la source. L'éditeur s'en protège
     * aussi de son côté : deux gardes valent mieux qu'une pour un défaut muet.
     *
     * @param array<string, mixed> $famille
     *
     * @return array<string, mixed>
     */
    private static function lisiblePourLEditeur(array $famille): array
    {
        if (($famille['reglages'] ?? null) === []) {
            $famille['reglages'] = new \stdClass();
        }

        return $famille;
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
