<?php

namespace App\Form;

use App\Entity\City;
use App\Form\Model\ItineraryLine;
use App\Time\MoroccoTime;
use Doctrine\ORM\EntityRepository;
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
            // Sur le site, la ville est déterminée par le serveur d'après le point de rendez-vous ;
            // la liste ne sert que si le service de cartographie est indisponible.
            ->add('city', EntityType::class, [
                'class' => City::class,
                'label' => 'Ville',
                'placeholder' => $options['city_from_point'] ? 'Déterminée d\'après le point de rendez-vous' : 'Choisir une ville',
                'required' => !$options['city_from_point'],
                'help' => $options['city_from_point'] ? 'À choisir seulement si la carte est indisponible' : null,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('c')->orderBy('c.name'),
                'constraints' => $options['city_from_point'] ? [] : [new Assert\NotNull(message: 'Choisissez une ville.')],
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
            ->add('arrivalAt', DateTimeType::class, $moment + ['label' => 'Arrivée ('.MoroccoTime::ZONE.')'])
            ->add('departureAt', DateTimeType::class, $moment + ['label' => 'Départ ('.MoroccoTime::ZONE.')'])
            ->add('distanceToNextMeters', IntegerType::class, [
                'label' => 'Distance jusqu\'à l\'étape suivante (m)',
                'help' => 'Distance routière, en mètres',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ItineraryLine::class, 'city_from_point' => false]);
        $resolver->setAllowedTypes('city_from_point', 'bool');
    }
}
