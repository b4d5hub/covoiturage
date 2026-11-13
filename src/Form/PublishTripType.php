<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Form\Model\ItineraryLine;
use App\Geo\CityResolver;
use App\Geo\Coordinates;
use App\Geo\GeoServiceUnavailable;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
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
                // Le formulaire limite les places proposées à celles du véhicule ; le serveur refait le contrôle.
                'choice_attr' => static fn (Vehicle $vehicle) => ['data-seats' => $vehicle->getPassengerSeats()],
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('seatCapacity', IntegerType::class, [
                'label' => 'Places proposées',
                'help' => 'Au plus le nombre de places passagers du véhicule',
                'attr' => ['min' => 1],
                'constraints' => [new Assert\NotBlank(), new Assert\Positive()],
            ])
            ->add('fullPriceCents', MadAmountType::class, [
                'label' => 'Prix par place pour le trajet entier (MAD)',
                'help' => 'Un passager qui ne fait qu\'une partie du trajet paie selon la distance parcourue',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez un prix.'), new Assert\Positive()],
            ])
            ->add('itinerary', CollectionType::class, [
                'label' => 'Itinéraire',
                'entry_type' => ItineraryLineType::class,
                'entry_options' => ['label' => false, 'city_from_point' => true],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'data' => $options['itinerary'] ?? [new ItineraryLine(), new ItineraryLine()],
            ]);

        // Toutes les erreurs sont signalées en une fois, au bon champ et avec le numéro de l'étape.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            self::checkCapacity($form);
            $this->resolveCities($form->get('itinerary'));
            self::checkItinerary($form->get('itinerary'));
        });
    }

    public function __construct(private readonly CityResolver $cities)
    {
    }

    /**
     * Ville de chaque étape, déterminée d'après ses coordonnées ; la ville envoyée par le navigateur
     * n'est retenue que si le service de cartographie est indisponible.
     */
    private function resolveCities(FormInterface $itinerary): void
    {
        $available = true;

        foreach (array_values(iterator_to_array($itinerary)) as $index => $stop) {
            $line = $stop->getData();

            if (!$line instanceof ItineraryLine || null === $line->latitude || null === $line->longitude || !$stop->get('latitude')->isValid() || !$stop->get('longitude')->isValid()) {
                continue;
            }

            $label = 'Étape '.($index + 1).' : ';

            if ($available) {
                try {
                    $city = $this->cities->cityAt(Coordinates::fromFloats((float) $line->latitude, (float) $line->longitude));

                    if (null === $city) {
                        $stop->get('meetingAddress')->addError(new FormError($label.'ce point n\'est situé dans aucune localité du Maroc, choisissez un autre lieu.'));
                    } else {
                        $line->city = $city;
                    }

                    continue;
                } catch (GeoServiceUnavailable) {
                    // Pas de nouvel essai pour les étapes suivantes : la liste des villes prend le relais.
                    $available = false;
                }
            }

            if (null === $line->city) {
                $stop->get('city')->addError(new FormError($label.'la carte est indisponible, choisissez la ville dans la liste.'));
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('driver');
        $resolver->setAllowedTypes('driver', User::class);
        // Itinéraire de départ, par exemple celui d'un trajet retour ; deux étapes vides sinon.
        $resolver->setDefault('itinerary', null);
        $resolver->setAllowedTypes('itinerary', ['null', 'array']);
    }

    private static function checkCapacity(FormInterface $form): void
    {
        $vehicle = $form->get('vehicle')->getData();
        $capacity = $form->get('seatCapacity')->getData();

        if ($vehicle instanceof Vehicle && \is_int($capacity) && $capacity > $vehicle->getPassengerSeats()) {
            $form->get('seatCapacity')->addError(new FormError(\sprintf('Ce véhicule ne propose que %d places passagers.', $vehicle->getPassengerSeats())));
        }
    }

    private static function checkItinerary(FormInterface $itinerary): void
    {
        $stops = array_values(iterator_to_array($itinerary));
        $count = \count($stops);

        if ($count < 2) {
            $itinerary->addError(new FormError('Indiquez au moins deux étapes : le départ et l\'arrivée.'));

            return;
        }

        $previousDeparture = null;

        foreach ($stops as $index => $stop) {
            $line = $stop->getData();

            if (!$line instanceof ItineraryLine) {
                continue;
            }

            $label = 'Étape '.($index + 1).' : ';
            $first = 0 === $index;
            $last = $index === $count - 1;
            $error = static fn (string $field, string $message) => $stop->get($field)->addError(new FormError($label.$message));

            if ($first && null !== $line->arrivalAt) {
                $error('arrivalAt', 'le départ n\'a pas d\'heure d\'arrivée, laissez ce champ vide.');
            }

            if (!$first && null === $line->arrivalAt) {
                $error('arrivalAt', 'indiquez l\'heure d\'arrivée.');
            }

            if ($last && null !== $line->departureAt) {
                $error('departureAt', 'l\'arrivée n\'a pas d\'heure de départ, laissez ce champ vide.');
            }

            if (!$last && null === $line->departureAt) {
                $error('departureAt', 'indiquez l\'heure de départ.');
            }

            if (null !== $line->arrivalAt && null !== $line->departureAt && $line->departureAt < $line->arrivalAt) {
                $error('departureAt', 'le départ ne peut pas précéder l\'arrivée.');
            }

            if (null !== $line->arrivalAt && null !== $previousDeparture && $line->arrivalAt <= $previousDeparture) {
                $error('arrivalAt', 'l\'arrivée doit suivre le départ de l\'étape précédente.');
            }

            if (!$last && (null === $line->distanceToNextMeters || $line->distanceToNextMeters <= 0)) {
                $error('distanceToNextMeters', 'indiquez la distance jusqu\'à l\'étape suivante, en mètres.');
            }

            $previousDeparture = $line->departureAt ?? $previousDeparture;
        }
    }
}
