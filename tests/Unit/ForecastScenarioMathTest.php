<?php

namespace Tests\Unit;

use App\Services\ForecastScenario;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ForecastScenarioMathTest extends TestCase
{
    public function test_percentage_arithmetic_is_not_a_new_prediction_or_monetary_estimate(): void
    {
        $service = new ForecastScenario;
        $result = $service->arithmetic(420, 30, 25);
        $this->assertSame(315.0, $result['scenarioKwh']);
        $this->assertSame(105.0, $result['assumedDifferenceKwh']);
        $this->assertNull($result['costDifferencePhp']);
        $this->assertNull($result['solar']);
        $this->assertSame(420.0, $service->arithmetic(420, 30, 0)['scenarioKwh']);
        $this->assertSame(0.0, $service->arithmetic(420, 30, 100)['scenarioKwh']);
    }

    public function test_solar_uses_daily_energy_units_target_days_and_explicit_capacity_ratio(): void
    {
        $result = (new ForecastScenario)->arithmetic(420, 30, 10, 2.5, 0.8, 4);
        $this->assertSame(240.0, $result['solar']['generationKwh']);
        $this->assertSame(240.0, $result['solar']['offsetCeilingKwh']);
        $this->assertSame(180.0, $result['solar']['gridEnergyLowerBoundKwh']);
        $this->assertSame(378.0, $result['scenarioKwh']);
        $this->assertSame(232.0, (new ForecastScenario)->arithmetic(420, 29, 0, 2.5, 0.8, 4)['solar']['generationKwh']);
    }

    public function test_generation_is_not_capped_but_energy_equivalent_offset_never_exceeds_load(): void
    {
        $solar = (new ForecastScenario)->arithmetic(420, 30, 0, 100, 0.8, 4)['solar'];
        $this->assertSame(9600.0, $solar['generationKwh']);
        $this->assertSame(420.0, $solar['offsetCeilingKwh']);
        $this->assertSame(100.0, $solar['offsetCeilingPercent']);
        $this->assertSame(0.0, $solar['gridEnergyLowerBoundKwh']);
    }

    public function test_zero_and_absent_assumptions_are_distinct_and_zero_load_has_no_percentage(): void
    {
        $service = new ForecastScenario;
        $this->assertNull($service->arithmetic(420, 30, 0)['solar']);
        $this->assertNull($service->arithmetic(420, 30, 0, 1, 0.8, null)['solar']);
        $this->assertSame(0.0, $service->arithmetic(420, 30, 0, 0, 0.8, 4)['solar']['generationKwh']);
        $this->assertSame(0.0, $service->arithmetic(420, 30, 0, 1, 0, 4)['solar']['generationKwh']);
        $this->assertNull($service->arithmetic(0, 30, 0, 1, 0.8, 4)['solar']['offsetCeilingPercent']);
    }

    public function test_invalid_physical_bounds_nonfinite_and_incomplete_input_pairs_are_rejected(): void
    {
        foreach ([[-1, 30, 0], [1, 0, 0], [1, 32, 0], [1, 30, -1], [1, 30, 101], [1, 30, 0, 1, null, 4], [1, 30, 0, 1, 1.1, 4], [1, 30, 0, 1, 0.8, -1], [NAN, 30, 0], [1, 30, INF]] as $args) {
            try {
                (new ForecastScenario)->arithmetic(...$args);
                $this->fail('Invalid scenario assumptions accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
