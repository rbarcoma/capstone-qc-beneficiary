<?php

use App\Http\Controllers\ActionTrackerController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BuildingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataManagementController;
use App\Http\Controllers\DecisionSupportController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreprocessingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SolarScenarioController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\TemporaryPasswordController;
use App\Http\Controllers\UsersAccessController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('account/password-setup', [TemporaryPasswordController::class, 'edit'])->name('password.setup');
    Route::put('account/password-setup', [TemporaryPasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.setup.update');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('notifications/read', [NotificationController::class, 'markRead'])->middleware(['role:energy_manager,budget_officer', 'throttle:30,1'])->name('notifications.read');
    Route::get('reports', [ReportController::class, 'index'])->name('reports');
    Route::post('reports/{building}/{type}', [ReportController::class, 'download'])->whereIn('type', ['summary', 'billing', 'evidence'])->middleware('throttle:10,1')->name('reports.download');
    Route::get('action-tracker', [ActionTrackerController::class, 'index'])->name('action-tracker');
    Route::get('actions/{action}/history', [ActionTrackerController::class, 'history'])->name('actions.history');
    Route::patch('actions/{action}', [ActionTrackerController::class, 'update'])->middleware('role:energy_manager')->name('actions.update');
    Route::get('decision-support', [DecisionSupportController::class, 'index'])->name('decision-support');
    Route::post('recommendations/{recommendation}/decide', [DecisionSupportController::class, 'decide'])->middleware('role:energy_manager')->name('recommendations.decide');
    Route::get('solar-scenarios', [SolarScenarioController::class, 'index'])->name('solar-scenarios');
    Route::post('solar-scenarios/calculate', [SolarScenarioController::class, 'calculate'])->middleware('throttle:30,1')->name('solar-scenarios.calculate');
    Route::get('forecasting', [ForecastController::class, 'index'])->name('forecasting');
    Route::get('model-evaluation', [ForecastController::class, 'evaluation'])->name('model-evaluation');
    Route::get('energy-analytics', [AnalyticsController::class, 'index'])->name('energy-analytics');
    Route::get('building-comparison', [AnalyticsController::class, 'comparison'])->name('building-comparison');
    Route::middleware('role:admin,energy_manager')->group(function () {
        Route::get('system-settings', [SystemSettingsController::class, 'index'])->name('system-settings');
        Route::post('forecasting/issue', [ForecastController::class, 'issue'])->middleware('throttle:6,1')->name('forecasting.issue');
        Route::post('forecasting/reconcile', [ForecastController::class, 'reconcile'])->name('forecasting.reconcile');
        Route::get('preprocessing', [PreprocessingController::class, 'index'])->name('preprocessing');
        Route::post('preprocessing/build', [PreprocessingController::class, 'build'])->name('preprocessing.build');
        Route::post('preprocessing/weather', [PreprocessingController::class, 'syncWeather'])->middleware('throttle:6,1')->name('preprocessing.weather');
        Route::get('datasets/{dataset}/lineage', [PreprocessingController::class, 'lineage'])->name('datasets.lineage');
        Route::get('verification-queue', [VerificationController::class, 'index'])->name('verification-queue');
        Route::post('monthly-records/{record}/verify', [VerificationController::class, 'verify'])->name('monthly-records.verify');
        Route::patch('monthly-records/{record}/correct', [VerificationController::class, 'correct'])->name('monthly-records.correct');
        Route::get('monthly-records/{record}/history', [VerificationController::class, 'history'])->name('monthly-records.history');
    });
    Route::middleware('role:admin,energy_manager,facilities_personnel')->group(function () {
        Route::get('data-management', [DataManagementController::class, 'index'])->name('data-management');
        Route::get('data-management/template', [DataManagementController::class, 'template'])->name('data-management.template');
        Route::post('data-management/import', [DataManagementController::class, 'import'])->name('data-management.import');
        Route::post('monthly-records', [DataManagementController::class, 'store'])->name('monthly-records.store');
    });
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('buildings-meters', [BuildingsController::class, 'index'])
        ->middleware('role:admin,energy_manager,facilities_personnel')
        ->name('buildings-meters');
    Route::post('buildings', [BuildingsController::class, 'store'])
        ->middleware('role:admin')
        ->name('buildings.store');
    Route::post('meters', [BuildingsController::class, 'storeMeter'])
        ->middleware('role:admin')
        ->name('meters.store');
    Route::middleware('role:admin')->group(function () {
        Route::get('system-health', [SystemHealthController::class, 'index'])->name('system-health');
        Route::get('system-health/checks', [SystemHealthController::class, 'checks'])->middleware('throttle:6,1')->name('system-health.checks');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
        Route::patch('buildings/{building}', [BuildingsController::class, 'update'])->name('buildings.update');
        Route::delete('buildings/{building}', [BuildingsController::class, 'destroy'])->name('buildings.destroy');
        Route::patch('meters/{meter}', [BuildingsController::class, 'updateMeter'])->name('meters.update');
        Route::delete('meters/{meter}', [BuildingsController::class, 'destroyMeter'])->name('meters.destroy');
    });
    Route::get('users-access', [UsersAccessController::class, 'index'])
        ->middleware('role:admin')
        ->name('users-access');
    Route::middleware('role:admin')->group(function () {
        Route::get('users/{user}', [UsersAccessController::class, 'show'])->name('users.show');
        Route::middleware(['password.confirm:password.confirm,'.config('user_management.password_confirmation_seconds', 900), 'throttle:10,1'])->group(function () {
            Route::post('users', [UsersAccessController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [UsersAccessController::class, 'update'])->name('users.update');
            Route::patch('users/{user}/status', [UsersAccessController::class, 'status'])->name('users.status');
            Route::post('users/{user}/temporary-password', [UsersAccessController::class, 'temporaryPassword'])->name('users.temporary-password');
        });
    });
});

require __DIR__.'/settings.php';
