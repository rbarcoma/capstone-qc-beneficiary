<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SystemHealthController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser(User::findOrFail($user->id))->authorize('view-system-health');
        $response = Inertia::render('system-health')->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function checks(Request $request, SystemHealth $health): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($health->inspect($user))->header('Cache-Control', 'private, no-store');
    }
}
