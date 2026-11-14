# Forecast worker — P6 contract checkpoint

The worker is invoked by Laravel as an argument-array subprocess, JSON on stdin/stdout. It never writes models/data or issues an operational forecast. `PythonForecast::evaluate()` authorizes Admin/Energy Manager, checks current dataset lineage and rejects a newer incomplete/unverified period before invoking the worker. Returned results include dataset identity/version/fingerprint. Persistence/publication and a final locked freshness check belong to the next P6 task.

## Runtime

From repo root with Sail running:

```sh
bash python/setup.sh
composer test:forecast
composer test
```

Setup installs only `python3.12-venv` and its wheel prerequisites inside the running Sail application container, then creates ignored `.venv` and installs fully pinned requirements. No global pip, DB settings, `.env`, Compose/image definitions or MySQL changes. Re-run setup after rebuilding the application container. The Linux venv is container-specific, not a host Python environment. `FORECAST_PYTHON` may explicitly select an equivalent prepared runtime; default `.venv/bin/python`, timeout 30s.

## Contract and evaluation

Input: `{ "contract_version": 1, "records": [...] }`, sorted unique first-of-month records with nonnegative finite `kwh`, observed `weather`, `weather_complete`, and exact native `weather_units`. No target/horizon override. Laravel sends only authorized current complete-building rows, not user/account data.

- Target is exactly the month after the latest verified complete observation.
- Primary = scikit-learn Linear Regression, baseline = previous-three-calendar-month average; no model-family promotion or added challengers.
- Eight predictors: energy lags 1/2, calendar sin/cos, previous-month T2M/RH2M/PRECTOTCORR/ALLSKY_SFC_SW_DWN. No target-month observed weather or demand. No imputation/global scaling.
- First 3 months are explicit warm-up. **Technical guard:** at least 10 training rows for 8 predictors plus intercept, and 3 expanding-window held-out months on the same calendar periods for both models (at least 16 contiguous complete observations). This is an implementation/data-sufficiency guard, not a City Hall-approved confidence policy. Missing required lagged weather/units, calendar gaps or insufficient history return ineligible without a number.
- StandardScaler fits separately on each past-only training fold. Final fit uses all available eligible training rows. MAE/RMSE/R² are computed from held-out evidence; R² is null for constant targets. Runtime/package versions and worker SHA256 returned.
- Error band = next prediction ± maximum absolute observed walk-forward error, lower bounded by physical zero. **Not a calibrated confidence interval**, no asserted probability/95% coverage. Negative primary predictions are rejected, not silently replaced/clamped/promoted to baseline.
- Sufficient results return computed evidence, metrics and prediction; insufficient cases return a reason with no prediction. Invalid JSON/schema returns a JSON error and exit 2. Source data is never changed.

All tests use explicitly synthetic fixtures in isolated tests, never official analytics. Operational storage, realized-target error reconciliation, Forecasting/Model Evaluation UI, and final before-publication freshness locking remain unfinished P6 tasks.
