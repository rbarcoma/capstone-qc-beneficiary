<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Route-group role gate: `->middleware('role:admin,energy_manager')`.
     *
     * Server-enforced authorization — never rely on the frontend hiding
     * controls (docs/ROLES_AND_PERMISSIONS.md §3).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403, 'Not authenticated.');
        }

        $allowed = array_map(
            fn (string $role) => UserRole::from(trim($role)),
            $roles,
        );

        if (! $user->hasRole(...$allowed)) {
            if ($request->routeIs('data-management.*', 'monthly-records.*', 'verification-queue', 'preprocessing*', 'datasets.*', 'forecasting.*', 'recommendations.*')) {
                AuditLog::create(['actor_id' => $user->id, 'event' => 'authorization.denied', 'reason' => $request->method().' '.$request->route()?->getName()]);
            }
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
