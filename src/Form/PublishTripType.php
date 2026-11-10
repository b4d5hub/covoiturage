<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Form\Model\ItineraryLine;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Publication d'un trajet par son conducteur, avec l'un de ses propres véhicules.
 */
final class PublishTripType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $driver = $options['driver'];

        $builder
            ->add('vehicle', EntityType::class, [
                'label' => 'Véhicule',
                'class' => Vehicle::class,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('v')
                    ->andWhere('v.owner = :driver')
                    ->setParameter('driver', $driver),
                'choice_label' => static fn (Vehicle $vehicle) => $vehicle.' ('.$vehicle->getPassengerSeats().' places)',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('seatCapacity', IntegerType::class, [
                'label' => 'Places proposées',
                'attr' => ['min' => 1],
                'constraints' => [new Assert\NotBlank(), new Assert\Positive()],
            ])
            ->add('fullPriceCents', MadAmountType::class, [
                'label' => 'Prix d\'une place pour le trajet complet (MAD)',
                'help' => 'Le prix d\'une portion est proportionnel à sa distance.',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez un prix.'), new Assert\Positive()],
            ])
            ->add('itinerary', CollectionType::class, [
                'label' => 'Itinéraire',
                'entry_type' => ItineraryLineType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'data' => [new ItineraryLine(), new ItineraryLine()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('driver');
        $resolver->setAllowedTypes('driver', User::class);
    }
}
