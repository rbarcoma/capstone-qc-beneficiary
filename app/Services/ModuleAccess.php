<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Dataset;
use App\Models\Forecast;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\User;

class ModuleAccess
{
    /** @return array<string, array{label: string, roles: list<string>, description: string}> */
    public static function catalogue(): array
    {
        $all = UserRole::values();
        $managers = ['admin', 'energy_manager'];
        $operations = [...$managers, 'facilities_personnel'];

        return [
            'dashboard' => ['label' => 'Dashboard', 'roles' => $all, 'description' => 'Required baseline workspace'],
            'buildings_meters' => ['label' => 'Buildings & Meters', 'roles' => $operations, 'description' => 'Admin manages; other permitted roles view'],
            'data_management' => ['label' => 'Data Management', 'roles' => $operations, 'description' => 'Upload / encode within building scope'],
            'verification_queue' => ['label' => 'Verification Queue', 'roles' => $managers, 'description' => 'Independent verification / corrections'],
            'preprocessing' => ['label' => 'Preprocessing', 'roles' => $managers, 'description' => 'Build datasets / sync weather'],
            'energy_analytics' => ['label' => 'Energy Analytics', 'roles' => $all, 'description' => 'Role / building / cost scope retained'],
            'building_comparison' => ['label' => 'Building Comparison', 'roles' => $all, 'description' => 'Compare authorized buildings'],
            'forecasting' => ['label' => 'Forecasting', 'roles' => $all, 'description' => 'Only Admin / Energy Manager may issue'],
            'model_evaluation' => ['label' => 'Model Evaluation', 'roles' => $all, 'description' => 'Read existing evaluation evidence'],
            'decision_support' => ['label' => 'Decision Support', 'roles' => $all, 'description' => 'Only Energy Manager decides; C5 still required'],
            'action_tracker' => ['label' => 'Action Tracker', 'roles' => $all, 'description' => 'Only Energy Manager updates accepted work'],
            'solar_scenarios' => ['label' => 'Solar & Scenarios', 'roles' => $all, 'description' => 'Explicit-assumption screening only'],
            'budget_planning' => ['label' => 'Budget Planning', 'roles' => [], 'description' => 'Unavailable: tariff / budget contract pending'],
            'reports' => ['label' => 'Reports', 'roles' => $all, 'description' => 'Authorized INTERNAL exports; cost scope retained'],
            'notifications' => ['label' => 'Notifications', 'roles' => $all, 'description' => 'Notice recipients remain role-specific'],
            'users_access' => ['label' => 'Users & Access', 'roles' => ['admin'], 'description' => 'Admin-only account management'],
            'audit_logs' => ['label' => 'Audit Logs', 'roles' => ['admin'], 'description' => 'Admin-only retained event evidence'],
            'system_settings' => ['label' => 'System Settings', 'roles' => $managers, 'description' => 'Read only; no unapproved policy writes'],
            'system_health' => ['label' => 'System Health', 'roles' => ['admin'], 'description' => 'Admin-only local diagnostics'],
        ];
    }

    public static function roleAllows(UserRole $role, string $module): bool
    {
        return in_array($role->value, self::catalogue()[$module]['roles'] ?? [], true);
    }

    public static function essential(UserRole $role, string $module): bool
    {
        return $module === 'dashboard' || ($role === UserRole::Admin && $module === 'users_access');
    }

    public static function forRoute(?string $route): ?string
    {
        return match (true) {
            $route === null => null,
            $route === 'dashboard' => 'dashboard',
            $route === 'users-access', str_starts_with($route, 'users.') => 'users_access',
            $route === 'buildings-meters', str_starts_with($route, 'buildings.'), str_starts_with($route, 'meters.') => 'buildings_meters',
            str_starts_with($route, 'data-management'), $route === 'monthly-records.store' => 'data_management',
            $route === 'verification-queue', str_starts_with($route, 'monthly-records.') => 'verification_queue',
            str_starts_with($route, 'preprocessing'), str_starts_with($route, 'datasets.') => 'preprocessing',
            $route === 'forecasting', str_starts_with($route, 'forecasting.') => 'forecasting',
            $route === 'model-evaluation' => 'model_evaluation',
            $route === 'energy-analytics' => 'energy_analytics',
            $route === 'building-comparison' => 'building_comparison',
            $route === 'decision-support', str_starts_with($route, 'recommendations.') => 'decision_support',
            $route === 'action-tracker', str_starts_with($route, 'actions.') => 'action_tracker',
            str_starts_with($route, 'solar-scenarios') => 'solar_scenarios',
            $route === 'reports', str_starts_with($route, 'reports.') => 'reports',
            $route === 'notifications', str_starts_with($route, 'notifications.') => 'notifications',
            str_starts_with($route, 'audit-logs') => 'audit_logs',
            $route === 'system-settings' => 'system_settings',
            str_starts_with($route, 'system-health') => 'system_health',
            default => null,
        };
    }

    /**
     * Guard mutating policies too, including their fresh-actor checks inside
     * transactions. Shared source visibility stays governed by existing policies.
     *
     * @param  array<mixed>  $arguments
     */
    public static function forAbility(string $ability, array $arguments): ?string
    {
        $subject = $arguments[0] ?? null;
        $class = is_object($subject) ? $subject::class : $subject;

        return match (true) {
            $ability === 'view-system-health' => 'system_health',
            $ability === 'view-system-settings' => 'system_settings',
            $class === User::class => 'users_access',
            $class === AuditLog::class => 'audit_logs',
            $class === Building::class && $ability === 'manage' => 'buildings_meters',
            $class === MonthlyRecord::class && $ability === 'create' => 'data_management',
            $class === MonthlyRecord::class && in_array($ability, ['verify', 'correct'], true) => 'verification_queue',
            $class === Dataset::class && $ability === 'create' => 'preprocessing',
            $class === Forecast::class && $ability === 'create' => 'forecasting',
            $class === Recommendation::class && $ability === 'decide' => 'decision_support',
            $class === Action::class && $ability === 'update' => 'action_tracker',
            default => null,
        };
    }
}
