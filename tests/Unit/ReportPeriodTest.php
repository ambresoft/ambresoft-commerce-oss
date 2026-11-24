<?php

namespace Tests\Unit;

use App\Reporting\Domain\Exception\InvalidReportPeriod;
use App\Reporting\Domain\Model\ReportPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ReportPeriodTest extends TestCase
{
    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('Europe/Athens'));
    }

    private function show(ReportPeriod $p): array
    {
        return [$p->start->format('Y-m-d H:i'), $p->end->format('Y-m-d H:i')];
    }

    public function test_presets_follow_the_store_timezone_and_end_at_the_end_of_today(): void
    {
        $now = $this->at('2026-10-17 01:30');
        self::assertSame(['2026-10-17 00:00', '2026-10-18 00:00'], $this->show(ReportPeriod::preset('today', $now)));
        self::assertSame(['2026-10-16 00:00', '2026-10-17 00:00'], $this->show(ReportPeriod::preset('yesterday', $now)));
        self::assertSame(['2026-10-11 00:00', '2026-10-18 00:00'], $this->show(ReportPeriod::preset('last_7_days', $now)));
        self::assertSame(['2026-09-18 00:00', '2026-10-18 00:00'], $this->show(ReportPeriod::preset('last_30_days', $now)));
        self::assertSame(['2026-10-01 00:00', '2026-10-18 00:00'], $this->show(ReportPeriod::preset('this_month', $now)));
        self::assertSame(['2026-09-01 00:00', '2026-10-01 00:00'], $this->show(ReportPeriod::preset('previous_month', $now)));
        self::assertSame(['2026-01-01 00:00', '2026-10-18 00:00'], $this->show(ReportPeriod::preset('this_year', $now)));
        // 01:30 in Athens is still the previous day in UTC: the boundary is the store's, not UTC's.
        self::assertSame('2026-10-16 21:00', ReportPeriod::preset('today', $now)->start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function test_previous_periods_are_the_equivalent_comparison_windows(): void
    {
        $now = $this->at('2026-10-17 12:00');
        self::assertSame(['2026-09-01 00:00', '2026-09-18 00:00'], $this->show(ReportPeriod::preset('this_month', $now)->previous()), 'Month to date against the same days of the previous month');
        self::assertSame(['2026-08-01 00:00', '2026-09-01 00:00'], $this->show(ReportPeriod::preset('previous_month', $now)->previous()));
        self::assertSame(['2025-01-01 00:00', '2025-10-18 00:00'], $this->show(ReportPeriod::preset('this_year', $now)->previous()));
        self::assertSame(['2026-10-04 00:00', '2026-10-11 00:00'], $this->show(ReportPeriod::preset('last_7_days', $now)->previous()));
        self::assertSame(['2026-10-15 00:00', '2026-10-16 00:00'], $this->show(ReportPeriod::preset('yesterday', $now)->previous()));
        $custom = ReportPeriod::custom('2026-03-10', '2026-03-19', new DateTimeZone('Europe/Athens'));
        self::assertSame(['2026-02-28 00:00', '2026-03-10 00:00'], $this->show($custom->previous()));
        // A month-to-date window on the 31st never reaches into the current month of the comparison.
        $late = ReportPeriod::preset('this_month', $this->at('2026-03-31 09:00'))->previous();
        self::assertSame(['2026-02-01 00:00', '2026-03-01 00:00'], $this->show($late));
    }

    public function test_custom_ranges_are_validated(): void
    {
        $zone = new DateTimeZone('Europe/Athens');
        $range = ReportPeriod::custom('2026-10-01', '2026-10-01', $zone);
        self::assertSame(['2026-10-01 00:00', '2026-10-02 00:00'], $this->show($range));
        self::assertSame(1, $range->days());
        foreach ([['2026-10-05', '2026-10-01', InvalidReportPeriod::ORDER], ['2026-02-30', '2026-03-01', InvalidReportPeriod::DATE], ['nope', '2026-03-01', InvalidReportPeriod::DATE], ['1999-01-01', '2026-03-01', InvalidReportPeriod::DATE], ['2020-01-01', '2026-03-01', InvalidReportPeriod::TOO_LONG]] as [$from, $to, $reason]) {
            try {
                ReportPeriod::custom($from, $to, $zone);
                self::fail("Accepted $from – $to");
            } catch (InvalidReportPeriod $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        $this->expectException(InvalidReportPeriod::class);
        ReportPeriod::preset('last_year', $this->at('2026-10-17 12:00'));
    }

    public function test_granularity_and_buckets_follow_the_window_length(): void
    {
        $zone = new DateTimeZone('Europe/Athens');
        $now = $this->at('2026-10-17 12:00');
        self::assertSame('hour', ReportPeriod::preset('today', $now)->granularity());
        self::assertCount(24, ReportPeriod::preset('today', $now)->buckets());
        self::assertSame('day', ReportPeriod::preset('last_30_days', $now)->granularity());
        self::assertCount(30, ReportPeriod::preset('last_30_days', $now)->buckets());
        $half = ReportPeriod::custom('2026-01-01', '2026-06-30', $zone);
        self::assertSame('week', $half->granularity());
        self::assertSame('2025-12-29', $half->buckets()[0]->format('Y-m-d'), 'Weeks start on Monday, like date_trunc');
        $year = ReportPeriod::custom('2025-03-15', '2026-03-14', $zone);
        self::assertSame('month', $year->granularity());
        self::assertCount(13, $year->buckets());
        self::assertSame('2025-03-01', $year->buckets()[0]->format('Y-m-d'));
        // A daylight-saving day has 23 hours and 23 buckets of local time.
        $dst = ReportPeriod::custom('2026-03-29', '2026-03-29', $zone);
        self::assertSame(1, $dst->days());
        self::assertCount(23, $dst->buckets());
    }
}
