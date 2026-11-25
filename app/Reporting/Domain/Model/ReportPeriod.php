<?php

namespace App\Reporting\Domain\Model;

use App\Reporting\Domain\Exception\InvalidReportPeriod;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A reporting window in the store's timezone: `start` inclusive, `end` exclusive, both at the store's local midnight
 * (or hour for a single day), so every report slices time identically. Presets that reach the present (today, last N
 * days, this month, this year) end at the end of the current day. `previous()` is the equivalent comparison window:
 * month-to-date is compared with the same number of days of the previous month, year-to-date with the same span of the
 * previous year, anything else with the window of equal length immediately before it.
 */
final readonly class ReportPeriod
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'this_year', 'custom'];

    /** Longest custom range, so a series and its buckets stay bounded. */
    public const MAX_DAYS = 1830;

    private function __construct(public string $preset, public DateTimeImmutable $start, public DateTimeImmutable $end) {}

    public static function preset(string $preset, DateTimeImmutable $now): self
    {
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');

        return match ($preset) {
            'today' => new self($preset, $today, $tomorrow),
            'yesterday' => new self($preset, $today->modify('-1 day'), $today),
            'last_7_days' => new self($preset, $today->modify('-6 days'), $tomorrow),
            'last_30_days' => new self($preset, $today->modify('-29 days'), $tomorrow),
            'this_month' => new self($preset, $today->modify('first day of this month'), $tomorrow),
            'previous_month' => new self($preset, $today->modify('first day of previous month'), $today->modify('first day of this month')),
            'this_year' => new self($preset, $today->setDate((int) $today->format('Y'), 1, 1), $tomorrow),
            default => throw new InvalidReportPeriod(InvalidReportPeriod::PRESET),
        };
    }

    /** @param string $from First day (Y-m-d); @param string $to Last day included (Y-m-d) */
    public static function custom(string $from, string $to, DateTimeZone $timezone): self
    {
        $start = self::day($from, $timezone);
        $last = self::day($to, $timezone);
        if ($last < $start) {
            throw new InvalidReportPeriod(InvalidReportPeriod::ORDER);
        }
        $end = $last->modify('+1 day');
        if ((int) $start->diff($end)->days > self::MAX_DAYS) {
            throw new InvalidReportPeriod(InvalidReportPeriod::TOO_LONG);
        }

        return new self('custom', $start, $end);
    }

    private static function day(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 2000) {
            throw new InvalidReportPeriod(InvalidReportPeriod::DATE);
        }

        return $date;
    }

    /** The first instant of the window as an unambiguous UTC timestamp, for comparing with stored timestamps. */
    public function startUtc(): string
    {
        return $this->start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s').'+00';
    }

    /** The first instant after the window as a UTC timestamp (the end is exclusive). */
    public function endUtc(): string
    {
        return $this->end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s').'+00';
    }

    /** Number of calendar days the window covers. */
    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days;
    }

    /** The comparison window (see the class description). */
    public function previous(): self
    {
        $days = $this->days();

        return match ($this->preset) {
            'this_month' => $this->sameSpan($this->start->modify('first day of previous month'), $days, $this->start),
            'this_year' => $this->sameSpan($this->start->modify('-1 year'), $days, $this->start),
            'previous_month' => new self($this->preset, $this->start->modify('first day of previous month'), $this->start),
            default => new self($this->preset, $this->start->modify('-'.$days.' days'), $this->start),
        };
    }

    private function sameSpan(DateTimeImmutable $start, int $days, DateTimeImmutable $limit): self
    {
        $end = $start->modify('+'.$days.' days');

        return new self($this->preset, $start, $end > $limit ? $limit : $end);
    }

    /** hour (a day), day (up to about two months), week (up to about half a year) or month. */
    public function granularity(): string
    {
        $days = $this->days();

        return match (true) {
            $days <= 2 => 'hour',
            $days <= 62 => 'day',
            $days <= 200 => 'week',
            default => 'month',
        };
    }

    /**
     * Start of every bucket in the window, aligned like PostgreSQL's date_trunc (weeks start on Monday).
     *
     * @return list<DateTimeImmutable>
     */
    public function buckets(): array
    {
        $unit = $this->granularity();
        $at = match ($unit) {
            'hour' => $this->start,
            'day' => $this->start,
            'week' => $this->start->modify('monday this week'),
            default => $this->start->modify('first day of this month'),
        };
        $step = match ($unit) {
            'hour' => '+1 hour',
            'day' => '+1 day',
            'week' => '+1 week',
            default => '+1 month',
        };
        $buckets = [];
        while ($at < $this->end) {
            $buckets[] = $at;
            $at = $at->modify($step);
        }

        return $buckets;
    }
}
