<?php

namespace Tests\Feature\ScheduleFeed;

use Carbon\Carbon;

/** Who may call the feed, how often, and which dates it accepts. */
class FeedAccessTest extends ScheduleFeedTestCase
{
    public function test_401_without_the_key_header(): void
    {
        $response = $this->feed('date=' . self::MON, null)->assertStatus(401);
        $this->assertSame('', $response->getContent());
    }

    public function test_401_with_a_wrong_key(): void
    {
        $response = $this->feed('date=' . self::MON, 'test-only-feed-key-0123456789abcdeX')->assertStatus(401);
        $this->assertSame('', $response->getContent());
        $this->feed('date=' . self::MON, '')->assertStatus(401);
    }

    public function test_401_for_every_call_when_no_key_is_configured(): void
    {
        foreach (['', null] as $configured) {
            config(['services.schedule_feed.key' => $configured]);
            $this->assertSame('', $this->feed('date=' . self::MON, self::KEY)->assertStatus(401)->getContent());
            $this->feed('date=' . self::MON, '')->assertStatus(401);
            $this->feed('date=' . self::MON, null)->assertStatus(401);
        }
    }

    public function test_200_with_the_right_key_and_no_session_cookie(): void
    {
        $this->employee('1', 'Employee One');
        $response = $this->feed('date=' . self::MON)->assertStatus(200);

        $this->assertSame(self::MON, $response->json('from'));
        $this->assertSame(self::MON, $response->json('to'));
        $this->assertCount(1, $response->json('days'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_default_date_is_today_in_manila(): void
    {
        // 17:30 UTC on the 15th is already 01:30 on the 16th in Manila.
        Carbon::setTestNow(Carbon::parse('2026-10-15 17:30:00', 'UTC'));
        try {
            $response = $this->feed()->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame('2026-10-16', $response->json('from'));
        $this->assertSame('2026-10-16', $response->json('to'));
        $this->assertSame('2026-10-16T01:30:00+08:00', $response->json('generated_at'));
    }

    public function test_422_for_a_bad_date(): void
    {
        foreach (['date=2026-02-30', 'date=abc', 'date=16-10-2026', 'from=2026-10-05', 'to=2026-10-05',
                  'from=2026-10-09&to=2026-10-05', 'date=2026-10-05&from=2026-10-05&to=2026-10-06', 'date[]=2026-10-05'] as $query) {
            $this->feed($query)->assertStatus(422);
        }
    }

    public function test_422_for_a_range_over_31_days_and_200_for_31(): void
    {
        $this->feed('from=2026-10-01&to=2026-11-01')->assertStatus(422); // 32 days
        $response = $this->feed('from=2026-10-01&to=2026-10-31')->assertStatus(200);
        $this->assertCount(31, $response->json('days'));
    }

    public function test_429_on_the_61st_call_in_an_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 08:00:00'));
        try {
            for ($i = 1; $i <= 60; $i++) {
                $this->feed('date=' . self::MON)->assertStatus(200);
            }
            // Past the minute of the general api limit, still inside the hour.
            Carbon::setTestNow(Carbon::parse('2026-10-16 08:05:00'));
            $this->feed('date=' . self::MON)->assertStatus(429);

            // Refused calls with a wrong key do not use up the bucket of the right key...
            Carbon::setTestNow(Carbon::parse('2026-10-16 09:01:00'));
            $this->feed('date=' . self::MON, 'wrong')->assertStatus(401);
            // ...and the next hour the key works again.
            $this->feed('date=' . self::MON)->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }
    }
}
