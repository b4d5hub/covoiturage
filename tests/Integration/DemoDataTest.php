<?php

namespace App\Tests\Integration;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Repository\BookingRepository;
use App\Service\PortionPricing;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les données de démonstration respectent les règles qu'elles illustrent.
 */
final class DemoDataTest extends DatabaseTestCase
{
    /** @var list<Trip> */
    private array $trips;

    /** @var list<Booking> */
    private array $allBookings;

    protected function setUp(): void
    {
        parent::setUp();

        $tester = new CommandTester((new Application(self::$kernel))->find('doctrine:fixtures:load'));
        $tester->execute([], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $this->trips = $this->em->getRepository(Trip::class)->findAll();
        $this->allBookings = $this->em->getRepository(Booking::class)->findAll();
    }

    public function testContainsEveryExpectedKindOfData(): void
    {
        $statuses = array_map(fn (Trip $trip) => $trip->getStatus(), $this->trips);

        self::assertContains(TripStatus::Published, $statuses);
        self::assertContains(TripStatus::Completed, $statuses);
        self::assertContains(TripStatus::Cancelled, $statuses);
        self::assertGreaterThan(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM review'));
        self::assertGreaterThan(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification'));

        $future = array_filter($this->trips, fn (Trip $t) => TripStatus::Published === $t->getStatus() && $t->getDepartureAt() > new \DateTimeImmutable());
        self::assertGreaterThanOrEqual(3, \count($future));
    }

    public function testOnlyFictionalAccounts(): void
    {
        $emails = $this->connection->fetchFirstColumn('SELECT email FROM app_user');

        foreach ($emails as $email) {
            self::assertStringEndsWith('@covoiturage.test', $email);
        }

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM app_user WHERE JSON_CONTAINS(roles, '\"ROLE_ADMIN\"')"));
    }

    public function testEveryTripAndBookingPassesValidation(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);

        foreach ([...$this->trips, ...$this->allBookings] as $entity) {
            self::assertCount(0, $validator->validate($entity), (string) $validator->validate($entity));
        }
    }

    public function testNoSegmentIsOverbooked(): void
    {
        $bookings = self::getContainer()->get(BookingRepository::class);

        foreach ($this->trips as $trip) {
            self::assertLessThanOrEqual($trip->getSeatCapacity(), $bookings->peakOccupiedSeats($trip), (string) $trip);
        }
    }

    public function testSeatsAreResoldAcrossSegments(): void
    {
        $bookings = self::getContainer()->get(BookingRepository::class);
        $resold = array_filter($this->trips, fn (Trip $trip) => \in_array(
            [1, 2, 3],
            [array_values($bookings->occupiedSeatsBySegment($trip))],
            true,
        ));

        self::assertCount(1, $resold);
    }

    public function testBookingAmountsFollowThePricingRule(): void
    {
        $pricing = new PortionPricing();

        foreach ($this->allBookings as $booking) {
            self::assertSame(
                $pricing->seatPriceCents($booking->getTrip(), $booking->getBoardingStop(), $booking->getAlightingStop()),
                $booking->getSeatPriceCents(),
            );
        }
    }

    public function testEveryBookingHasAMatchingPayment(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM booking b LEFT JOIN payment p ON p.booking_id = b.id
            WHERE p.id IS NULL OR p.amount_cents <> b.total_price_cents
                OR (b.status = 'confirmed') <> (p.status = 'paid')
                OR (p.status = 'refunded') <> (p.refunded_at IS NOT NULL)
            SQL));
    }

    public function testItineraryIsFrozenExactlyWhenBookingsExist(): void
    {
        foreach ($this->trips as $trip) {
            self::assertSame(!$trip->getBookings()->isEmpty(), $trip->isItineraryFrozen(), (string) $trip);
        }
    }

    public function testNoSelfBookingAndOneActiveBookingPerPassenger(): void
    {
        $active = [];

        foreach ($this->allBookings as $booking) {
            self::assertNotSame($booking->getTrip()->getDriver(), $booking->getPassenger());

            if (BookingStatus::Confirmed === $booking->getStatus()) {
                $key = $booking->getTrip()->getId().'-'.$booking->getPassenger()->getId();
                self::assertArrayNotHasKey($key, $active);
                $active[$key] = true;
            }
        }
    }

    public function testCancelledTripsHaveAReasonCancelledBookingsAndNotifications(): void
    {
        $count = 0;

        foreach ($this->trips as $trip) {
            if (TripStatus::Cancelled !== $trip->getStatus()) {
                continue;
            }

            ++$count;
            self::assertNotEmpty($trip->getCancellationReason());

            foreach ($trip->getBookings() as $booking) {
                self::assertSame(BookingStatus::Cancelled, $booking->getStatus());
                self::assertSame(1, (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM notification WHERE recipient_id = ? AND message LIKE ?',
                    [$booking->getPassenger()->getId(), '%'.$trip->getCancellationReason().'%'],
                ));
            }
        }

        self::assertSame(1, $count);
    }

    public function testReviewsRespectTheRules(): void
    {
        foreach ($this->em->getRepository(\App\Entity\Review::class)->findAll() as $review) {
            $trip = $review->getTrip();
            self::assertSame(TripStatus::Completed, $trip->getStatus());
            self::assertGreaterThanOrEqual($trip->getCompletedAt()->modify('-1 day'), $review->getCreatedAt());
            self::assertLessThanOrEqual($trip->getCompletedAt()->modify('+14 days'), $review->getCreatedAt());
            self::assertTrue($review->getAuthor() === $trip->getDriver() || $review->getTarget() === $trip->getDriver());

            $passenger = $review->getAuthor() === $trip->getDriver() ? $review->getTarget() : $review->getAuthor();
            self::assertTrue($trip->getBookings()->exists(fn ($k, Booking $b) => $b->getPassenger() === $passenger && BookingStatus::Confirmed === $b->getStatus()));
        }
    }
}
