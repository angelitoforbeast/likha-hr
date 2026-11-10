<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The biometrics feed is called by the office PC agent, not a signed-in user: the agent sends
 * the shared key in X-BIOMETRICS-FEED-KEY, and only there (never the query or the body). No key
 * configured means the endpoints are off. A refusal is an empty 401 that says nothing about why,
 * as for the staff schedule feed, so a caller cannot tell a wrong key from a closed feed.
 */
class BiometricsFeedKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.biometrics_feed.key');
        $given = $request->header('X-BIOMETRICS-FEED-KEY');

        if ($expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            return response('', 401);
        }

        return $next($request);
    }
}
