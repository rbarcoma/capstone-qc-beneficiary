<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserManagement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class TemporaryPasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $response = Inertia::render('auth/password-setup')->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function update(Request $request, UserManagement $management): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->must_change_password, 403);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed', 'different:current_password'],
        ]);
        $management->changePassword($user, $data['password'], $data['current_password']);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.');
    }
}
