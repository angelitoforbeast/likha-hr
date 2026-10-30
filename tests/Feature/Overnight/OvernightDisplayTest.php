<?php

namespace Tests\Feature\Overnight;

use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-009.9: the calendar cell and the attendance viewer show a time out after midnight as
 * "01:11 (next day)". The value the edit forms send back stays the time alone.
 */
class OvernightDisplayTest extends OvernightTestCase
{
    #[TestDox('S-009.9 the calendar cell shows 01:11 AM (next day); its raw value stays 01:11:00')]
    public function test_s_009_9_calendar_cell(): void
    {
        $this->liveDayPunches($this->w);
        $this->recompute(self::D, self::D1);

        $cell = $this->cell($this->calendar($this->c), $this->w, self::D);

        $this->assertStringContainsString('data-time-out-display="01:11 AM (next day)"', $cell);
        $this->assertStringContainsString('data-time-out="01:11:00"', $cell);
        $this->assertStringContainsString('data-time-in-display="11:12 AM"', $cell);
    }

    #[TestDox('S-009.9 the attendance viewer shows 01:11 (next day) for the time out and plain times for the rest')]
    public function test_s_009_9_attendance_viewer(): void
    {
        $this->liveDayPunches($this->w);
        $this->recompute(self::D, self::D1);

        $html = $this->actingAs($this->c)
            ->get('/attendance?start_date=' . self::D . '&end_date=' . self::D1)
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression('/data-field="time_out"\s+data-value="01:11"\s*>\s*01:11 \(next day\)/', $html);
        $this->assertMatchesRegularExpression('/data-field="time_in"\s+data-value="11:12"\s*>\s*11:12\s/', $html);
    }
}
