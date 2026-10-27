<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $isAdmin = (bool) $session->get('is_admin', false);
        $isStoreMode = (bool) $session->get('store_mode', false);
        abort_unless($isAdmin || $isStoreMode, 403);

        $lastActivity = (int) $session->get('staff_last_activity', 0);

        if ($lastActivity > 0 && $lastActivity < now()->subMinutes(30)->timestamp) {
            $session->forget(['is_admin', 'store_mode', 'staff_last_activity']);
            $session->regenerate();

            abort(403, 'Your staff session expired. Re-enter store mode to continue.');
        }

        $session->put('staff_last_activity', now()->timestamp);

        return $next($request);
    }
}
