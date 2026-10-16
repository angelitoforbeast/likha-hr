<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff schedule feed is read by a machine, not a signed-in user: the caller sends
 * the shared key in X-SCHEDULE-FEED-KEY. No key configured means the feed is off. A
 * refusal says nothing about why, so a caller cannot tell a wrong key from a closed feed.
 */
class ScheduleFeedKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.schedule_feed.key');
        $given = $request->header('X-SCHEDULE-FEED-KEY');

        if ($expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            return response('', 401);
        }

        return $next($request);
    }
}
