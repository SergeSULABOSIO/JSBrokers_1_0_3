<?php

namespace App\Form;

use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création d'un ticket de support, sur le pattern Console (Coupon).
 */
class CrmTicketType extends AbstractType
{
    public function __construct(private UtilisateurRepository $utilisateurRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('client', EntityType::class, [
                'class'        => Utilisateur::class,
                'choices'      => $this->utilisateurRepository->findAllCrm(),
                'choice_label' => fn (Utilisateur $u) => ($u->getNom() ?: $u->getEmail()) . ' (' . $u->getEmail() . ')',
                'label'        => 'Client concerné',
                'placeholder'  => '— Choisir un client —',
                'attr'         => ['data-icon' => 'client'],
            ])
            ->add('sujet', TextType::class, [
                'label' => 'Sujet',
                'attr'  => ['placeholder' => 'Ex. Problème de connexion', 'data-icon' => 'feedback'],
            ])
            ->add('priorite', ChoiceType::class, [
                'label'   => 'Priorité',
                'choices' => [
                    'Basse'   => CrmTicket::PRIORITE_BASSE,
                    'Normale' => CrmTicket::PRIORITE_NORMALE,
                    'Haute'   => CrmTicket::PRIORITE_HAUTE,
                    'Urgente' => CrmTicket::PRIORITE_URGENTE,
                ],
                'attr'    => ['data-icon' => 'action:alert'],
            ])
            ->add('canal', ChoiceType::class, [
                'label'   => 'Canal',
                'choices' => [
                    'E-mail'    => 'email',
                    'Téléphone' => 'telephone',
                    'Chat'      => 'chat',
                ],
                'attr'    => ['data-icon' => 'contact'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description',
                'required' => false,
                'attr'     => ['placeholder' => 'Détails de la demande (optionnel)', 'rows' => 4, 'data-icon' => 'action:description'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CrmTicket::class]);
    }
}
