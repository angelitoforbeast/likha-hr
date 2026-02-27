<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'name', 'type', 'recurring', 'remarks'];

    protected $casts = [
        'date' => 'date',
        'recurring' => 'boolean',
    ];

    const TYPE_REGULAR = 'regular';
    const TYPE_SPECIAL = 'special';

    /**
     * Check if a given date is a holiday.
     */
    public static function isHoliday(string $date): bool
    {
        return self::getHolidayForDate($date) !== null;
    }

    /**
     * Get the holiday record for a given date (checks exact date + recurring).
     */
    public static function getHolidayForDate(string $date): ?self
    {
        $carbonDate = Carbon::parse($date);

        // Check exact date match
        $holiday = self::whereDate('date', $carbonDate->toDateString())->first();
        if ($holiday) {
            return $holiday;
        }

        // Check recurring holidays (same month-day, any year)
        return self::where('recurring', true)
            ->whereMonth('date', $carbonDate->month)
            ->whereDay('date', $carbonDate->day)
            ->first();
    }

    /**
     * Get all holidays within a date range.
     */
    public static function getHolidaysInRange(string $startDate, string $endDate): \Illuminate\Support\Collection
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Get exact date matches
        $holidays = self::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();

        // Get recurring holidays that fall within the range
        $recurringHolidays = self::where('recurring', true)->get();
        foreach ($recurringHolidays as $rh) {
            // Build the date for the current year range
            $period = \Carbon\CarbonPeriod::create($start, $end);
            foreach ($period as $day) {
                if ($day->month === $rh->date->month && $day->day === $rh->date->day) {
                    // Check if we already have this date
                    if (!$holidays->contains(fn($h) => $h->date->toDateString() === $day->toDateString())) {
                        // Create a virtual holiday object for display
                        $virtual = clone $rh;
                        $virtual->date = $day->copy();
                        $holidays->push($virtual);
                    }
                }
            }
        }

        return $holidays->sortBy(fn($h) => $h->date->toDateString())->values();
    }

    /**
     * Count holidays in a date range.
     */
    public static function countHolidaysInRange(string $startDate, string $endDate): int
    {
        return self::getHolidaysInRange($startDate, $endDate)->count();
    }

    /**
     * Get type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_REGULAR => 'Regular Holiday',
            self::TYPE_SPECIAL => 'Special Non-Working Day',
            default => $this->type,
        };
    }
}
