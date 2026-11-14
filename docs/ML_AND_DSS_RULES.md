# ML_AND_DSS_RULES.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

**Status (2026-11-14):** P5 preprocessing and P6 Linear/baseline worker, locked versioned issuance/storage, evaluation UI and actual-error reconciliation are **Existing and verified**. P7 explicit-assumption scenarios and independent audited decision/action workflow are **Existing and verified**. DSS rule evaluation/threshold policy remains blocked by C5; production decisions are disabled until a coherent reviewed evaluator exists. Test-fixture metrics are not official building accuracy results.

## 1. Inputs

- **Consumption history:** verified monthly records only (kWh; optional billed demand kW, cost, power factor) — `docs/DATABASE_DESIGN.md` §2.1.
- **Weather:** user approved NASA POWER primary, T2M/RH2M/PRECTOTCORR/ALLSKY_SFC_SW_DWN; P5 complete daily-derived monthly means/totals and source/missing-value lineage implemented. See `NASA_POWER_DATA_CONTRACT.md`. P5 predictor features use **previous-month** weather, not unknown target-month observations. Not part of the billing CSV.
- **Eligibility:** a building is forecastable when its latest month is **verified** and history meets the minimum. Insufficient history → data-sufficiency notice (R7), not a prediction.

## 2. Forecasting pipeline (proposal §10.2 + Figure 4)

1. Verify month → 2. Refresh weather values → 3. Preprocess verified history → 4. Compare candidate models on walk-forward test months → 5. Forecast **next eligible month** with current model → 6. Store prediction + range + model version + metrics → 7. After the actual bill arrives, record forecast error → 8. Re-run comparison; promote a challenger **only** if it beats the current model on the same test months.

Hard constraints:
- **Horizon: next eligible month only** unless the user explicitly approves otherwise.
- **Invocation model:** Laravel calls a Python subprocess (pandas, NumPy, scikit-learn). FastAPI service = Proposed/optional; do not build without approval.
- **Scheduling:** monthly run after verification (proposed scheduler/queue; no cron in this repo yet).

## 3. Model evaluation

**P6 verified:** worker uses 8 past-only predictors, 3 warm-up observations, at least 10 training rows and 3 expanding-window held-out months on the same periods for both methods (minimum 16 contiguous complete months). Scaling fits on each past training window only. Exact units/complete lagged weather required; no target-month observed weather. R² null for constant held-out targets. Empirical error band uses observed maximum absolute held-out error, with **no calibrated confidence probability**. These are documented technical guards, not City Hall-approved confidence policy. Versioned publication rechecks source/runtime/actor under locks; realized error reconciliation retracts corrected/incomplete actuals. See `python/README.md` and verified P6 progress entry.

| Metric | Meaning | Status |
| --- | --- | --- |
| MAE | Mean absolute held-out error | Existing and verified P6 |
| RMSE | Penalizes large misses | Existing and verified P6 |
| R² | Fit on held-out months; null for constant targets | Existing and verified P6 |
| Actual vs predicted chart | Per building, per month; held-out and reconciled realized evidence | Existing and verified P6 |
| Prediction range | Empirical absolute-error band, not calibrated confidence | Existing and verified P6 |

**Never fabricate metrics.** Model comparison always reports per-method errors on identical test months.

## 4. Model set — and the conflict to resolve

| Source | Claim |
| --- | --- |
| Proposal §3 Table 2 | Baselines: **previous-3-month average**; models: **Linear and Ridge Regression** |
| Original capstone note (user) | **Linear Regression primary** |
| Capstone `Python/algo/` scripts (external) | `train_linear.py`, `compare_models.py`, `train_random.py`, `train_xgboost.py` (Random Forest, XGBoost as experimental) |

**Implemented working decision:** Linear Regression = primary; previous-3-month average = baseline comparison. Ridge/other model families remain optional and require C1 clarification; no automatic challenger promotion implemented. Random Forest/XGBoost are experimental proposals gated on longer history. Tracked in `docs/PROJECT_CONTEXT.md` conflict C1 and current handoff.

## 5. DSS rules (proposal §10.3 — R1–R7)

Every recommendation is **rule-based and explainable**: it displays the numbers behind it, and the Energy Manager can accept, challenge, or reject it. The system supports decisions; it does not make them.

| Rule | Focus | Basis |
| --- | --- | --- |
| R1 | Budget estimate | Forecast kWh × tariff → estimated cost (tariff source = open item) |
| R2 | Expected change | Month-over-month change vs history |
| R3 | Unusual pattern | Consumption deviation vs typical pattern |
| R4 | Peak demand | Billed demand review / load factor when available |
| R5 | Conservation | Actionable saving suggestions from evidence |
| R6 | Solar pre-assessment | Screening-level offset from irradiance, assumed capacity, performance ratio — **never** an engineering feasibility conclusion |
| R7 | Data sufficiency | Notices on coverage, missing months, forecast confidence |

**Blocked / requires clarification (C5):** this table is proposed intent, not an implemented trigger contract. Proposal Table 11/§10.3 names seven rules, Figure 5 labels six, and external `EnergyDss.php` has four conditionals + fallback with global history and invalid kW/monthly-kWh comparisons. Numeric thresholds, priority mapping and complete evidence/units contract remain unresolved; no generator is implemented. Configurable thresholds per building remain Proposed. R1 additionally requires tariff source/budget-cycle decisions.

## 6. Recommendation workflow (proposal §10.3 + Figure 5)

1. Rules fire from verified data + forecast.
2. Energy Manager reviews each recommendation with its evidence.
3. Decision: accept / reject / defer — recorded with actor and timestamp.
4. Accepted items become tracked actions (owner, due date, outcome).
5. Alerts for high-priority items (unusual pattern, demand spike) — proposed Table 12.
6. Everything audited.

**Independent foundation Existing and verified (P7):** stored recommendation must be evaluated under the matching configured policy fingerprint and current issued forecast/source; Energy Manager only, fresh actor, locked sources, version check, required reason. Pending/deferred may transition to accepted/rejected/deferred; accepted/rejected are final. Acceptance creates exactly one authorized assigned action with explicit due date. Decision snapshots and before/after audit are atomic; history retained. Production `config/recommendations.php` policy is null, so the workflow cannot turn unapproved stored advice into actions. Tests enable synthetic contracts only in isolated testing. Action outcome/status lifecycle UI remains P8.

## 7. What-if scenarios (Existing and verified P7)

Apply a chosen percentage change to the forecast → estimate kWh and cost effect. **Arithmetic on the forecast — not a prediction of how a measure will perform.**

Current issued source required; percentage explicitly supplied. Solar additionally requires paired explicit kWp/performance-ratio inputs, uses a labeled historical NASA POWER daily-energy proxy, standard 1kW/m² irradiance normalization and target calendar days. Energy-equivalent offset ceiling is not engineering feasibility, self-consumption, ROI or procurement advice. Solar and conservation independently reference the unchanged issued baseline; not combined. Tariff unavailable → monetary results null. Assumptions/source audited; no scenario changes official bills, datasets or issued forecasts.

## 8. Design principle (binding)

> "Forecasting estimates; rules explain. Every recommendation shows the numbers behind it, so the Energy Manager can accept, challenge or reject it. The system supports decisions; it does not make them." — proposal §3

## 9. Cross-references

- Forecast/evaluation tables → `docs/DATABASE_DESIGN.md` §2.2
- Python invocation architecture → `docs/SYSTEM_ARCHITECTURE.md` §4
- Build order → `docs/IMPLEMENTATION_PLAN.md` (P6, P7)
