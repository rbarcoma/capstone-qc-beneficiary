import copy
import json
import subprocess
import sys
import unittest
from datetime import date
from pathlib import Path

from forecast import UNITS, forecast, next_month


def fixture(count=20, constant=False):
    month = date(2025, 1, 1)
    rows = []
    for index in range(count):
        rows.append({"month": month.isoformat(), "kwh": 100.0 if constant else 100.0 + 20 * index,
                     "weather": {"T2M": 25.0, "RH2M": 75.0, "PRECTOTCORR": 2.0, "ALLSKY_SFC_SW_DWN": 4.0},
                     "weather_complete": True, "weather_units": UNITS})
        month = next_month(month)
    return {"contract_version": 1, "records": rows}


class ForecastContractTest(unittest.TestCase):
    def test_next_month_only_primary_and_same_month_baseline_evidence(self):
        result = forecast(fixture())
        self.assertTrue(result["eligible"])
        self.assertEqual(result["target_month"], "2026-09-01")
        self.assertAlmostEqual(result["prediction_kwh"], 500.0, places=6)
        self.assertAlmostEqual(result["metrics"]["linear_regression"]["mae"], 0.0, places=6)
        self.assertEqual(result["metrics"]["linear_regression"]["test_months"], 3)
        self.assertEqual(result["metrics"]["previous_3_month_average"]["test_months"], 3)
        self.assertIsNone(result["error_band"]["calibrated_confidence_level"])
        self.assertEqual(len(result["model_version"]), 64)
        for row in result["evidence"]:
            self.assertLess(row["train_end_month"], row["month"])
            self.assertGreaterEqual(row["training_rows"], 10)

    def test_future_actuals_and_weather_do_not_change_earlier_backtests(self):
        original = fixture()
        changed = copy.deepcopy(original)
        changed["records"][-1]["kwh"] = 10000.0
        changed["records"][-1]["weather"]["T2M"] = 45.0
        first = forecast(original)
        second = forecast(changed)
        self.assertEqual(first["evidence"][0], second["evidence"][0])
        self.assertEqual(first["evidence"][1], second["evidence"][1])

    def test_insufficient_empty_and_gapped_history_has_no_prediction(self):
        for payload in [fixture(0), fixture(15)]:
            result = forecast(payload)
            self.assertFalse(result["eligible"])
            self.assertNotIn("prediction_kwh", result)
        payload = fixture()
        del payload["records"][5]
        self.assertEqual(forecast(payload)["reason"], "non_contiguous_verified_history")

    def test_missing_or_wrong_unit_weather_is_not_imputed(self):
        for value in [None, -999, float("nan")]:
            payload = fixture()
            payload["records"][-1]["weather"]["T2M"] = value
            self.assertEqual(forecast(payload)["reason"], "missing_or_unverified_weather")
        payload = fixture()
        payload["records"][-1]["weather_units"] = {**UNITS, "PRECTOTCORR": "mm"}
        self.assertFalse(forecast(payload)["eligible"])

    def test_constant_test_targets_have_undefined_r2_not_a_fabricated_perfect_score(self):
        result = forecast(fixture(constant=True))
        self.assertTrue(result["eligible"])
        self.assertIsNone(result["metrics"]["linear_regression"]["r2"])
        self.assertEqual(result["metrics"]["linear_regression"]["mae"], 0)

    def test_invalid_values_duplicates_and_horizon_override_are_rejected(self):
        payload = fixture()
        payload["target_month"] = "2030-01-01"
        with self.assertRaises(ValueError):
            forecast(payload)
        payload = fixture()
        payload["records"][1]["month"] = payload["records"][0]["month"]
        with self.assertRaises(ValueError):
            forecast(payload)
        payload = fixture()
        payload["records"][0]["kwh"] = -1
        with self.assertRaises(ValueError):
            forecast(payload)

    def test_real_subprocess_emits_json_only_and_rejects_bad_json(self):
        script = Path(__file__).with_name("forecast.py")
        success = subprocess.run([sys.executable, str(script)], input=json.dumps(fixture()), capture_output=True, text=True, check=True)
        self.assertTrue(json.loads(success.stdout)["eligible"])
        self.assertEqual(success.stderr, "")
        failure = subprocess.run([sys.executable, str(script)], input="not-json", capture_output=True, text=True)
        self.assertEqual(failure.returncode, 2)
        self.assertEqual(json.loads(failure.stdout)["error"]["code"], "invalid_input")


if __name__ == "__main__":
    unittest.main()
