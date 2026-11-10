<?php

namespace Tests\Feature\Biometrics;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-012.2 Only the biometrics key opens the endpoints.
 */
class FeedKeyTest extends BiometricsTestCase
{
    private const ROUTES = [
        ['POST', '/api/biometrics/punches'],
        ['POST', '/api/biometrics/heartbeat'],
        ['POST', '/api/biometrics/users'],
        ['GET',  '/api/biometrics/status'],
    ];

    private function body(): string
    {
        return json_encode([
            'agent_id' => 'office-pc', 'attlog' => "1\t2026-11-02 08:00:00",
            'device_reachable' => true, 'device_record_count' => 5,
            'users' => [['user_id' => '77', 'name' => 'Someone']],
        ]);
    }

    private function callWith(string $method, string $uri, array $server, string $query = '', ?string $body = null): \Illuminate\Testing\TestResponse
    {
        return $this->call($method, $uri . ($query === '' ? '' : '?' . $query), [], [], [],
            array_merge(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $server),
            $body ?? $this->body());
    }

    #[TestDox('S-012.2 no key, a wrong key, other case, a trailing space, the key in the query or body: empty 401, nothing written')]
    public function test_s_012_2_wrong_keys_get_an_empty_401_and_write_nothing(): void
    {
        $before = $this->writeCounts();
        $variants = [
            'no key'         => [[], ''],
            'wrong key'      => [['HTTP_X_BIOMETRICS_FEED_KEY' => 'test-only-biometrics-key-0123456789abcdeX'], ''],
            'empty key'      => [['HTTP_X_BIOMETRICS_FEED_KEY' => ''], ''],
            'upper case'     => [['HTTP_X_BIOMETRICS_FEED_KEY' => strtoupper(self::KEY)], ''],
            'trailing space' => [['HTTP_X_BIOMETRICS_FEED_KEY' => self::KEY . ' '], ''],
            'leading space'  => [['HTTP_X_BIOMETRICS_FEED_KEY' => ' ' . self::KEY], ''],
            'in the query'   => [[], 'key=' . self::KEY . '&X-BIOMETRICS-FEED-KEY=' . self::KEY],
            'schedule key'   => [['HTTP_X_SCHEDULE_FEED_KEY' => self::KEY], ''],
        ];

        foreach (self::ROUTES as [$method, $uri]) {
            foreach ($variants as $name => [$server, $query]) {
                $response = $this->callWith($method, $uri, $server, $query);
                $this->assertSame(401, $response->status(), "{$method} {$uri} with {$name}");
                $this->assertSame('', $response->getContent(), "{$method} {$uri} with {$name}");
            }
            // The key inside the body.
            $response = $this->callWith($method, $uri, [], '', json_encode([
                'key' => self::KEY, 'X-BIOMETRICS-FEED-KEY' => self::KEY, 'attlog' => "1\t2026-11-02 08:00:00",
            ]));
            $this->assertSame(401, $response->status(), "{$method} {$uri} with the key in the body");
            $this->assertSame('', $response->getContent());
        }

        $this->assertSame($before, $this->writeCounts());
        $this->assertNull($this->feedStatus()->json('last_contact_at'), 'a refused call is not a contact');
    }

    #[TestDox('S-012.2 no key configured: every call gets an empty 401')]
    public function test_s_012_2_no_key_configured_means_off(): void
    {
        foreach (['', null] as $configured) {
            config(['services.biometrics_feed.key' => $configured]);
            foreach (self::ROUTES as [$method, $uri]) {
                foreach ([self::KEY, '', null] as $sent) {
                    $server = $sent === null ? [] : ['HTTP_X_BIOMETRICS_FEED_KEY' => $sent];
                    $response = $this->callWith($method, $uri, $server);
                    $this->assertSame(401, $response->status(), "{$method} {$uri}");
                    $this->assertSame('', $response->getContent());
                }
            }
        }
        $this->assertSame(0, \DB::table('attendance_logs')->count());
    }

    #[TestDox('S-012.2 the right key works on all four endpoints')]
    public function test_s_012_2_the_right_key_works(): void
    {
        foreach (self::ROUTES as [$method, $uri]) {
            $response = $this->callWith($method, $uri, ['HTTP_X_BIOMETRICS_FEED_KEY' => self::KEY]);
            $this->assertSame(200, $response->status(), "{$method} {$uri}: " . $response->getContent());
            $this->assertNotNull($response->json('server_time'));
            $this->assertArrayHasKey('last_punch_at', $response->json());
            $this->assertSame([], $response->headers->getCookies(), 'no session cookie');
        }
    }

    #[TestDox('S-012.2 the 301st call in an hour gets 429; wrong keys do not use the bucket; the next hour works again')]
    public function test_s_012_2_rate_limit_is_300_an_hour_per_key(): void
    {
        $start = Carbon::parse('2026-11-10 08:00:00', 'Asia/Manila');
        for ($i = 0; $i < 300; $i++) {
            // Spread over the hour so the general api limit of 60 a minute is never the one hit.
            Carbon::setTestNow($start->copy()->addSeconds($i * 10));
            $this->feedStatus()->assertStatus(200);
        }
        Carbon::setTestNow($start->copy()->addMinutes(55));
        $this->feedStatus()->assertStatus(429);
        $this->heartbeat()->assertStatus(429);

        Carbon::setTestNow($start->copy()->addMinutes(61));
        $this->feedStatus('wrong')->assertStatus(401);
        $this->feedStatus()->assertStatus(200);
    }

    #[TestDox('S-012.2 the wrong method gets 405, never 500')]
    public function test_s_012_2_wrong_method_is_405(): void
    {
        foreach (['/api/biometrics/punches', '/api/biometrics/heartbeat', '/api/biometrics/users'] as $uri) {
            $this->callWith('GET', $uri, ['HTTP_X_BIOMETRICS_FEED_KEY' => self::KEY])->assertStatus(405);
            $this->callWith('GET', $uri, [])->assertStatus(405);
        }
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $this->callWith($method, '/api/biometrics/status', ['HTTP_X_BIOMETRICS_FEED_KEY' => self::KEY])->assertStatus(405);
        }
    }

    #[TestDox('S-012.2 the staff schedule key does not open the biometrics endpoints, and the biometrics key does not open the schedule feed')]
    public function test_s_012_2_keys_are_separate(): void
    {
        config(['services.schedule_feed.key' => 'test-only-schedule-key-0123456789abcdef']);
        $this->callWith('GET', '/api/biometrics/status', ['HTTP_X_BIOMETRICS_FEED_KEY' => 'test-only-schedule-key-0123456789abcdef'])
            ->assertStatus(401);
        $this->call('GET', '/api/staff-schedule', [], [], [], ['HTTP_X_SCHEDULE_FEED_KEY' => self::KEY])->assertStatus(401);
    }
}
