<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationInbox $inbox): Response
    {
        $filters = $request->validate(['state' => ['nullable', Rule::in(['unread', 'read'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        /** @var User $user */
        $user = $request->user();
        $items = $inbox->read($user);
        $filtered = $items->filter(fn ($item) => empty($filters['state']) || ($filters['state'] === 'read') === ($item['readAt'] !== null))->values();
        $page = $filters['page'] ?? 1;
        $response = Inertia::render('notifications', [
            'filters' => ['state' => $filters['state'] ?? ''],
            'metrics' => ['unread' => $items->whereNull('readAt')->count(), 'verification' => $items->where('kind', 'verification')->count(), 'forecasts' => $items->where('kind', 'forecast')->count()],
            'notices' => (new LengthAwarePaginator($filtered->forPage($page, 20)->values(), $filtered->count(), 20, $page, ['path' => $request->url()]))->withQueryString(),
            'operationResult' => $request->session()->get('notificationResult'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function markRead(Request $request, NotificationInbox $inbox): RedirectResponse
    {
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:20'], 'keys.*' => ['required', 'string', 'distinct', 'regex:/^[a-f0-9]{64}$/']]);
        /** @var User $user */
        $user = $request->user();
        $inbox->markRead($user, $data['keys']);

        return back()->with('notificationResult', 'Reviewed notices marked read. Source verification and decisions are unchanged.');
    }
}
