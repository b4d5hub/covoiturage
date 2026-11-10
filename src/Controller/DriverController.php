<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Form\Model\ItineraryLine;
use App\Form\PublishTripType;
use App\Form\VehicleType;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Service\TripLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Espace conducteur : trajets publiés, passagers, historique, véhicules.
 */
#[IsGranted('ROLE_USER')]
#[Route('/conducteur')]
final class DriverController extends AbstractController
{
    #[Route('', name: 'app_driver')]
    public function index(TripRepository $trips, BookingRepository $bookings, ReviewRepository $reviews): Response
    {
        /** @var User $driver */
        $driver = $this->getUser();
        $now = new \DateTimeImmutable();
        $sections = ['upcoming' => [], 'toClose' => [], 'history' => []];

        foreach ($trips->findForDriver($driver) as $trip) {
            $passengers = $bookings->findConfirmedForTrip($trip);
            $lastArrival = $trip->getStopAt($trip->getStops()->count() - 1)->getArrivalAt();
            $isPublished = TripStatus::Published === $trip->getStatus();
            $reviewable = [];

            if (TripStatus::Completed === $trip->getStatus() && $now <= $trip->getCompletedAt()->modify('+14 days')) {
                foreach ($passengers as $booking) {
                    if (!$reviews->exists($trip, $driver, $booking->getPassenger())) {
                        $reviewable[] = $booking->getPassenger();
                    }
                }
            }

            $item = [
                'trip' => $trip,
                'passengers' => $passengers,
                'occupancy' => $isPublished ? $bookings->occupiedSeatsBySegment($trip) : [],
                'canCancel' => $isPublished && $now < $trip->getDepartureAt(),
                'canComplete' => $isPublished && $now >= $lastArrival,
                'reviewable' => $reviewable,
            ];

            $section = match (true) {
                !$isPublished => 'history',
                $now < $trip->getDepartureAt() => 'upcoming',
                default => 'toClose',
            };
            $sections[$section][] = $item;
        }

        $sections['upcoming'] = array_reverse($sections['upcoming']);

        return $this->render('driver/index.html.twig', $sections + ['hasVehicle' => !$driver->getVehicles()->isEmpty()]);
    }

    #[Route('/trajets/nouveau', name: 'app_driver_publish')]
    public function publish(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        /** @var User $driver */
        $driver = $this->getUser();

        if ($driver->getVehicles()->isEmpty()) {
            $this->addFlash('info', 'Ajoutez d\'abord le véhicule avec lequel vous voyagez.');

            return $this->redirectToRoute('app_driver_vehicles');
        }

        $form = $this->createForm(PublishTripType::class, null, ['driver' => $driver]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            /** @var list<ItineraryLine> $lines */
            $lines = array_values($data['itinerary']);
            $trip = (new Trip())
                ->setVehicle($data['vehicle'])
                ->setSeatCapacity($data['seatCapacity'])
                ->setFullPriceCents($data['fullPriceCents']);

            try {
                $trip->replaceItinerary(
                    array_map(static fn (ItineraryLine $line) => $line->toStop(), $lines),
                    array_map(static fn (ItineraryLine $line) => (int) $line->distanceToNextMeters, \array_slice($lines, 0, max(0, \count($lines) - 1))),
                );

                foreach ($validator->validate($trip) as $violation) {
                    $form->addError(new FormError($violation->getMessage()));
                }

                if (0 === \count($form->getErrors())) {
                    $entityManager->persist($trip);
                    $entityManager->flush();
                    $this->addFlash('success', 'Trajet publié : il apparaît désormais dans les recherches.');

                    return $this->redirectToRoute('app_trip_show', ['id' => $trip->getId()]);
                }
            } catch (DomainRuleException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('driver/publish.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/trajets/{id}/annuler', name: 'app_driver_cancel', methods: ['POST'])]
    public function cancel(Trip $trip, Request $request, TripLifecycleService $lifecycle): Response
    {
        $this->assertDriverAction($trip, $request, 'cancel-trip-');

        try {
            $lifecycle->cancel($trip, $this->getUser(), $request->request->getString('reason'));
            $this->addFlash('success', 'Trajet annulé : les passagers sont remboursés et prévenus.');
        } catch (DomainRuleException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_driver');
    }

    #[Route('/trajets/{id}/terminer', name: 'app_driver_complete', methods: ['POST'])]
    public function complete(Trip $trip, Request $request, TripLifecycleService $lifecycle): Response
    {
        $this->assertDriverAction($trip, $request, 'complete-trip-');

        try {
            $lifecycle->complete($trip, $this->getUser());
            $this->addFlash('success', 'Trajet marqué comme effectué : vous pouvez maintenant laisser un avis à vos passagers.');
        } catch (DomainRuleException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_driver');
    }

    #[Route('/vehicules', name: 'app_driver_vehicles')]
    public function vehicles(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $driver */
        $driver = $this->getUser();
        $vehicle = (new Vehicle())->setOwner($driver);
        $form = $this->createForm(VehicleType::class, $vehicle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($vehicle);
            $entityManager->flush();
            $this->addFlash('success', 'Véhicule ajouté.');

            return $this->redirectToRoute('app_driver_vehicles');
        }

        return $this->render('driver/vehicles.html.twig', ['form' => $form, 'vehicles' => $driver->getVehicles()], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    private function assertDriverAction(Trip $trip, Request $request, string $tokenPrefix): void
    {
        if ($trip->getDriver() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid($tokenPrefix.$trip->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
