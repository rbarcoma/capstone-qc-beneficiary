# ML_AND_DSS_RULES.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

**Status:** everything in this doc is **Proposed / Existing in capstone, not ported**. There is no ML or DSS code in this repo yet. No metrics may be quoted as real until computed from verified data here.

## 1. Inputs

- **Consumption history:** verified monthly records only (kWh; optional billed demand kW, cost, power factor) — `docs/DATABASE_DESIGN.md` §2.1.
- **Weather:** monthly values joined by the system; source recorded per value (NASA POWER or PAGASA). Not part of the upload template (proposal Appendix A).
- **Eligibility:** a building is forecastable when its latest month is **verified** and history meets the minimum. Insufficient history → data-sufficiency notice (R7), not a prediction.

## 2. Forecasting pipeline (proposal §10.2 + Figure 4)

1. Verify month → 2. Refresh weather values → 3. Preprocess verified history → 4. Compare candidate models on walk-forward test months → 5. Forecast **next eligible month** with current model → 6. Store prediction + range + model version + metrics → 7. After the actual bill arrives, record forecast error → 8. Re-run comparison; promote a challenger **only** if it beats the current model on the same test months.

Hard constraints:
- **Horizon: next eligible month only** unless the user explicitly approves otherwise.
- **Invocation model:** Laravel calls a Python subprocess (pandas, NumPy, scikit-learn). FastAPI service = Proposed/optional; do not build without approval.
- **Scheduling:** monthly run after verification (proposed scheduler/queue; no cron in this repo yet).

## 3. Model evaluation

| Metric | Meaning | Status |
| --- | --- | --- |
| MAE | Mean absolute error vs actual bill | Existing in capstone, not ported |
| RMSE | Penalizes large misses | Same |
| R² | Fit on test months | Same |
| Actual vs predicted chart | Per building, per month | Same |
| Prediction range | Optimistic–cautious band for budgeting | Proposed |

**Never fabricate metrics.** Model comparison always reports per-method errors on identical test months.

## 4. Model set — and the conflict to resolve

| Source | Claim |
| --- | --- |
| Proposal §3 Table 2 | Baselines: **previous-3-month average**; models: **Linear and Ridge Regression** |
| Original capstone note (user) | **Linear Regression primary** |
| Capstone `Python/algo/` scripts (external) | `train_linear.py`, `compare_models.py`, `train_random.py`, `train_xgboost.py` (Random Forest, XGBoost as experimental) |

**Working decision (until user overrules):** Linear Regression = primary; previous-3-month average = baseline comparison; Ridge = secondary comparison; Random Forest/XGBoost = experimental only and gated on 24–36 months of history (proposal §8.2: Prophet ~24 months — Highrise hits that with the March 2027 bill; XGBoost ~36+ months and only if it wins walk-forward tests). Tracked in `docs/PROJECT_CONTEXT.md` conflict C1.

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

Status: "Existing (to reconcile with the implementation)" per proposal Table 11 — the capstone rule count differs from other sources; **reconcile against Document 1 / actual ported code when P7 begins.** Thresholds are to become configurable per building (Proposed).

## 6. Recommendation workflow (proposal §10.3 + Figure 5)

1. Rules fire from verified data + forecast.
2. Energy Manager reviews each recommendation with its evidence.
3. Decision: accept / reject / defer — recorded with actor and timestamp.
4. Accepted items become tracked actions (owner, due date, outcome).
5. Alerts for high-priority items (unusual pattern, demand spike) — proposed Table 12.
6. Everything audited.

## 7. What-if scenarios (Proposed)

Apply a chosen percentage change to the forecast → estimate kWh and cost effect. **Arithmetic on the forecast — not a prediction of how a measure will perform.**

## 8. Design principle (binding)

> "Forecasting estimates; rules explain. Every recommendation shows the numbers behind it, so the Energy Manager can accept, challenge or reject it. The system supports decisions; it does not make them." — proposal §3

## 9. Cross-references

- Forecast/evaluation tables → `docs/DATABASE_DESIGN.md` §2.2
- Python invocation architecture → `docs/SYSTEM_ARCHITECTURE.md` §4
- Build order → `docs/IMPLEMENTATION_PLAN.md` (P6, P7)
