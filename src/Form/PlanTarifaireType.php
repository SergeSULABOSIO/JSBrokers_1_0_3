<?php

namespace App\Form;

use App\Entity\PlateformeParametres;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Édition du plan tarifaire global (entité PlateformeParametres).
 * Les champs scalaires sont mappés directement ; les structures (paquets et
 * poids d'écriture par entité) sont éditées en JSON via des zones de texte
 * (non mappées) — simple et totalement général. Le contrôleur décode/valide.
 */
class PlanTarifaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('freeAllowance', IntegerType::class, [
                'label' => 'Allocation gratuite (tokens / fenêtre)',
                'attr'  => ['placeholder' => 'Ex. 1000', 'data-icon' => 'action:count'],
            ])
            ->add('freeWindowHours', IntegerType::class, [
                'label' => 'Durée de la fenêtre gratuite (heures)',
                'attr'  => ['placeholder' => 'Ex. 8', 'data-icon' => 'action:calendar'],
            ])
            ->add('readWeight', IntegerType::class, [
                'label' => 'Poids d\'une lecture (tokens / entité)',
                'attr'  => ['placeholder' => 'Ex. 2', 'data-icon' => 'action:count'],
            ])
            ->add('defaultWriteWeight', IntegerType::class, [
                'label' => 'Poids d\'écriture par défaut (tokens)',
                'attr'  => ['placeholder' => 'Ex. 5', 'data-icon' => 'action:count'],
            ])
            ->add('usdPerToken', NumberType::class, [
                'label' => 'Taux de conversion (USD / token)',
                'scale' => 5,
                'attr'  => ['placeholder' => 'Ex. 0.001', 'data-icon' => 'monnaie'],
            ])
            // Documents produits par l'assistant. Trois scalaires SÉPARÉS (et non un
            // JSON unique) : chacun porte ainsi son propre repli sur la constante,
            // si bien que régler le coût de base n'emporte pas le prix de la page.
            ->add('documentBase', IntegerType::class, [
                'label' => 'Documents IA — coût de base (tokens)',
                'attr'  => ['placeholder' => 'Ex. 60', 'data-icon' => 'action:count'],
            ])
            ->add('documentParPage', IntegerType::class, [
                'label' => 'Documents IA — coût par page (tokens)',
                'attr'  => ['placeholder' => 'Ex. 30', 'data-icon' => 'action:count'],
            ])
            ->add('documentCaracteresParPage', IntegerType::class, [
                'label' => 'Documents IA — taille d\'une page (caractères)',
                'attr'  => ['placeholder' => 'Ex. 2500', 'data-icon' => 'action:description'],
            ])
            // ── ÉCHANGE DE DONNÉES (rubrique « Importation / Exportation ») ──────────
            //
            // ⚠ C'EST LE SEUL RÉGLAGE QUI COMPTE ENCORE ICI, et il est ANNONCÉ SUR LE SITE
            // PUBLIC : la page de tarif lit ce nombre via `plan_tarifaire()`. Le changer
            // change ce que lisent les visiteurs — c'est une promesse commerciale, pas un
            // paramètre technique.
            ->add('echangeFranchiseLignes', IntegerType::class, [
                'label' => 'Reprise de données — lignes offertes par cabinet (à vie)',
                'help'  => 'Comptées sur la feuille DONNEES du gabarit, tous dépôts confondus, quel que soit le nombre '
                    . 'd\'enregistrements qu\'une ligne fait naître. Au-delà, chaque ligne paie le métrage d\'écriture '
                    . 'ordinaire des entités qu\'elle crée. Ce nombre est affiché aux visiteurs sur la page de tarif.',
                'attr'  => ['placeholder' => 'Ex. 1000', 'data-icon' => 'echange'],
            ])
            // ⚠ LES DEUX SUIVANTS NE S'APPLIQUENT PLUS, et on ne les retire pas pour
            // autant : ils vivent dans un barème publié, et une colonne qu'on efface est
            // une donnée qu'on perd. Ils restent réglables — leur libellé dit simplement
            // qu'ils dorment, pour qu'aucun agent n'y règle un prix qui ne s'applique nulle
            // part.
            ->add('echangeQuotaGratuit', IntegerType::class, [
                'label' => 'Échange — opérations offertes (sans effet)',
                'help'  => 'Ancien modèle, conservé pour mémoire : plus aucune opération d\'échange n\'est facturée à l\'unité.',
                'attr'  => ['placeholder' => 'Ex. 3', 'data-icon' => 'action:count'],
            ])
            ->add('echangeCoutOccurrence', IntegerType::class, [
                'label' => 'Échange — coût d\'une exportation (sans effet)',
                'help'  => 'L\'exportation est désormais GRATUITE ET ILLIMITÉE, et le site public l\'annonce ainsi. Ce réglage n\'est plus lu.',
                'attr'  => ['placeholder' => 'Ex. 600', 'data-icon' => 'echange'],
            ])
            // Paquets prépayés : édités via une collection + boîte de dialogue (contrôleur
            // Stimulus `packs-editor`) qui tient ce champ caché synchronisé en JSON. Le
            // contrôleur PHP décode ce JSON de façon générique (cf. decodeJsonMap).
            ->add('packsJson', HiddenType::class, [
                'mapped' => false,
                'data'   => $options['packs_json'],
            ])
            // Poids d'écriture par entité : édités via une collection + boîte de dialogue
            // (contrôleur Stimulus `weights-editor`) qui tient ce champ caché synchronisé
            // en JSON. Le contrôleur PHP décode ce JSON de façon générique (decodeJsonMap).
            ->add('writeWeightsJson', HiddenType::class, [
                'mapped'   => false,
                'required' => false,
                'data'     => $options['write_weights_json'],
            ])
            // Multiplicateurs par format : même mécanique JSON cachée, tenue à jour
            // par le contrôleur Stimulus `formats-editor`.
            ->add('documentFormatsJson', HiddenType::class, [
                'mapped'   => false,
                'required' => false,
                'data'     => $options['document_formats_json'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'            => PlateformeParametres::class,
            'packs_json'            => '',
            'write_weights_json'    => '',
            'document_formats_json' => '',
        ]);
    }
}
