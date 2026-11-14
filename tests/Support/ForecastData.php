<?php

namespace Tests\Support;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\WeatherRecord;
use App\Services\BuildingDataset;
use App\Services\DataFingerprint;
use App\Services\NasaPowerWeather;
use Illuminate\Support\Carbon;

trait ForecastData
{
    /** @return array{user: User, building: Building, meters: list<Meter>, dataset: Dataset} */
    private function forecastFixture(int $months = 16, bool $multi = false): array
    {
        $user = User::factory()->role('energy_manager')->create();
        $building = Building::factory()->create(['name' => 'Forecast Test Building', 'floor_area_m2' => 100]);
        $meters = [Meter::factory()->create(['building_id' => $building->id])];
        if ($multi) {
            $meters[] = Meter::factory()->create(['building_id' => $building->id]);
        }
        $month = Carbon::parse('2025-05-01');
        for ($i = 0; $i < $months; $i++) {
            foreach ($meters as $meter) {
                MonthlyRecord::create(['meter_id' => $meter->id, 'month' => $month->toDateString(), 'kwh' => 100 + 20 * $i,
                    'source' => 'manual', 'verification_status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now()]);
            }
            $values = ['T2M' => 25, 'RH2M' => 75, 'PRECTOTCORR' => 2, 'ALLSKY_SFC_SW_DWN' => 4];
            WeatherRecord::firstOrCreate(['location_key' => app(NasaPowerWeather::class)->locationKey(), 'month' => $month->toDateString()], ['source' => 'NASA_POWER',
                'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude'), 'weather_values' => $values, 'units' => NasaPowerWeather::UNITS,
                'quality' => ['fixture' => true], 'is_complete' => true, 'raw_values' => [], 'source_metadata' => ['fixture' => true],
                'content_hash' => DataFingerprint::hash($values), 'fetched_at' => now()]);
            $month->addMonth();
        }
        $dataset = app(BuildingDataset::class)->build($building, $user)['dataset'];

        return compact('user', 'building', 'meters', 'dataset');
    }
}
