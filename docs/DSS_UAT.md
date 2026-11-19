# DSS synthetic browser acceptance — 2026-11-19

## Result

**PASS: 20 acceptance checks, 0 failed.** Executed against the application evaluator and real HTTP decision/action/scenario workflows in the isolated `testing` database. This is automated browser acceptance evidence; office approval, human assistive-technology acceptance and production sign-off remain pending.

No application bug was confirmed. No application code, migration, operational policy or normal-runtime data was changed during this UAT task. The pre-existing uncommitted evaluator implementation was preserved.

## Environment and preservation

- Chromium, built application assets, desktop 1440×1000 and mobile 390×900.
- Temporary listener `http://localhost:8089`; `APP_ENV=testing`, configured and actual database `testing`. The router refuses any other environment/database and requires the shipped empty activations and null legacy fingerprint before applying an in-process synthetic activation.
- `ReviewedDssData`, `ForecastData` and `BillingEvidenceData` supplied fictional Highrise/Treasury fixtures. Configuration versions were drafted/submitted/independently approved through `DssConfigurationWorkflow`; tariff source confirmation used Budget Office. Forecasts came from `ForecastIssuance`. The test-only `SyntheticRecommendationEvaluator` was used for its isolation guard, not as the application evaluator or decision adapter.
- Highrise had two meters, Treasury one; each had 16 complete fixture observations. Only the latest month had independently verified financial evidence; the preceding 15 periods correctly remained financially incomplete. No real utility records or inferred service associations were imported.
- Before and after browser operation, and again after regressions, **all 19 monitored normal-runtime table counts/hashes matched**. This was a read-only snapshot of the existing local database. Normal runtime retained `activations = []`, `policy_fingerprint = null` and zero recommendations/actions.
- Browser evaluations, decisions and scenarios left 14 protected testing-source/configuration table hashes unchanged. The final R7 case then intentionally changed one synthetic monthly record to draft to verify source withdrawal.
- Listener and `dss-uat-forward` sidecar were stopped before the full regression suite; their absence was verified afterward.

## Acceptance results

| Case                                      | Result | Observed evidence                                                                                                                                                                                                                                                                                        |
| ----------------------------------------- | ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| UAT-01: default disabled runtime contract | PASS   | Approved fixture versions alone did not enable Run evaluation. Forged evaluation POST returned 422 and produced no recommendations.                                                                                                                                                                      |
| UAT-02: authorized application evaluation | PASS   | Energy Manager Run evaluation produced six Highrise subjects: R1, R2, R3, two separate R4 meters and R5-01. Each carried numeric evidence, configured comparison and pinned dependencies. No action was created by evaluation.                                                                           |
| UAT-03: R7 ready state                    | PASS   | Current issued forecast; 16 complete observations; coverage opened by keyboard. No R7 recommendation was persisted.                                                                                                                                                                                      |
| UAT-04: accept/reject/defer               | PASS   | EM accepted R1 and R5, rejected R2 and deferred R3 through the dialog. Exactly two actions and four decision events resulted.                                                                                                                                                                            |
| UAT-05: deferred acceptance and retries   | PASS   | R3 deferred → accepted created the third action. A stale repeat acceptance and attempted reopening of rejected R2 returned 422. Re-evaluation reused six rows and preserved all decisions.                                                                                                               |
| UAT-06: Action Tracker                    | PASS   | Three accepted subjects, three distinct recommendation IDs, explicit owner and 2026-12-20 due date. No action for rejected R2 or pending R4.                                                                                                                                                             |
| UAT-07: building scope                    | PASS   | Treasury separately generated five subjects, all tied to its forecast. Its cost estimate was PHP1,050.00 versus Highrise PHP2,100.00. A Highrise-only activation could not authorize Treasury. Admin/Budget/Viewer building-selector changes loaded Treasury-only recommendations.                       |
| UAT-08: source preservation               | PASS   | Browser evaluation/decisions/scenarios did not change billing, identity, dataset, forecast or reviewed configuration tables.                                                                                                                                                                             |
| UAT-09: policy withdrawal                 | PASS   | With shipped empty activation configuration restored in the test listener, counts became zero, Run evaluation disabled, decision controls disappeared, retained evidence was labeled historical, and pending-subject decision/evaluation POSTs returned 422. Existing three actions remained historical. |
| UAT-10: R7 stale source                   | PASS   | One synthetic latest bill became draft: 15/16 complete observations and one unverified meter-month; no current issued forecast or current advice. Evaluation returned 422; Solar required a current forecast.                                                                                            |
| UAT-11: normal-runtime preservation       | PASS   | Read-only normal-runtime snapshots matched across all 19 tables, including after full regressions. No operational activation.                                                                                                                                                                            |

### Explainable numbers verified in the browser

| Rule | Synthetic Highrise result                                                                                                                                  |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1   | 840 forecast kWh × PHP2.5/kWh = **PHP2,100.00**, energy-only basis, included/excluded charges and pinned tariff/rule versions shown.                       |
| R2   | Forecast 840 versus verified 800 = **+40 kWh / +5%**.                                                                                                      |
| R3   | Verified 800 versus exact three-month average 720 = **+80 kWh / +11.111%**.                                                                                |
| R4   | Each meter: actual 15 kW versus reference average 10 kW = **+5 kW / +50%**; independent subjects.                                                          |
| R5   | Value **1** = matching independently reviewed catalogue case plus available system evidence. Not savings, scoring or an operational eligibility inference. |

Final synthetic ledger: **11 recommendations**, **5 decision events**, **3 actions**. Action IDs 22/23/24 corresponded to accepted recommendation IDs 74/79/76 (R1/R5/R3). Rejected R2 and all pending subjects had no action. No R6/R7 recommendation was stored.

### Five-role matrix — 4 role checks plus 5 Solar checks passed

Energy Manager capabilities were exercised in UAT-02–06. The other four roles were separately authenticated and checked with actual denied POSTs as well as UI assertions.

| Role                 | Evaluate                   | Decide                      | Building/cost/history                                                                                                                 | Solar installation estimate                                                             |
| -------------------- | -------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Admin                | Allowed; retry reused rows | Denied 403                  | Highrise/Treasury; costs and historical rates allowed                                                                                 | Documented estimate passed                                                              |
| Energy Manager       | Allowed                    | Accept/reject/defer allowed | Highrise/Treasury; costs and historical rates allowed                                                                                 | Documented estimate passed; missing documentation 422; omission returned to energy-only |
| Budget Officer       | Denied 403                 | Denied 403                  | Highrise/Treasury; read-only costs/historical rates                                                                                   | Documented estimate passed                                                              |
| Viewer               | Denied 403                 | Denied 403                  | Highrise/Treasury; read-only costs/historical rates                                                                                   | Documented estimate passed                                                              |
| Facilities Personnel | Denied 403                 | Denied 403                  | Assigned Highrise only; Treasury DSS/Solar 404. R1, financial history and cost-marked actions excluded, including direct `view=rates` | Input absent; forged installation POST 403; energy-only calculation passed              |

Historical rates for authorized roles were **PHP2.500000 gross** and **PHP2.450000 net-service per import-kWh**, explicitly historical, with financial gaps withheld. Private fixture account/document/component strings were absent from the historical projection.

All five roles submitted the energy scenario: 840 → **756 kWh** at 10% assumed reduction; explicit 2.5 kWp/0.8 performance ratio produced **240 kWh** solar screening. Four cost-authorized roles submitted the optional documented rate: 2.5 × PHP100.002 = **PHP250.01**. No default price or tariff-dependent electricity saving was asserted.

## Rendering and accessibility evidence

- **22 scans**, **28 screenshots**, zero axe WCAG 2 A/AA and 2.1 AA violations, JavaScript page errors or page overflow.
- Five decision dialogs each retained keyboard focus across 12 Tab presses. R7 coverage opened/closed by Enter.
- Retained axe inconclusive results: 15 `aria-hidden-focus` targets in modal background/focus guards; focus traversal stayed within the active dialog. Of 255 inconclusive contrast targets, 125 were in the aria-hidden modal background; the other **130 were checked after scrolling into view**, minimum computed contrast **6.03:1**. Raw targets and samples remain in the result JSON. No incomplete check was reported as an automatic pass.
- Screenshots were visually reviewed for R1 evidence, three accepted actions, historical rates, installation-cost mobile output, Facilities energy-only mobile output and R7 stale-source mobile state. Human VoiceOver/NVDA behavior was not tested.

## Regression results

| Command                                       | Result                                                                                                                                                           |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `COMPOSER_PROCESS_TIMEOUT=1200 composer test` | PASS — Pint passed; PHPStan zero errors; PHPUnit **360 total / 357 passed / 3 existing skips / 5,258 assertions**                                                |
| `composer test:forecast`                      | PASS — **7/7**                                                                                                                                                   |
| `npm run build`                               | PASS — production assets and Wayfinder generated                                                                                                                 |
| `npm run types:check`                         | PASS                                                                                                                                                             |
| `npx vp lint`                                 | PASS                                                                                                                                                             |
| `git diff --check`                            | PASS                                                                                                                                                             |
| `npm run check`                               | FAIL — same pre-existing formatting issues in 13 Markdown files and `resources/js/components/user-management.tsx`; no application failure or bulk formatting fix |

## Evidence files and screenshots

Local artifact directory: `/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/`.

- `dss-uat-results.json`: all 20 checks, 22 scans, inconclusive targets, contrast samples and 28 screenshot paths.
- `dss-uat-final-ledger.json`: recommendation, decision, action and audit evidence captured before regression database cleanup.
- `dss-uat-normal-{before,after,after-regressions}.json`: normal-runtime count/hash/configuration snapshots.
- Reproduction scripts: `dss-uat-{fixture.php,router.php,browser.cjs,normal-snapshot.php,summary.cjs,closeout.cjs}`. The fixture reuses existing test support and adds uniquely labeled data only in `testing`; the router is test-only. These temporary scripts are not application routes or operational deployment configuration.

Selected screenshots (all synthetic):

- [R1 numeric evidence](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-evidence-R1.png) · [R2](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-evidence-R2.png) · [R3](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-evidence-R3.png) · [R4](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-evidence-R4.png) · [R5](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-evidence-R5.png)
- [Three accepted Action Tracker records](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-three-accepted-actions-desktop.png)
- [Historical rates](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-energy-manager-historical-rates.png)
- [Solar installation inputs/result — mobile](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-admin-solar-mobile.png)
- [Facilities energy-only scenario — mobile](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-facilities_personnel-solar-mobile.png)
- [Disabled default policy](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-normal-policy-disabled.png) · [R7 source withdrawal — mobile](/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/dss-uat-r7-stale-source-mobile.png)

## Remaining approval gates

1. Named operational authority, applicable rule/source/reference-method contracts, thresholds/operators/priorities, effective windows and a separately authorized deployment activation (ML/DSS §5.1–5.4/§5.11).
2. Real tariff/charge scope and budget cycle; R5 per-entry operational evidence, ownership, eligibility/targets/savings assumptions. Synthetic rates and conditions are not defaults.
3. Authorization for real utility data import and explicit account/service associations; Treasury real-data coverage remains unverified by synthetic testing.
4. Office reviewer acceptance, spoken assistive-technology review, and existing ICT/DPO/production operating approvals. Alerts, SMTP, schedules and overdue policy retain their separate gates.
