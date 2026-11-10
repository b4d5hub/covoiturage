<?php

namespace App\Controller;

use App\Form\TripSearchType;
use App\Repository\BookingRepository;
use App\Repository\TripRepository;
use App\Service\PortionPricing;
use App\Time\MoroccoTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, TripRepository $trips, BookingRepository $bookings, PortionPricing $pricing): Response
    {
        $form = $this->createForm(TripSearchType::class);
        $form->handleRequest($request);
        $now = new \DateTimeImmutable();
        $results = null;

        if ($form->isSubmitted() && $form->isValid()) {
            ['from' => $from, 'to' => $to, 'date' => $date] = $form->getData();

            if ($from === $to) {
                $form->get('to')->addError(new FormError('Choisissez une ville d\'arrivée différente de la ville de départ.'));
            } else {
                // La date saisie est un jour au Maroc, converti en intervalle UTC.
                $start = MoroccoTime::toUtc($date.' 00:00');
                $end = MoroccoTime::toUtc((new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d').' 00:00');

                $results = array_map(static fn (array $row): array => $row + [
                    'seatPrice' => $pricing->seatPriceCents($row['trip'], $row['boarding'], $row['alighting']),
                    'available' => $bookings->availableSeats($row['trip'], $row['boarding'], $row['alighting']),
                ], $trips->search($from, $to, $start, $end, $now));
            }
        }

        return $this->render('home/index.html.twig', [
            'form' => $form,
            'results' => $results,
            'upcoming' => null === $results ? $trips->findUpcoming($now, 6) : [],
        ]);
    }
}
