<?php

namespace App\Form;

use App\Entity\Vehicle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VehicleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('brand', TextType::class, ['label' => 'Marque'])
            ->add('model', TextType::class, ['label' => 'Modèle'])
            ->add('passengerSeats', IntegerType::class, ['label' => 'Places passagers (hors conducteur)', 'attr' => ['min' => 1]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Vehicle::class]);
    }
}
