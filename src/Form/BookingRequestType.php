<?php

namespace App\Form;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Time\MoroccoTime;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class BookingRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Trip $trip */
        $trip = $options['trip'];
        $stops = $trip->getStops()->toArray();
        usort($stops, static fn (TripStop $a, TripStop $b) => $a->getPosition() <=> $b->getPosition());
        $label = static function (TripStop $stop, bool $departure): string {
            $time = $departure ? $stop->getDepartureAt() : $stop->getArrivalAt();

            return $stop->getCity().' – '.MoroccoTime::toLocal($time)->format('d/m H:i');
        };

        $builder
            ->add('boarding', ChoiceType::class, [
                'label' => 'Montée',
                'choices' => \array_slice($stops, 0, -1),
                'choice_value' => static fn (?TripStop $stop) => $stop?->getId(),
                'choice_label' => static fn (TripStop $stop) => $label($stop, true),
            ])
            ->add('alighting', ChoiceType::class, [
                'label' => 'Descente',
                'choices' => \array_slice($stops, 1),
                'choice_value' => static fn (?TripStop $stop) => $stop?->getId(),
                'choice_label' => static fn (TripStop $stop) => $label($stop, false),
            ])
            ->add('seats', IntegerType::class, [
                'label' => 'Places',
                'data' => 1,
                'attr' => ['min' => 1, 'max' => $trip->getSeatCapacity()],
                'constraints' => [new Assert\Range(min: 1, max: $trip->getSeatCapacity())],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('trip');
        $resolver->setAllowedTypes('trip', Trip::class);
    }
}
