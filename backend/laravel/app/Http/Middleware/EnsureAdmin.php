<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (! $session->get('is_admin', false)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Admin sign-in required.'], 401);
            }

            return redirect()->route('admin.login');
        }

        $lastActivity = (int) $session->get('staff_last_activity', 0);

        if ($lastActivity > 0 && $lastActivity < now()->subMinutes(30)->timestamp) {
            $session->forget(['is_admin', 'store_mode', 'staff_last_activity']);
            $session->regenerate();

            return redirect()->route('admin.login')->withErrors(['email' => 'Your admin session expired. Please sign in again.']);
        }

        $session->put('staff_last_activity', now()->timestamp);

        return $next($request);
    }
}
