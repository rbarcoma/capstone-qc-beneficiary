<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BuildingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataManagementController;
use App\Http\Controllers\DecisionSupportController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\PreprocessingController;
use App\Http\Controllers\SolarScenarioController;
use App\Http\Controllers\UsersAccessController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('decision-support', [DecisionSupportController::class, 'index'])->name('decision-support');
    Route::post('recommendations/{recommendation}/decide', [DecisionSupportController::class, 'decide'])->middleware('role:energy_manager')->name('recommendations.decide');
    Route::get('solar-scenarios', [SolarScenarioController::class, 'index'])->name('solar-scenarios');
    Route::post('solar-scenarios/calculate', [SolarScenarioController::class, 'calculate'])->middleware('throttle:30,1')->name('solar-scenarios.calculate');
    Route::get('forecasting', [ForecastController::class, 'index'])->name('forecasting');
    Route::get('model-evaluation', [ForecastController::class, 'evaluation'])->name('model-evaluation');
    Route::get('energy-analytics', [AnalyticsController::class, 'index'])->name('energy-analytics');
    Route::get('building-comparison', [AnalyticsController::class, 'comparison'])->name('building-comparison');
    Route::middleware('role:admin,energy_manager')->group(function () {
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
        Route::patch('buildings/{building}', [BuildingsController::class, 'update'])->name('buildings.update');
        Route::delete('buildings/{building}', [BuildingsController::class, 'destroy'])->name('buildings.destroy');
        Route::patch('meters/{meter}', [BuildingsController::class, 'updateMeter'])->name('meters.update');
        Route::delete('meters/{meter}', [BuildingsController::class, 'destroyMeter'])->name('meters.destroy');
    });
    Route::get('users-access', [UsersAccessController::class, 'index'])
        ->middleware('role:admin')
        ->name('users-access');
});

require __DIR__.'/settings.php';
