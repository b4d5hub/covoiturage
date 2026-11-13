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
        // « Rabat, 09:30 » ; la date n'est ajoutée que si l'étape est un autre jour que le départ.
        $firstDay = MoroccoTime::toLocal($stops[0]->getDepartureAt())->format('Y-m-d');
        $label = static function (TripStop $stop, bool $departure) use ($firstDay): string {
            $time = MoroccoTime::toLocal($departure ? $stop->getDepartureAt() : $stop->getArrivalAt());

            return $stop->getCity().', '.($time->format('Y-m-d') === $firstDay ? $time->format('H:i') : $time->format('d/m à H:i'));
        };

        $builder
            ->add('boarding', ChoiceType::class, [
                'label' => 'Je monte à',
                'choices' => \array_slice($stops, 0, -1),
                'choice_value' => static fn (?TripStop $stop) => $stop?->getId(),
                'choice_label' => static fn (TripStop $stop) => $label($stop, true),
            ])
            ->add('alighting', ChoiceType::class, [
                'label' => 'Je descends à',
                'choices' => \array_slice($stops, 1),
                'choice_value' => static fn (?TripStop $stop) => $stop?->getId(),
                'choice_label' => static fn (TripStop $stop) => $label($stop, false),
            ])
            ->add('seats', IntegerType::class, [
                'label' => 'Nombre de places',
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
