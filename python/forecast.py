"""P6 v1: pure stdin/stdout JSON, expanding-window OLS vs calendar 3-month mean.

No files/models/datasets are written. Laravel owns authorization and lineage.
Target-month weather is never requested or used: weather predictors are lagged.
"""
import hashlib
import json
import math
import sys
from datetime import date
from pathlib import Path

CONTRACT = 1
MIN_TRAIN = 10
VALIDATION_MONTHS = 3
PARAMETERS = ("T2M", "RH2M", "PRECTOTCORR", "ALLSKY_SFC_SW_DWN")
UNITS = {"T2M": "C", "RH2M": "%", "PRECTOTCORR": "mm/day", "ALLSKY_SFC_SW_DWN": "kW-hr/m^2/day"}
FEATURES = ("lag_1_kwh", "lag_2_kwh", "month_sin", "month_cos", *[f"{p}_lag_1" for p in PARAMETERS])


def next_month(month):
    return date(month.year + (month.month == 12), month.month % 12 + 1, 1)


def finite_number(value):
    return not isinstance(value, bool) and isinstance(value, (int, float)) and math.isfinite(value)


def forecast(payload):
    if not isinstance(payload, dict) or set(payload) != {"contract_version", "records"} or payload["contract_version"] != CONTRACT:
        raise ValueError("Expected contract_version=1 and records only; no target/horizon override is accepted.")
    rows = payload["records"]
    if not isinstance(rows, list) or len(rows) > 2400:
        raise ValueError("records must be an array of at most 2400 monthly observations.")
    months = []
    for index, row in enumerate(rows):
        if not isinstance(row, dict):
            raise ValueError(f"Record {index} must be an object.")
        try:
            month = date.fromisoformat(row["month"])
        except (KeyError, TypeError, ValueError):
            raise ValueError(f"Record {index} must have a valid YYYY-MM-01 month.") from None
        if month.day != 1 or row["month"] != month.isoformat() or not finite_number(row.get("kwh")) or row["kwh"] < 0:
            raise ValueError(f"Record {index} has an invalid monthly energy observation.")
        if months and month <= months[-1]:
            raise ValueError("Observations must be sorted and have unique months.")
        months.append(month)

    target = next_month(months[-1]).isoformat() if months else None
    base = {"contract_version": CONTRACT, "eligible": False, "target_month": target,
            "model": "linear_regression", "baseline": "previous_3_month_average",
            "feature_names": list(FEATURES), "minimum_training_rows": MIN_TRAIN,
            "validation_months_required": VALIDATION_MONTHS}
    if not rows:
        return {**base, "reason": "no_verified_history"}
    if any(next_month(months[i - 1]) != months[i] for i in range(1, len(months))):
        return {**base, "reason": "non_contiguous_verified_history"}
    # First three observations are explicit warm-up, not silently imputed rows.
    usable = len(rows) - 3
    if usable < MIN_TRAIN + VALIDATION_MONTHS:
        return {**base, "reason": "insufficient_history", "usable_rows": max(0, usable), "warmup_months": 3}
    for index in range(2, len(rows)):
        weather = rows[index].get("weather")
        if rows[index].get("weather_complete") is not True or rows[index].get("weather_units") != UNITS or not isinstance(weather, dict):
            return {**base, "reason": "missing_or_unverified_weather", "month": months[index].isoformat()}
        for parameter in PARAMETERS:
            value = weather.get(parameter)
            if not finite_number(value) or value == -999 or (parameter == "T2M" and value < -273.15) or (parameter != "T2M" and value < 0) or (parameter == "RH2M" and value > 100):
                return {**base, "reason": "missing_or_unverified_weather", "month": months[index].isoformat()}

    import numpy as np
    import sklearn
    from sklearn.linear_model import LinearRegression
    from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
    from sklearn.pipeline import make_pipeline
    from sklearn.preprocessing import StandardScaler

    def features(index, month):
        # index may equal len(rows), but every observed predictor is index-1/2.
        return [rows[index - 1]["kwh"], rows[index - 2]["kwh"],
                math.sin(2 * math.pi * month.month / 12), math.cos(2 * math.pi * month.month / 12),
                *[rows[index - 1]["weather"][parameter] for parameter in PARAMETERS]]

    indices = list(range(3, len(rows)))
    x = np.asarray([features(i, months[i]) for i in indices], dtype=float)
    y = np.asarray([rows[i]["kwh"] for i in indices], dtype=float)
    evidence = []
    for split in range(len(indices) - VALIDATION_MONTHS, len(indices)):
        model = make_pipeline(StandardScaler(), LinearRegression())
        # Scaling and fitting occur only on the expanding PAST training window.
        model.fit(x[:split], y[:split])
        index = indices[split]
        predicted = float(model.predict(x[split:split + 1])[0])
        baseline = sum(rows[j]["kwh"] for j in range(index - 3, index)) / 3
        evidence.append({"month": months[index].isoformat(), "actual_kwh": rows[index]["kwh"],
                         "linear_kwh": predicted, "baseline_kwh": baseline,
                         "train_end_month": months[index - 1].isoformat(), "training_rows": split})

    actual = [item["actual_kwh"] for item in evidence]

    def metrics(field):
        predictions = [item[field] for item in evidence]
        return {"mae": float(mean_absolute_error(actual, predictions)),
                "rmse": float(math.sqrt(mean_squared_error(actual, predictions))),
                "r2": float(r2_score(actual, predictions)) if len(set(actual)) > 1 else None,
                "test_months": len(evidence)}

    model = make_pipeline(StandardScaler(), LinearRegression())
    model.fit(x, y)
    prediction = float(model.predict(np.asarray([features(len(rows), next_month(months[-1]))], dtype=float))[0])
    if not math.isfinite(prediction) or prediction < 0:
        return {**base, "reason": "primary_prediction_outside_consumption_domain"}
    error = max(abs(item["actual_kwh"] - item["linear_kwh"]) for item in evidence)
    return {**base, "eligible": True, "reason": None, "prediction_kwh": prediction,
            "baseline_kwh": sum(row["kwh"] for row in rows[-3:]) / 3,
            "training_rows": len(indices), "warmup_months": 3,
            "metrics": {"linear_regression": metrics("linear_kwh"), "previous_3_month_average": metrics("baseline_kwh")},
            "evidence": evidence,
            "error_band": {"lower_kwh": max(0.0, prediction - error), "upper_kwh": prediction + error,
                           "method": "observed_max_absolute_walk_forward_error", "validation_months": len(evidence),
                           "calibrated_confidence_level": None},
            "validation_label": f"Limited validation: {len(evidence)} walk-forward months; band is not a calibrated confidence interval",
            "model_version": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
            "runtime": {"python": sys.version.split()[0], "numpy": np.__version__, "scikit_learn": sklearn.__version__}}


def main():
    try:
        payload = json.load(sys.stdin)
        result = forecast(payload)
        print(json.dumps(result, allow_nan=False))
        return 0
    except (ValueError, TypeError, KeyError, json.JSONDecodeError) as error:
        print(json.dumps({"contract_version": CONTRACT, "error": {"code": "invalid_input", "message": str(error)}}))
        return 2


if __name__ == "__main__":
    sys.exit(main())
