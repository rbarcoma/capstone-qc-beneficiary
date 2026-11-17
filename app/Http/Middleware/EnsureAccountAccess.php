<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $signedIn = $request->user();
        if ($signedIn instanceof User) {
            $user = User::find($signedIn->id);
            $stamp = $request->session()->get('auth.access_version');
            // Preserve pre-extension sessions only at the untouched baseline.
            $validStamp = $user && ($stamp === null ? $user->access_version === 1 : (int) $stamp === $user->access_version);
            if (! $user || ! $user->is_active || ! $validStamp || $user->temporaryPasswordExpired()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Your account or access changed. Sign in again; expired temporary credentials require Admin renewal.'], 401)
                    : redirect()->route('login')->withErrors(['email' => 'Your account or access changed. Sign in again; contact an Admin if temporary credentials expired.']);
            }
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->session()->put('auth.access_version', $user->access_version);
            if ($user->must_change_password && ! $request->routeIs('password.setup', 'password.setup.update', 'logout')) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Replace your temporary password before accessing the application.', 'setupUrl' => route('password.setup')], 403)
                    : redirect()->route('password.setup');
            }
            if ($request->routeIs('password.confirm.store') && $request->isMethod('POST')) {
                $key = 'account-password-confirm:'.$user->id.'|'.$request->ip();
                abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many password confirmations. Wait one minute before retrying.');
                RateLimiter::hit($key, 60);
            }
            $module = ModuleAccess::forRoute($request->route()?->getName());
            if ($module !== null && ! $user->canAccessModule($module)) {
                AuditLog::create(['actor_id' => $user->id, 'event' => 'authorization.denied', 'reason' => $request->method().' '.$request->route()?->getName()]);
                abort(403, 'This module is not available to your account.');
            }
        }

        return $next($request);
    }
}
