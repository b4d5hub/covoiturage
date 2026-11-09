<?php

namespace App\Tests\Unit;

use App\Entity\Trip;
use App\Form\MadAmountType;
use App\Service\PortionPricing;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class PortionPricingTest extends TestCase
{
    /** @param list<int> $distances */
    private function trip(int $fullPrice, array $distances): Trip
    {
        $stops = [TestData::stop(null, '2026-12-01 01:00')];

        for ($i = 1; $i < \count($distances); ++$i) {
            $stops[] = TestData::stop(\sprintf('2026-12-01 %02d:00', 1 + 2 * $i), \sprintf('2026-12-01 %02d:30', 1 + 2 * $i));
        }

        $stops[] = TestData::stop('2026-12-01 23:00', null);
        $trip = (new Trip())->setFullPriceCents($fullPrice);
        $trip->replaceItinerary($stops, $distances);

        return $trip;
    }

    public function testExampleOfFortyPercentOfTheDistance(): void
    {
        $trip = $this->trip(20000, [40000, 60000]);

        self::assertSame(8000, (new PortionPricing())->seatPriceCents($trip, $trip->getStopAt(0), $trip->getStopAt(1)));
    }

    public function testFullJourneyCostsExactlyTheFullPrice(): void
    {
        $trip = $this->trip(20000, [33333, 33333, 33334]);

        self::assertSame(20000, (new PortionPricing())->seatPriceCents($trip, $trip->getStopAt(0), $trip->getStopAt(3)));
    }

    public function testRoundsToTheNearestCentime(): void
    {
        $trip = $this->trip(20000, [1000, 1000, 1000]);
        $pricing = new PortionPricing();

        self::assertSame(6667, $pricing->seatPriceCents($trip, $trip->getStopAt(0), $trip->getStopAt(1)));
        self::assertSame(13333, $pricing->seatPriceCents($trip, $trip->getStopAt(0), $trip->getStopAt(2)));
    }

    public function testRoundsHalfCentimeUp(): void
    {
        $trip = $this->trip(101, [1000, 1000]);

        self::assertSame(51, (new PortionPricing())->seatPriceCents($trip, $trip->getStopAt(0), $trip->getStopAt(1)));
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function validAmounts(): iterable
    {
        yield 'entier' => ['200', 20000];
        yield 'virgule' => ['199,99', 19999];
        yield 'point' => ['19.5', 1950];
        yield 'centimes piégeux en flottant' => ['1.15', 115];
        yield 'vide' => ['', null];
    }

    #[DataProvider('validAmounts')]
    public function testAmountIsParsedWithoutFloatErrors(string $input, ?int $cents): void
    {
        $form = Forms::createFormFactory()->create(MadAmountType::class);
        $form->submit($input);

        self::assertTrue($form->isSynchronized());
        self::assertSame($cents, $form->getData());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'lettres' => ['abc'];
        yield 'trois décimales' => ['1,999'];
        yield 'négatif' => ['-5'];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountIsRejected(string $input): void
    {
        $form = Forms::createFormFactory()->create(MadAmountType::class);
        $form->submit($input);

        self::assertFalse($form->isSynchronized());
    }

    public function testAmountIsDisplayedInMad(): void
    {
        $form = Forms::createFormFactory()->create(MadAmountType::class, 19905);

        self::assertSame('199,05', $form->createView()->vars['value']);
    }
}
