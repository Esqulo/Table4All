<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Full replacement of a restaurant's weekly opening hours. Each entry is one
 * interval on a weekday (0 = Sunday); a weekday with no entry is closed.
 */
class OpeningHoursRequest extends FormRequest
{
    public const MAX_INTERVALS_PER_DAY = 3;

    private const MINUTES_PER_DAY = 1440;

    private const MINUTES_PER_WEEK = 10080;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', 'max:'.(7 * self::MAX_INTERVALS_PER_DAY)],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i', 'different:hours.*.opens_at'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $perDay = [];
            $spans = [];

            foreach ($this->rawIntervals() as $i => $interval) {
                $day = (int) $interval['weekday'];
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;

                if ($perDay[$day] > self::MAX_INTERVALS_PER_DAY) {
                    $validator->errors()->add("hours.$i.opens_at", __('establishment.error_hours_per_day', [
                        'max' => self::MAX_INTERVALS_PER_DAY,
                    ]));

                    return;
                }

                $opens = self::minutes($interval['opens_at']);
                $closes = self::minutes($interval['closes_at']);
                $start = $day * self::MINUTES_PER_DAY + $opens;
                // Closing at or before opening runs past midnight into the next day.
                $end = $day * self::MINUTES_PER_DAY + $closes + ($closes <= $opens ? self::MINUTES_PER_DAY : 0);
                $spans[$i] = [$start, $end];
            }

            foreach ($spans as $i => [$aStart, $aEnd]) {
                foreach ($spans as $j => [$bStart, $bEnd]) {
                    if ($j <= $i) {
                        continue;
                    }

                    // Saturday night spills into Sunday morning: compare across the week wrap too.
                    foreach ([0, self::MINUTES_PER_WEEK, -self::MINUTES_PER_WEEK] as $shift) {
                        if ($aStart < $bEnd + $shift && $bStart + $shift < $aEnd) {
                            $validator->errors()->add("hours.$j.opens_at", __('establishment.error_hours_overlap'));

                            return;
                        }
                    }
                }
            }
        }];
    }

    /**
     * The validated intervals, sorted by weekday and opening time.
     *
     * @return list<array{weekday: int, opens_at: string, closes_at: string}>
     */
    public function intervals(): array
    {
        $intervals = array_map(fn (array $h): array => [
            'weekday' => (int) $h['weekday'],
            'opens_at' => $h['opens_at'],
            'closes_at' => $h['closes_at'],
        ], $this->rawIntervals());

        usort($intervals, fn (array $a, array $b): int => [$a['weekday'], $a['opens_at']] <=> [$b['weekday'], $b['opens_at']]);

        return $intervals;
    }

    /** @return list<array{weekday: int|string, opens_at: string, closes_at: string}> */
    private function rawIntervals(): array
    {
        /** @var array<array{weekday: int|string, opens_at: string, closes_at: string}> $hours */
        $hours = $this->input('hours', []);

        return array_values($hours);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
