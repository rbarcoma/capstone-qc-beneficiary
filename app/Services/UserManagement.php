<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagement
{
    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    private function accountTransaction(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent create/edit can race the validation query. Do not
            // return SQL/bindings (including password hashes) to the client.
            $this->fail('email', 'This email is already registered. Refresh and use another address.');
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, ?User $subject = null): array
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($subject?->id)],
            'role' => ['required', Rule::in(UserRole::values())],
            'building_ids' => ['present', 'array', 'max:500'],
            'building_ids.*' => ['required', 'integer', 'distinct', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'restricted_modules' => ['present', 'array', 'max:50'],
            'restricted_modules.*' => ['required', 'string', 'distinct', Rule::in(array_keys(ModuleAccess::catalogue()))],
            'version' => [$subject ? 'required' : 'prohibited', 'integer', 'min:1'],
            'reason' => [$subject ? 'required' : 'nullable', 'string', 'max:1000'],
            'password' => ['prohibited'], 'is_active' => ['prohibited'], 'access_version' => ['prohibited'],
            'email_verified_at' => ['prohibited'], 'must_change_password' => ['prohibited'],
            'temporary_password_expires_at' => ['prohibited'], 'deactivated_at' => ['prohibited'],
        ])->validate();
        $role = UserRole::from($data['role']);
        if (! $role->isBuildingRestricted() && $data['building_ids'] !== []) {
            $this->fail('building_ids', 'This role has all-building access. Individual assignments apply only to Facilities Personnel.');
        }
        foreach ($data['restricted_modules'] as $module) {
            if (ModuleAccess::essential($role, $module)) {
                $this->fail('restricted_modules', 'Dashboard and Admin account-management access cannot be removed.');
            }
        }
        $data['building_ids'] = array_map('intval', $data['building_ids']);
        sort($data['building_ids']);
        sort($data['restricted_modules']);

        return $data;
    }

    private function lockAdmins(): void
    {
        // Shared, deterministic lock set serializes last-Admin changes (including
        // two Admins changing each other). Pending password setup is not usable.
        User::where('role', UserRole::Admin)->orderBy('id')->lockForUpdate()->get();
    }

    private function actor(User $user): User
    {
        $actor = User::lockForUpdate()->findOrFail($user->id);
        Gate::forUser($actor)->authorize('viewAny', User::class);

        return $actor;
    }

    /** @param list<int> $ids */
    private function lockBuildings(array $ids): void
    {
        $lockedIds = Building::whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
        if ($lockedIds !== $ids) {
            $this->fail('building_ids', 'A selected building was removed. Refresh and review the assignments.');
        }
    }

    private function checkVersion(User $subject, int $version): void
    {
        if ($subject->version !== $version) {
            $this->fail('version', 'This account changed. Refresh its details before saving; your entries have been retained.');
        }
    }

    private function protectLastAdmin(User $subject): void
    {
        if ($subject->role === UserRole::Admin && $subject->isUsable()
            && User::where('role', UserRole::Admin)->where('is_active', true)->where('must_change_password', false)->count() <= 1) {
            $this->fail('user', 'The last usable Admin account must be retained. Complete another Admin account first.');
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(User $user): array
    {
        return [
            ...$user->only(['id', 'name', 'email', 'is_active', 'version', 'access_version', 'must_change_password']),
            'role' => $user->role->value,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'temporary_password_expires_at' => $user->temporary_password_expires_at?->toIso8601String(),
            'building_ids' => $user->buildings()->orderBy('buildings.id')->pluck('buildings.id')->all(),
            'restricted_modules' => $user->moduleRestrictions()->orderBy('module_key')->pluck('module_key')->all(),
        ];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, User $subject, string $event, ?array $before, ?string $reason): void
    {
        AuditLog::create(['actor_id' => $actor->id, 'subject_user_id' => $subject->id, 'event' => $event,
            'before' => $before, 'after' => ['user' => $this->snapshot($subject), 'actor' => $actor->only(['id', 'name', 'role'])], 'reason' => $reason]);
    }

    public function revoke(User $subject, ?string $keepSession = null): void
    {
        $subject->forceFill(['access_version' => $subject->access_version + 1, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $subject->id)->when($keepSession, fn ($query) => $query->where('id', '!=', $keepSession))->delete();
        DB::table('password_reset_tokens')->where('email', $subject->email)->delete();
        if ($keepSession !== null && request()->hasSession()) {
            request()->session()->put('auth.access_version', $subject->access_version);
        }
    }

    /** @param array<string, mixed> $input
     * @return array{user: User, temporaryPassword: string}
     */
    public function create(User $user, array $input): array
    {
        Gate::forUser($user)->authorize('create', User::class);
        $data = $this->validate($input);
        $temporary = Str::password(24);

        return $this->accountTransaction(function () use ($user, $data, $temporary) {
            $this->lockBuildings($data['building_ids']);
            $this->lockAdmins();
            $actor = $this->actor($user);
            $subject = new User(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'password' => $temporary]);
            $subject->forceFill(['must_change_password' => true,
                'temporary_password_expires_at' => now()->addMinutes(max(1, (int) config('user_management.temporary_password_minutes', 60)))])->save();
            $subject->buildings()->sync($data['building_ids']);
            foreach ($data['restricted_modules'] as $key) {
                $subject->moduleRestrictions()->create(['module_key' => $key]);
            }
            $this->audit($actor, $subject, 'user.created', null, $data['reason'] ?? null);

            // Plaintext exists only in this one no-store response, never a
            // session flash, persistent credential record or audit payload.
            return ['user' => $subject, 'temporaryPassword' => $temporary];
        });
    }

    /** @param array<string, mixed> $input */
    public function update(User $user, User $target, array $input): User
    {
        Gate::forUser($user)->authorize('update', $target);
        $data = $this->validate($input, $target);

        return $this->accountTransaction(function () use ($user, $target, $data) {
            $this->lockBuildings($data['building_ids']);
            $this->lockAdmins();
            $actor = $this->actor($user);
            $subject = User::lockForUpdate()->findOrFail($target->id);
            $this->checkVersion($subject, (int) $data['version']);
            $before = $this->snapshot($subject);
            $oldRestrictions = $before['restricted_modules'];
            $role = UserRole::from($data['role']);
            if ($subject->id === $actor->id && ($role !== $subject->role || $oldRestrictions !== $data['restricted_modules'])) {
                $this->fail('role', 'Use another Admin to change your own role or module access.');
            }
            if ($role !== UserRole::Admin) {
                $this->protectLastAdmin($subject);
            }
            $emailChanged = $subject->email !== $data['email'];
            $accessChanged = $emailChanged || $subject->role !== $role || $oldRestrictions !== $data['restricted_modules']
                || ($role->isBuildingRestricted() && $before['building_ids'] !== $data['building_ids']);
            $subject->fill(['name' => $data['name'], 'email' => $data['email'], 'role' => $role]);
            if ($emailChanged) {
                DB::table('password_reset_tokens')->where('email', $before['email'])->delete();
                $subject->email_verified_at = null;
            }
            $subject->version++;
            $subject->save();
            // Non-restricted roles retain dormant historical assignments, but
            // their effective scope stays all buildings. Facilities is explicit.
            if ($role->isBuildingRestricted()) {
                $subject->buildings()->sync($data['building_ids']);
            }
            $subject->moduleRestrictions()->delete();
            foreach ($data['restricted_modules'] as $key) {
                $subject->moduleRestrictions()->create(['module_key' => $key]);
            }
            if ($accessChanged) {
                $keep = $actor->id === $subject->id && request()->hasSession() ? request()->session()->getId() : null;
                $this->revoke($subject, $keep);
            }
            $this->audit($actor, $subject, 'user.updated', ['user' => $before], $data['reason']);

            return $subject;
        });
    }

    /** @param array<string, mixed> $input */
    public function changeStatus(User $user, User $target, array $input): User
    {
        $data = Validator::make($input, ['is_active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000']])->validate();

        return DB::transaction(function () use ($user, $target, $data) {
            $this->lockAdmins();
            $actor = $this->actor($user);
            $subject = User::lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('changeStatus', $subject);
            $this->checkVersion($subject, (int) $data['version']);
            $active = (bool) $data['is_active'];
            if ($subject->is_active === $active) {
                $this->fail('user', 'This account already has that status. Refresh before continuing.');
            }
            if (! $active) {
                $this->protectLastAdmin($subject);
            }
            $before = $this->snapshot($subject);
            $subject->forceFill(['is_active' => $active, 'deactivated_at' => $active ? null : now(), 'version' => $subject->version + 1])->save();
            $this->revoke($subject);
            $this->audit($actor, $subject, $active ? 'user.reactivated' : 'user.deactivated', ['user' => $before], $data['reason']);

            return $subject;
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array{user: User, temporaryPassword: string}
     */
    public function temporaryPassword(User $user, User $target, array $input): array
    {
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']])->validate();
        $temporary = Str::password(24);

        return DB::transaction(function () use ($user, $target, $data, $temporary) {
            $this->lockAdmins();
            $actor = $this->actor($user);
            $subject = User::lockForUpdate()->findOrFail($target->id);
            abort_if($actor->id === $subject->id, 403, 'An Admin cannot issue their own temporary credentials.');
            $this->checkVersion($subject, (int) $data['version']);
            if (! $subject->is_active) {
                $this->fail('user', 'Reactivate the account before issuing temporary credentials.');
            }
            $this->protectLastAdmin($subject);
            $before = $this->snapshot($subject);
            $subject->forceFill(['password' => $temporary, 'must_change_password' => true, 'version' => $subject->version + 1,
                'temporary_password_expires_at' => now()->addMinutes(max(1, (int) config('user_management.temporary_password_minutes', 60)))])->save();
            $this->revoke($subject);
            $this->audit($actor, $subject, 'user.temporary_password_issued', ['user' => $before], $data['reason']);

            return ['user' => $subject, 'temporaryPassword' => $temporary];
        }, 3);
    }

    public function changePassword(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $currentPassword = null, bool $reset = false): void
    {
        DB::transaction(function () use ($user, $password, $currentPassword, $reset) {
            $subject = User::lockForUpdate()->findOrFail($user->id);
            if (! $subject->is_active || $subject->access_version !== $user->access_version || (! $reset && $subject->temporaryPasswordExpired())) {
                $this->fail('password', 'Account access changed. Sign in again or request a new reset.');
            }
            if ($currentPassword !== null && ! Hash::check($currentPassword, $subject->password)) {
                $this->fail('current_password', 'The current password is incorrect.');
            }
            if ($subject->must_change_password && Hash::check($password, $subject->password)) {
                $this->fail('password', 'Choose a new password different from the temporary password.');
            }
            $before = $this->snapshot($subject);
            $subject->forceFill(['password' => $password, 'must_change_password' => false, 'temporary_password_expires_at' => null,
                'version' => $subject->version + 1])->save();
            $this->revoke($subject);
            $this->audit($subject, $subject, $reset ? 'user.password_reset' : 'user.password_changed', ['user' => $before], null);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateOwnProfile(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $subject = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($subject->isUsable(), 403);
            $before = $this->snapshot($subject);
            $emailChanged = $subject->email !== $data['email'];
            $subject->fill($data);
            if ($emailChanged) {
                $subject->email_verified_at = null;
                DB::table('password_reset_tokens')->where('email', $before['email'])->delete();
            }
            $subject->version++;
            $subject->save();
            if ($emailChanged) {
                $this->revoke($subject, request()->session()->getId());
            }
            $this->audit($subject, $subject, 'user.profile_updated', ['user' => $before], null);
        }, 3);
    }

    public function deleteOwnAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lockAdmins();
            $subject = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($subject->isUsable(), 403);
            $this->protectLastAdmin($subject);
            Auth::logout();
            $subject->delete();
        }, 3);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
