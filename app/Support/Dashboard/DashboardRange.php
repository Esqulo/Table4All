<?php

namespace App\Support\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period a dashboard reports on, as whole local days of the business
 * timezone, plus the equally long period right before it (for comparisons).
 *
 * Accepts `period` = today | 7 | 30 | 90 | custom (with `from`/`to` as Y-m-d).
 * Anything malformed falls back to the last 30 days; a custom range ending in
 * the future is cut at today, and one longer than MAX_DAYS keeps its last
 * MAX_DAYS days.
 */
final readonly class DashboardRange
{
    public const MAX_DAYS = 90;

    public const PRESETS = ['today', '7', '30', '90'];

    private function __construct(
        public string $period,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
    ) {}

    public static function fromRequest(Request $request, string $timezone): self
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $period = $request->query('period', '30');
        $period = is_string($period) ? $period : '30';

        if ($period === 'custom') {
            $from = self::parseDate($request->query('from'), $timezone);
            $to = self::parseDate($request->query('to'), $timezone);

            if ($from !== null && $to !== null && $from->lte($to) && $from->lte($today)) {
                $to = $to->min($today);
                if (round($from->diffInDays($to)) >= self::MAX_DAYS) {
                    $from = $to->subDays(self::MAX_DAYS - 1);
                }

                return new self('custom', $from, $to, $timezone);
            }

            $period = '30';
        }

        if (! in_array($period, self::PRESETS, true)) {
            $period = '30';
        }

        $days = $period === 'today' ? 1 : (int) $period;

        return new self($period, $today->subDays($days - 1), $today, $timezone);
    }

    private static function parseDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        // createFromFormat rolls 2026-02-31 over to March: reject instead.
        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Number of local days covered (1 for "today"). */
    public function days(): int
    {
        return (int) round($this->from->diffInDays($this->to)) + 1;
    }

    /** A single day is charted hour by hour; longer ranges day by day. */
    public function hourly(): bool
    {
        return $this->days() === 1;
    }

    /** The same number of days, ending the day before this range starts. */
    public function previous(): self
    {
        $to = $this->from->subDay();

        return new self($this->period, $to->subDays($this->days() - 1), $to, $this->timezone);
    }

    /** Inclusive UTC start, to compare with timestamps stored in UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    /** Exclusive UTC end (the start of the day after `to`). */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->addDay()->utc();
    }
}
