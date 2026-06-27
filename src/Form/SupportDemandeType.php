<?php

namespace App\Form;

use App\Entity\Crm\CrmTicket;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @file Formulaire d'ouverture d'une demande de support, côté courtier.
 * @description Variante allégée de CrmTicketType (console) : le client n'est PAS
 * choisi (c'est l'utilisateur courant, fixé côté serveur) et le canal est imposé
 * (« portail »). Lié à CrmTicket : la référence et l'échéance SLA sont calculées
 * automatiquement à la persistance (PrePersist).
 */
class SupportDemandeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sujet', TextType::class, [
                'label' => 'Objet de votre demande',
                'attr'  => ['placeholder' => 'Ex. Je n\'arrive pas à générer un bordereau', 'maxlength' => 200],
            ])
            ->add('priorite', ChoiceType::class, [
                'label'   => 'Niveau d\'urgence',
                'choices' => [
                    'Basse — question générale'       => CrmTicket::PRIORITE_BASSE,
                    'Normale — gêne dans mon travail' => CrmTicket::PRIORITE_NORMALE,
                    'Haute — blocage important'       => CrmTicket::PRIORITE_HAUTE,
                    'Urgente — activité à l\'arrêt'   => CrmTicket::PRIORITE_URGENTE,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description (facultatif)',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => 'Décrivez votre besoin, les étapes déjà tentées, un éventuel message d\'erreur…'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CrmTicket::class]);
    }
}
