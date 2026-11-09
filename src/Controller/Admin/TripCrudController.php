<?php

namespace App\Controller\Admin;

use App\Entity\Trip;
use App\Entity\Vehicle;
use App\Exception\DomainRuleException;
use App\Form\ItineraryLineType;
use App\Form\MadAmountType;
use App\Form\Model\ItineraryLine;
use App\Service\TripCapacityManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * L'itinéraire passe par Trip::replaceItinerary() et la capacité d'un trajet
 * enregistré par TripCapacityManager : le back-office applique les mêmes règles.
 */
final class TripCrudController extends AbstractCrudController
{
    private ?int $requestedCapacity = null;

    public function __construct(private readonly TripCapacityManager $capacityManager)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Trip::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Trajet')->setEntityLabelInPlural('Trajets')->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        $trip = $this->getContext()?->getEntity()->getInstance();
        $isEdit = Crud::PAGE_EDIT === $pageName && $trip instanceof Trip;
        $isFrozen = $isEdit && $trip->isItineraryFrozen();

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('routeLabel', 'Trajet')->hideOnForm()->setSortable(false);
        yield Field::new('driver', 'Conducteur')->hideOnForm()->setSortable(false);
        yield DateTimeField::new('departureAt', 'Départ')->hideOnForm()->setSortable(false);

        yield AssociationField::new('vehicle', 'Véhicule')
            ->setFormTypeOptions([
                'disabled' => $isEdit,
                'choice_label' => static fn (Vehicle $vehicle): string => $vehicle.' — '.$vehicle->getOwner().' ('.$vehicle->getPassengerSeats().' places)',
            ])
            ->setHelp($isEdit ? 'Le véhicule d\'un trajet existant ne peut pas changer.' : '')
            ->hideOnIndex();

        if ($isEdit) {
            yield IntegerField::new('newCapacity', 'Places proposées')
                ->onlyOnForms()
                ->setFormTypeOptions([
                    'mapped' => false,
                    'data' => $trip->getSeatCapacity(),
                    'constraints' => [new Assert\NotBlank(), new Assert\Positive()],
                ]);
        } else {
            yield IntegerField::new('seatCapacity', 'Places proposées');
        }

        yield MoneyField::new('fullPriceCents', 'Prix complet')
            ->setCurrency('MAD')
            ->setStoredAsCents()
            ->hideOnForm();
        yield Field::new('fullPriceCents', 'Prix complet (MAD)')
            ->onlyOnForms()
            ->setFormType(MadAmountType::class)
            ->setHelp($isEdit ? 'Un changement de prix ne concerne que les nouvelles réservations.' : '');

        yield TextField::new('statusLabel', 'État')->hideOnForm()->setSortable(false);
        yield BooleanField::new('itineraryFrozen', 'Itinéraire gelé')
            ->hideOnForm()
            ->renderAsSwitch(false)
            ->setSortable(false);

        yield Field::new('itinerarySummary', 'Itinéraire')
            ->onlyOnDetail()
            ->setTemplatePath('admin/field/itinerary.html.twig');
        yield TextField::new('cancellationReason', 'Motif d\'annulation')->onlyOnDetail();
        yield DateTimeField::new('cancelledAt', 'Annulé le')->onlyOnDetail();
        yield DateTimeField::new('completedAt', 'Effectué le')->onlyOnDetail();

        if (Crud::PAGE_NEW === $pageName || $isEdit) {
            yield CollectionField::new('itinerary', 'Itinéraire')
                ->setEntryType(ItineraryLineType::class)
                ->setEntryIsComplex()
                ->renderExpanded()
                ->setEntryToStringMethod(static fn (?ItineraryLine $line): string => null !== $line?->city ? $line->city.' – '.$line->meetingAddress : 'Étape')
                ->allowAdd(!$isFrozen)
                ->allowDelete(!$isFrozen)
                ->setHelp($isFrozen
                    ? 'Itinéraire gelé : pour le changer, le trajet doit être annulé puis publié de nouveau.'
                    : 'Étapes dans l\'ordre du trajet. La première n\'a pas d\'arrivée, la dernière pas de départ.')
                ->setFormTypeOptions([
                    'mapped' => false,
                    'disabled' => $isFrozen,
                    'by_reference' => false,
                    'data' => $isEdit ? ItineraryLine::fromTrip($trip) : [new ItineraryLine(), new ItineraryLine()],
                ]);
        }
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->applyItineraryOnSubmit(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->applyItineraryOnSubmit(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);

        if (null === $this->requestedCapacity || $this->requestedCapacity === $entityInstance->getSeatCapacity()) {
            return;
        }

        try {
            $this->capacityManager->changeCapacity($entityInstance, $this->requestedCapacity);
        } catch (DomainRuleException $exception) {
            $this->addFlash('danger', 'Capacité inchangée : '.$exception->getMessage());
        }
    }

    /**
     * S'exécute avant la validation du formulaire, qui contrôle ainsi le trajet complété.
     */
    private function applyItineraryOnSubmit(FormBuilderInterface $builder): FormBuilderInterface
    {
        return $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $trip = $form->getData();

            if ($form->has('newCapacity')) {
                $capacity = $form->get('newCapacity')->getData();
                $this->requestedCapacity = \is_int($capacity) ? $capacity : null;
            }

            if (!$trip instanceof Trip || $trip->isItineraryFrozen()) {
                return;
            }

            $lines = array_values($form->get('itinerary')->getData() ?? []);

            foreach ($lines as $line) {
                if (!$line instanceof ItineraryLine || !$line->isComplete()) {
                    return;
                }
            }

            $distances = array_map(
                static fn (ItineraryLine $line): int => (int) $line->distanceToNextMeters,
                \array_slice($lines, 0, max(0, \count($lines) - 1)),
            );

            try {
                $trip->replaceItinerary(array_map(static fn (ItineraryLine $line) => $line->toStop(), $lines), $distances);
            } catch (DomainRuleException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }, 1);
    }
}
