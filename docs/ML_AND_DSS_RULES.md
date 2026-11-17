# ML_AND_DSS_RULES.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-15

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

### 5.1 C5 decision matrix — for approval, not approved policy

**Reviewed 2026-11-15. All rows remain Blocked-requires approval.** The R1–R7 labels below retain this document's proposed taxonomy; approve the mapping first. The proposal is a discussion draft: Table 11 (p10)/§10.3 (p11) list seven focuses, while Figure 5 (p21) labels an R1–R6 engine and a separate solar branch. Figure 5 names High/Medium/Low, but gives no per-rule assignment or severity boundaries. Table 12's unusual-pattern/demand-spike alert examples do not make every R3/R4 result High.

**TBD means no approved value/definition is available, not zero or a suggested default.** Units identify the relevant quantities; the approver must select the actual measure/formula. Evidence entries are fields to agree for the future rule, not claims that those inputs or an evaluator already exist.

| Proposed rule | Trigger decision still needed | Units / dimensional boundary | Threshold decision still needed | Evidence to specify | Priority |
| --- | --- | --- | --- | --- | --- |
| **R1 Budget** | Informational estimate every eligible month, or alert on a defined target/budget comparison? Confirm tariff source and budget period. | Forecast kWh; tariff PHP/kWh; estimate PHP. Demand-charge basis/units require separate definition. | Tariff/effective dates and any breach amount/percentage: **TBD**. | Issued run/target month, tariff source/version, included/excluded charges, approved target/budget and calculation. | **TBD** |
| **R2 Expected change** | Which forecast/actual pair and same-building history window? Which change direction is actionable? | kWh difference or % with an explicit reference denominator. | Change limits, comparison operator and persistence window: **TBD**. | Compared periods/values, verified source versions, reference calculation and operational context. | **TBD** |
| **R3 Unusual pattern** | Define “typical,” observed-versus-forecast scope, baseline window and treatment of known operational changes. | kWh or % deviation; any statistical score must be defined with its units. | Deviation method, cutoff and required history: **TBD**. | Actual/reference values and periods, baseline method, source lineage and relevant event notes. | **TBD** |
| **R4 Demand** | Demand spike, low load factor, or distinct conditions? Define meter/building scope and comparison period. | Billed demand kW; load factor = kWh ÷ (kW × calendar-month hours), dimensionless. No kW/monthly-kWh comparison or summed coincident peak. | Demand/load-factor cutoffs, reference window and persistence: **TBD**. | Same-meter/period verified kWh, billed kW, hours, reference values and missing/zero-demand handling. | **TBD** |
| **R5 Conservation** | Which evidence conditions justify which approved suggestion? Define overlap with R2–R4/targets. | Use the selected evidence measure; any assumed reduction must explicitly state kWh or %. | Eligibility/target gap and any assumed reduction: **TBD**. | Approved suggestion catalogue, supporting condition/source, explicit assumptions and limitations; no automatically attributed savings. | **TBD** |
| **R6 Solar screening** | Show explicit-assumption screening, or fire on a defined solar/offset condition? Confirm historical proxy and scope. | Solar energy kWh/m²/day, capacity kWp, performance ratio dimensionless, target days; output kWh/%. Not instantaneous kW/m². | Screening cutoff, proxy window and capacity/ratio policy inputs: **TBD**; no defaults. | NASA units/source month/hash, explicit capacity/ratio, target days, forecast and formula. No feasibility/ROI conclusion. | **TBD** |
| **R7 Sufficiency** | Which missing/gapped/stale/quality conditions produce a notice? Is it a notice or an actionable recommendation when no forecast exists? | Month counts/coverage %, age with defined time unit; any error metric retains its own units. | Coverage/age/quality criteria: **TBD**. Existing 16-month guard is technical, not approved confidence policy. | Expected/verified/missing periods and meters, weather completeness, eligibility reason; forecast/evaluation evidence only when available. | **TBD** |

**Shared decisions to return with the matrix:**

- Confirm rule-code mapping, per-building applicability/parameter ownership, history windows, exact operators/equality/rounding, and missing/partial/stale/zero-denominator behavior. Unavailable inputs must not be converted to zero or a favorable conclusion; specify the notice/withholding behavior and rule precedence/overlap.
- Define priority criteria and any escalation/alert mapping, required evidence fields and source/policy version/effective dates. Preserve building scope, verified-only history, current-source checks and retained correction/audit history. Existing empirical error bands do not support a calibrated confidence label.
- For each row record **Approve / Revise / Defer**, the completed definitions/values (or explicitly no numeric trigger for an informational output), rationale/source, applicable buildings, named approver and effective version/date. All decisions are currently **Pending**. Proposal §7.3 identifies the Energy Manager as business owner; Budget Officer input is needed for tariff/budget terms and Facilities input for demand/building/solar context. Named approval authority remains for the user/City Hall to confirm.

### 5.2 Implementation boundaries exposed by the review

- **R7/no-forecast mismatch:** migration `2026_11_14_000010_create_recommendation_workflow_tables.php` requires `recommendations.forecast_id`; `RecommendationReadiness::reason()` requires the current issued forecast for a decision. A no-forecast sufficiency notice cannot simply be inserted as an ordinary actionable recommendation. Decide the intended notice/workflow contract before any schema/behavior change; preserve existing eligibility notices and do not manufacture a forecast. This is part of unresolved C5, not a change made by this review.
- **Arithmetic is not trigger policy:** §7's verified explicit-input conservation/solar calculations do not approve R5/R6 firing conditions or saved assumptions. R1 tariff/cost outputs remain unavailable. Prior external-code/prototype constants recorded in the handoff are not approval candidates/defaults and are not copied into this matrix.
- Source inspection confirms `config/recommendations.php` declares `policy_fingerprint => null`; no runtime/cache check or activation was performed. A completed approval matrix would authorize the next scoped specification/implementation work, not prove an evaluator exists or permit activation by arbitrary hash. P9 human review, real-data authorization and production security approvals remain separate gates.

### 5.3 C5 approval worksheet — recommended options and assumptions

**Prepared 2026-11-15. Proposed only; every decision is Pending.** These are agent recommendations requested for review, not source-derived City Hall policy or implemented changes. Keep §5.1's unit/evidence/missing-value requirements and §5.2's compatibility boundaries. No numeric trigger, tariff, performance assumption or High/Medium/Low assignment is supplied here.

**Mapping decision (Pending):** recommend retaining §5's seven distinct business focuses as the review taxonomy and explicitly reconciling Figure 5's engine/solar labels against it. **Assumption:** these seven focuses represent the intended scope. Alternative: return a revised code-to-focus map; do not infer a mapping from the legacy conditionals.

| Rule | Recommended option — unapproved | Alternative for reviewer choice | Assumption to confirm | Decision |
| --- | --- | --- | --- | --- |
| **R1 Budget** | Start with an **informational energy-charge estimate**, only with an approved effective tariff; label exclusions. Without tariff, retain unavailable monetary output. | Full bill/budget-breach advice only after charge components, budget cycle/amount and breach criteria are approved. | An explicitly limited energy component is useful and will not be mistaken for the full Meralco bill or an approved budget. | Pending |
| **R2 Expected change** | Describe issued next-eligible forecast versus the latest complete verified month of the **same building**, with kWh difference and % only for a positive reference. Defer alerting until limits are approved. | A separately approved historical-reference window or actual-to-actual comparison. | Latest verified month is an appropriate projected-change reference; seasonality/operating changes are shown as context, not claimed causes. | Pending |
| **R3 Unusual pattern** | Use verified actuals versus an explicit, comparable **same-building historical baseline** for human review; withhold classification until method/window/cutoff are approved. | Forecast-deviation screening with its own approved baseline and limitations; or defer R3. | Available history is sufficiently comparable; a flag warrants review, not an error diagnosis, deletion or automatic correction. | Pending |
| **R4 Demand** | Review billed demand **per meter** against an approved comparable period; present calendar-hour load factor as separate context. | Load-factor-only review under an approved cutoff, or defer where demand is absent. | Monthly billed kW supports historical review, not future peak prediction or coincident building-peak inference. | Pending |
| **R5 Conservation** | Link approved evidence conditions to a **human-reviewed suggestion catalogue**; make no default saving percentage or savings guarantee. | Target-gap suggestions after target ownership, period and eligibility are approved; or defer R5. | Bill-level evidence can justify investigation/suggestions, not identify equipment causes or establish measured savings. | Pending |
| **R6 Solar** | Retain **on-demand, explicit-input what-if screening**; do not introduce an automatic irradiance-based recommendation trigger. | A separately approved automated screening condition with source window, cutoff, assumptions and limitations; or defer generated R6. | Historical gridded solar is only a proxy; energy-equivalent offset does not establish roof suitability, self-consumption, feasibility or ROI. | Pending |
| **R7 Sufficiency** | Use a **non-actionable, building-scoped readiness notice even without a forecast**; report actual missing inputs/eligibility reasons. Keep it outside recommendation acceptance/action creation. | Approve a separate persisted notice/history design; or retain existing eligibility messages and defer a new R7 notice feature. | Data remediation should be visible without inventing a forecast or requiring a recommendation decision. | Pending |

**R7 without a forecast — recommended contract, not implemented:** derive the notice from current authorized building/meter coverage, verification and weather/eligibility state. Identify the as-of time, relevant periods, missing inputs and applicable technical guard; omit forecast values/ID when none exists. When a run is stale, describe it as unavailable for current use rather than reuse its values. A notice is not a stored `Recommendation`, has no accept/reject/defer or action-creation path, and cannot unlock issuance. Resolve it by correcting source inputs and reassessing current readiness. Do not create a placeholder forecast, relax the required forecast FK/current-source decision gate, or call the technical 16-month guard approved confidence. A persisted notice/audit/acknowledgment lifecycle, overdue timing or alert delivery would require its own approved scope; none is assumed here.

**Complete for each selected option (all blank/Pending):**

- **Selection:** rule/mapping ID; recommended option / alternative / revised wording; Approve / Revise / Defer; confirm or correct the stated assumption.
- **Executable definition:** building/meter scope; source/reference periods and formula; threshold **value + unit + operator + window** or explicitly “informational, no numeric trigger”; missing/zero/stale behavior; required evidence fields from §5.1; priority/alert criteria or explicitly “non-actionable notice/informational output, no recommendation priority.” No blank is a default.
- **Authority:** rationale/source, named business approver, tariff/budget input owner where relevant, policy version/effective date. Reviewer selections/assumptions are not recorded as approved until actually returned and confirmed.

Approving these options alone does not close C5: unresolved parameters/deferred rules must be explicit in a coherent contract, then separately scoped implementation and verification are needed. Rule generation remains disabled. Verified scenarios/readiness messages, P9 human review and City Hall data/privacy/production-security gates retain their current status.

### 5.4 Project-owner C5 approval checklist — plain-language summary

**Status: awaiting owner decisions.** “Recommended default” below means a suggested starting choice for discussion, **not** an approved or programmed setting. No numerical limits, prices, saving assumptions or urgency levels have been selected. This checklist summarizes §5.1–5.3; their detailed requirements remain in place.

- [ ] Confirm that the seven purposes below are the intended rule list; record any changes. The proposal's written list and diagram still need to be reconciled.
- [ ] For **each R1–R7**, choose **Approve approach / Request changes / Defer** and complete the approval fields below. Nothing is pre-approved.

| Rule / purpose | Recommended default — for confirmation | Owner needs to confirm |
| --- | --- | --- |
| **R1 — Budget estimate** | Show an energy-charge estimate only after an approved electricity rate is available; clearly say what charges are excluded. Otherwise show cost unavailable. | Rate source and applicable dates, who confirms it, and whether an energy-only estimate is sufficient. Full-bill estimates or budget warnings need separately agreed charges, budget period and warning limits. |
| **R2 — Expected change** | Compare the next eligible month's forecast with the same building's latest complete, checked month; show the difference without automatic alerts. | Is that comparison useful, or should different past months be used? Which increases/decreases should eventually prompt review? |
| **R3 — Unusual use** | Flag unusual use for a person's review only after the comparison method and limits are agreed; do not automatically change records. | Which past months represent normal use, what difference deserves review, and how closures, renovations or equipment changes should be considered. |
| **R4 — Peak demand** | Review the bill's demand readings separately for each meter; do not treat their sum as the building's simultaneous peak or predict a future peak from monthly energy use. | Which months to compare, which readings are available, and when a person should investigate. |
| **R5 — Conservation advice** | Use a reviewed list of practical suggestions supported by the available evidence; promise no saving percentage. | Who approves the suggestions, which situations justify each one, and what evidence is required. |
| **R6 — Solar screening** | Keep solar as an on-request “what-if” using clearly stated assumptions, without automatic installation advice. | Who supplies/checks the assumed system size and performance, and acceptance that the result is not a site study, savings guarantee or investment approval. |
| **R7 — Missing or insufficient data** | Explain what information is missing **even when no forecast can be produced**. Treat this as a status message, not advice requiring acceptance or creating an assigned action. | Agree on the message's audience and explanation; confirm that it must not invent a forecast or allow forecasting before its requirements are met. Saved notice history, reminders or acknowledgments need separate agreement. |

**Approval fields — complete one record for each rule:**

- **Rule:** ______ · **Decision:** [ ] Approve approach [ ] Request changes [ ] Defer
- **Changes / assumptions confirmed or corrected / reason:** ______
- **Buildings/meters and months covered; information supporting the result:** ______
- **When should a warning appear?** ______ (include any agreed amount, unit and comparison period, or explicitly write “information only; no automatic warning”). **Urgency, who reviews it and notification choice:** ______ (or explicitly no alert).
- **Outstanding decisions and person responsible:** ______
- **Owner/approver name and office:** ______ · **Consulted Energy Manager / Budget Officer / Facilities, as relevant:** ______
- **Decision date:** ______ · **Document version and intended effective date:** ______ · **Approval/signature or approval reference:** ______

- [ ] Before handing back, identify every remaining blank or deferred decision. A blank is **not** permission to choose a number or turn on a rule. Approval of an approach does not supply missing limits or approve a full electricity bill estimate.
- [ ] Record the intended next step: complete the agreed details, then separately authorize development and testing. This checklist does **not** activate rules or approve production use. City Hall data/privacy/security approvals and the outstanding human accessibility review remain separate.

## 6. Recommendation workflow (proposal §10.3 + Figure 5)

1. Rules fire from verified data + forecast.
2. Energy Manager reviews each recommendation with its evidence.
3. Decision: accept / reject / defer — recorded with actor and timestamp.
4. Accepted items become tracked actions (owner, due date, outcome).
5. Alerts for high-priority items (unusual pattern, demand spike) — proposed Table 12.
6. Everything audited.

**Independent foundation Existing and verified (P7):** stored recommendation must be evaluated under the matching configured policy fingerprint and current issued forecast/source; Energy Manager only, fresh actor, locked sources, version check, required reason. Pending/deferred may transition to accepted/rejected/deferred; accepted/rejected are final. Acceptance creates exactly one authorized assigned action with explicit due date. Decision snapshots and before/after audit are atomic; history retained. Production `config/recommendations.php` policy is null, so the workflow cannot turn unapproved stored advice into actions. Tests enable synthetic contracts only in isolated testing.

**P8 follow-through Existing and verified:** Energy Manager can record a required human outcome on retained accepted actions (Open→Open follow-up or final Open→Completed), with version checks/locked fresh actor/accepted decision ledger and atomic before/after/history/audit. Historical source/policy does not stop factual follow-up; no recommendation or action is created through this endpoint, and C5 remains disabled. Outcomes are reported observations, not automatically attributed savings or model predictions. Owner self-service, reopening/reassignment, extra states and overdue policy remain unspecified extensions; see permission doc for the conservative v1 role/source contract.

## 7. What-if scenarios (Existing and verified P7)

Apply a chosen percentage change to the forecast → estimate kWh and cost effect. **Arithmetic on the forecast — not a prediction of how a measure will perform.**

Current issued source required; percentage explicitly supplied. Solar additionally requires paired explicit kWp/performance-ratio inputs, uses a labeled historical NASA POWER daily-energy proxy, standard 1kW/m² irradiance normalization and target calendar days. Energy-equivalent offset ceiling is not engineering feasibility, self-consumption, ROI or procurement advice. Solar and conservation independently reference the unchanged issued baseline; not combined. Tariff unavailable → monetary results null. Assumptions/source audited; no scenario changes official bills, datasets or issued forecasts.

## 8. Design principle (binding)

> "Forecasting estimates; rules explain. Every recommendation shows the numbers behind it, so the Energy Manager can accept, challenge or reject it. The system supports decisions; it does not make them." — proposal §3

## 9. Cross-references

- Forecast/evaluation tables → `docs/DATABASE_DESIGN.md` §2.2
- Python invocation architecture → `docs/SYSTEM_ARCHITECTURE.md` §4
- Build order → `docs/IMPLEMENTATION_PLAN.md` (P6, P7)
