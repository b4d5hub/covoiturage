<?php

namespace App\Form;

use App\Entity\City;
use App\Form\Model\ItineraryLine;
use App\Time\MoroccoTime;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ItineraryLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $coordinate = ['input' => 'string', 'scale' => 6, 'html5' => true, 'attr' => ['step' => 'any']];
        $moment = [
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'model_timezone' => 'UTC',
            'view_timezone' => MoroccoTime::ZONE,
            'required' => false,
        ];

        $builder
            ->add('city', EntityType::class, [
                'class' => City::class,
                'label' => 'Ville',
                'constraints' => [new Assert\NotNull(message: 'Choisissez une ville.')],
            ])
            ->add('meetingAddress', TextType::class, [
                'label' => 'Point de rendez-vous',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez le point de rendez-vous.'), new Assert\Length(max: 255)],
            ])
            ->add('latitude', NumberType::class, $coordinate + [
                'label' => 'Latitude',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez la latitude.'), new Assert\Range(min: -90, max: 90)],
            ])
            ->add('longitude', NumberType::class, $coordinate + [
                'label' => 'Longitude',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez la longitude.'), new Assert\Range(min: -180, max: 180)],
            ])
            ->add('arrivalAt', DateTimeType::class, $moment + ['label' => 'Arrivée (heure du Maroc)'])
            ->add('departureAt', DateTimeType::class, $moment + ['label' => 'Départ (heure du Maroc)'])
            ->add('distanceToNextMeters', IntegerType::class, [
                'label' => 'Distance jusqu\'à l\'étape suivante (m)',
                'help' => 'À laisser vide pour la dernière étape.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ItineraryLine::class]);
    }
}
