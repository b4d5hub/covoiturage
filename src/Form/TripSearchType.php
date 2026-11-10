<?php

namespace App\Form;

use App\Entity\City;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recherche par ville de départ, ville d'arrivée et date (jour au Maroc).
 */
final class TripSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $city = [
            'class' => City::class,
            'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('c')->orderBy('c.name'),
            'placeholder' => 'Choisir une ville',
            'constraints' => [new Assert\NotNull(message: 'Choisissez une ville.')],
        ];

        $builder
            ->add('from', EntityType::class, $city + ['label' => 'Départ'])
            ->add('to', EntityType::class, $city + ['label' => 'Arrivée'])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'string',
                'constraints' => [new Assert\NotBlank(message: 'Choisissez une date.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['method' => 'GET', 'csrf_protection' => false]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
