<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Reads a restaurant's weekly opening intervals. Times are wall-clock "HH:MM"
 * in the business timezone; a closing time at or before the opening time
 * means the shift ends after midnight, on the next day.
 */
final class OpeningHours
{
    /**
     * @param  iterable<array{weekday: int, opens_at: string, closes_at: string}>  $intervals
     * @param  CarbonInterface  $now  already in the business timezone
     */
    public static function isOpenAt(iterable $intervals, CarbonInterface $now): bool
    {
        $today = $now->dayOfWeek;
        $yesterday = ($today + 6) % 7;
        $time = $now->format('H:i');

        foreach ($intervals as $interval) {
            $opens = substr($interval['opens_at'], 0, 5);
            $closes = substr($interval['closes_at'], 0, 5);
            $overnight = $closes <= $opens;

            // A shift that started today and is still running.
            if ($interval['weekday'] === $today && $time >= $opens && ($overnight || $time < $closes)) {
                return true;
            }

            // Yesterday's overnight shift that hasn't ended yet.
            if ($overnight && $interval['weekday'] === $yesterday && $time < $closes) {
                return true;
            }
        }

        return false;
    }
}
