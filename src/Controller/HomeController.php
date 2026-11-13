<?php

namespace App\Controller;

use App\Entity\City;
use App\Entity\Trip;
use App\Form\TripSearchType;
use App\Geo\GeoServiceUnavailable;
use App\Geo\SearchLocationResolver;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use App\Service\PortionPricing;
use App\Service\TripMapData;
use App\Time\MoroccoTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, TripRepository $trips, BookingRepository $bookings, ReviewRepository $reviews, PortionPricing $pricing, TripMapData $mapData, SearchLocationResolver $resolver, EntityManagerInterface $entityManager): Response
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone(MoroccoTime::ZONE)))->format('Y-m-d');
        $form = $this->createForm(TripSearchType::class, null, ['min_date' => $today]);
        $form->handleRequest($request);
        $now = new \DateTimeImmutable();
        $results = null;
        $otherDays = [];

        $locations = [];

        if ($form->isSubmitted() && $form->isValid()) {
            ['from' => $fromText, 'to' => $toText, 'date' => $date] = $form->getData();

            if ($date < $today) {
                $form->get('date')->addError(new FormError('Choisissez une date à partir d\'aujourd\'hui.'));
            }

            foreach (['from' => $fromText, 'to' => $toText] as $field => $text) {
                try {
                    $locations[$field] = $resolver->resolve($text);

                    if (null === $locations[$field]) {
                        $form->get($field)->addError(new FormError('Lieu introuvable au Maroc : vérifiez l\'orthographe ou indiquez une ville.'));
                    }
                } catch (GeoServiceUnavailable) {
                    $locations[$field] = null;
                    $form->get($field)->addError(new FormError('La recherche de lieux est momentanément indisponible : indiquez le nom d\'une ville.'));
                }
            }

            $from = $locations['from'];
            $to = $locations['to'];

            if (null !== $from && null !== $to && ((null !== $from->city && $from->city === $to->city) || (null !== $from->point && $from->point == $to->point))) {
                $form->get('to')->addError(new FormError('Choisissez une arrivée dans une autre ville que le départ.'));
            }

            if ($form->isValid()) {
                // La date saisie est un jour au Maroc, converti en intervalle UTC.
                $start = MoroccoTime::toUtc($date.' 00:00');
                $end = MoroccoTime::toUtc((new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d').' 00:00');

                // La distance au point de départ n'a de sens que si l'étape est hors de la ville recherchée
                // ou si le passager a cherché un lieu précis : pour « Agadir », la gare d'Agadir est à Agadir.
                $fromIsCity = null !== $from->city && $from->label === (string) $from->city;

                $results = array_map(static fn (array $row): array => $row + [
                    'fromLabel' => $from->label,
                    'showBoardingDistance' => !$fromIsCity || $row['boarding']->getCity() !== $from->city,
                    'seatPrice' => $pricing->seatPriceCents($row['trip'], $row['boarding'], $row['alighting']),
                    'available' => $bookings->availableSeats($row['trip'], $row['boarding'], $row['alighting']),
                    'driverRating' => $reviews->ratingOf($row['trip']->getDriver()),
                ], $trips->search($from, $to, $start, $end, $now));

                if ([] === $results) {
                    foreach (['-1 day', '+1 day'] as $shift) {
                        $day = (new \DateTimeImmutable($date))->modify($shift)->format('Y-m-d');

                        if ($day >= $today) {
                            $otherDays[] = ['date' => $day, 'from' => $fromText, 'to' => $toText];
                        }
                    }
                }
            }
        }

        $upcoming = null === $results
            ? array_map(static fn (Trip $trip): array => [
                'trip' => $trip,
                'availableEndToEnd' => $bookings->availableSeats($trip, $trip->getStopAt(0), $trip->getStopAt($trip->getStops()->count() - 1)),
            ], $trips->findUpcoming($now, 6))
            : [];

        // Trajet mis en avant sur la carte de l'accueil : le prochain départ ayant des places
        // du départ à l'arrivée, hors trajets de l'utilisateur connecté.
        $featured = null;

        foreach ($upcoming as $item) {
            if ($item['availableEndToEnd'] > 0 && $item['trip']->getDriver() !== $this->getUser()) {
                $featured = $item;
                break;
            }
        }

        return $this->render('home/index.html.twig', [
            'featured' => $featured,
            'featuredMap' => null === $featured ? null : $mapData->of($featured['trip']),
            'form' => $form,
            'knownCities' => $entityManager->getRepository(City::class)->findBy([], ['name' => 'ASC']),
            'searchFrom' => $locations['from'] ?? null,
            'searchTo' => $locations['to'] ?? null,
            'results' => $results,
            'otherDays' => $otherDays,
            'searchDate' => null === $results ? null : new \DateTimeImmutable($form->get('date')->getData().' 12:00', new \DateTimeZone(MoroccoTime::ZONE)),
            'upcoming' => $upcoming,
        ]);
    }
}
