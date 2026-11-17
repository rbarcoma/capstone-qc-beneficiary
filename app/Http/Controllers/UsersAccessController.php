<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use App\Services\ModuleAccess;
use App\Services\UserManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class UsersAccessController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(UserRole::values())],
            'status' => ['nullable', Rule::in(['active', 'setup_required', 'deactivated'])],
            'building' => ['nullable', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $query = User::query()->with(['buildings:id,name,code', 'moduleRestrictions']);
        if (($search = trim($filters['search'] ?? '')) !== '') {
            $search = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('email', 'like', $search));
        }
        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }
        match ($filters['status'] ?? '') {
            'active' => $query->where('is_active', true)->where('must_change_password', false),
            'setup_required' => $query->where('is_active', true)->where('must_change_password', true),
            'deactivated' => $query->where('is_active', false),
            default => null,
        };
        if (! empty($filters['building'])) {
            $query->where(fn ($query) => $query->where('role', '!=', UserRole::FacilitiesPersonnel->value)
                ->orWhereHas('buildings', fn ($query) => $query->whereKey((int) $filters['building'])));
        }
        $response = Inertia::render('users-access', [
            'users' => $query->orderBy('name')->orderBy('id')->paginate(10)->withQueryString()->through(fn (User $user) => $this->project($user)),
            'filters' => ['search' => $filters['search'] ?? '', 'role' => $filters['role'] ?? '', 'status' => $filters['status'] ?? '', 'building' => (string) ($filters['building'] ?? '')],
            'counts' => ['total' => User::count(), 'active' => User::where('is_active', true)->where('must_change_password', false)->count(),
                'setupRequired' => User::where('is_active', true)->where('must_change_password', true)->count(), 'deactivated' => User::where('is_active', false)->count()],
            'buildings' => Building::orderBy('name')->get(['id', 'name', 'code']),
            'roleOptions' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label(), 'buildingRestricted' => $role->isBuildingRestricted()], UserRole::cases()),
            'moduleCatalogue' => collect(ModuleAccess::catalogue())->map(fn ($module, $key) => ['key' => $key, ...$module,
                'requiredFor' => array_values(array_filter(UserRole::values(), fn ($role) => ModuleAccess::essential(UserRole::from($role), $key)))])->values(),
            'temporaryPasswordMinutes' => (int) config('user_management.temporary_password_minutes', 60),
            'roles' => [
                ['name' => 'Admin', 'status' => 'Existing'],
                ['name' => 'Viewer / Executive', 'status' => 'Existing'],
                ['name' => 'Energy Manager', 'status' => 'Proposed'],
                ['name' => 'Facilities / Budget', 'status' => 'Proposed'],
            ],
            'matrix' => [
                ['capability' => 'Upload monthly data', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'Assigned', 'budgetViewer' => 'No'],
                ['capability' => 'Verify data', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Run next forecast', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Decide recommendation', 'admin' => 'No', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Set targets / tariffs', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Download reports', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'Own', 'budgetViewer' => 'Yes'],
                ['capability' => 'Audit trail', 'admin' => 'Yes', 'energyManager' => 'No', 'facilities' => 'No', 'budgetViewer' => 'No'],
            ],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @return array<string, mixed> */
    private function project(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'roleLabel' => $user->role->label(),
            'version' => $user->version, 'isActive' => $user->is_active, 'setupRequired' => $user->must_change_password,
            'setupExpired' => $user->temporaryPasswordExpired(), 'emailVerified' => $user->email_verified_at !== null,
            'temporaryPasswordExpiresAt' => $user->temporary_password_expires_at?->toIso8601String(),
            'deactivatedAt' => $user->deactivated_at?->toIso8601String(),
            'buildingIds' => $user->role->isBuildingRestricted() ? $user->buildings->pluck('id')->all() : [],
            'buildingNames' => $user->role->isBuildingRestricted() ? $user->buildings->pluck('name')->all() : [],
            'restrictedModules' => $user->moduleRestrictions->pluck('module_key')->sort()->values()->all(),
        ];
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view', $user);
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $history = AuditLog::where('subject_user_id', $user->id)->where('event', 'like', 'user.%')->with('actor:id,name')
            ->latest('id')->paginate(10)->through(fn (AuditLog $entry) => [
                'id' => $entry->id, 'event' => $entry->event, 'reason' => $entry->reason,
                'before' => $entry->before, 'after' => $entry->after, 'createdAt' => $entry->created_at?->toIso8601String(),
                'actorName' => $entry->after['actor']['name'] ?? $entry->actor->name ?? 'System / former user',
            ]);

        return $this->json(['user' => $this->project($user->load(['buildings:id,name,code', 'moduleRestrictions'])), 'history' => $history]);
    }

    public function store(Request $request, UserManagement $management): JsonResponse
    {
        $result = $management->create($request->user(), $request->all());

        return $this->json(['user' => $this->project($result['user']->load(['buildings', 'moduleRestrictions'])),
            'temporaryPassword' => $result['temporaryPassword'], 'message' => 'User created. Password replacement is required.'], 201);
    }

    public function update(Request $request, User $user, UserManagement $management): JsonResponse
    {
        $subject = $management->update($request->user(), $user, $request->all());

        return $this->json(['user' => $this->project($subject->load(['buildings', 'moduleRestrictions'])), 'message' => 'Account and access updated.']);
    }

    public function status(Request $request, User $user, UserManagement $management): JsonResponse
    {
        $subject = $management->changeStatus($request->user(), $user, $request->all());

        return $this->json(['user' => $this->project($subject->load(['buildings', 'moduleRestrictions'])),
            'message' => $subject->is_active ? 'User reactivated. A fresh sign-in is required.' : 'User deactivated. Sessions revoked; history retained.']);
    }

    public function temporaryPassword(Request $request, User $user, UserManagement $management): JsonResponse
    {
        $result = $management->temporaryPassword($request->user(), $user, $request->all());

        return $this->json(['user' => $this->project($result['user']->load(['buildings', 'moduleRestrictions'])),
            'temporaryPassword' => $result['temporaryPassword'], 'message' => 'Temporary password issued. Previous credentials and sessions are invalid.']);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
