<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recherche par départ, arrivée (une ville ou un lieu précis) et date (jour au Maroc).
 * Les noms des villes connues sont proposés par une liste locale (datalist), sans appel externe.
 */
final class TripSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $place = static fn (string $label, string $placeholder, string $message) => [
            'label' => $label,
            'attr' => ['list' => 'known-cities', 'placeholder' => $placeholder, 'autocomplete' => 'off', 'maxlength' => 200],
            'constraints' => [new Assert\NotBlank(message: $message), new Assert\Length(max: 200)],
        ];

        $builder
            ->add('from', TextType::class, $place('Départ', 'Ville ou lieu, ex. Rabat, Agdal', 'Indiquez un lieu de départ.'))
            ->add('to', TextType::class, $place('Arrivée', 'Ville ou lieu, ex. Casablanca', 'Indiquez un lieu d\'arrivée.'))
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'string',
                'attr' => null === $options['min_date'] ? [] : ['min' => $options['min_date']],
                'constraints' => [new Assert\NotBlank(message: 'Choisissez une date.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['method' => 'GET', 'csrf_protection' => false, 'min_date' => null]);
        $resolver->setAllowedTypes('min_date', ['null', 'string']);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
