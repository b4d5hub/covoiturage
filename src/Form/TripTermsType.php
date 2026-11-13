<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Places proposées et prix du trajet entier, modifiables par le conducteur tant que le trajet n'est pas parti.
 * Les autres règles (places du véhicule, places déjà occupées) sont vérifiées par l'entité, sous le verrou du trajet.
 */
final class TripTermsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('seatCapacity', IntegerType::class, [
                'label' => 'Places proposées',
                'attr' => ['min' => 1, 'max' => $options['max_seats'], 'inputmode' => 'numeric'],
                'constraints' => [new Assert\NotBlank(message: 'Indiquez le nombre de places.'), new Assert\Positive(message: 'Proposez au moins une place.')],
            ])
            ->add('fullPriceCents', MadAmountType::class, [
                'label' => 'Prix du trajet entier (MAD)',
                'attr' => ['inputmode' => 'decimal'],
                'constraints' => [new Assert\NotBlank(message: 'Indiquez un prix.'), new Assert\Positive(message: 'Le prix doit être positif.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['max_seats' => null]);
        $resolver->setAllowedTypes('max_seats', ['null', 'int']);
    }
}
