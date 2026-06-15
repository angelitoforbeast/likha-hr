<?php

namespace App\Http\Middleware;

use App\Models\FeaturePermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class NavPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        // CEO always allowed (override)
        if ($user->role === 'ceo') {
            return $next($request);
        }

        if (!FeaturePermission::canView($user->role, $featureKey)) {
            abort(403, 'You do not have access to this page.');
        }

        return $next($request);
    }
}
