# AI_HANDOFF.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-14 · Read this file FIRST, every session.

## 1. Current status

- **Project stage:** **P0–P6 complete; P7 partially verified, policy-blocked** (2026-11-14). Scenario arithmetic/UI and independent recommendation decision/action workflow are verified. Rule generation remains blocked by C5.
- **Codebase:** P1–P6 preserved. P7 has explicit-assumption scenarios plus audited decision/history/action storage and a scoped Decision Support page. The production recommendation policy fingerprint is null, so no stored recommendation can be acted on until a reviewed policy evaluator exists. No rule generator or tariff output is implemented.
- **Knowledge base:** P3 code/status conflicts reconciled below; historical baseline statements are not current implementation status.
- **Figma inspection:** P1–P4 frames preserved. P5 Preprocessing `2024:607`, Energy Analytics `2024:776`, Building Comparison `2024:938` inspected/implemented; complete motion inventories found no animation. Maps found no reusable Figma bindings; existing shadcn/theme reused.

## 2. Completed documentation

`AGENTS.md`, `README.md`, `docs/{PROJECT_CONTEXT, SYSTEM_ARCHITECTURE, ROLES_AND_PERMISSIONS, FIGMA_DESIGN_GUIDE, MODULES_AND_FEATURES, DATABASE_DESIGN, ML_AND_DSS_RULES, IMPLEMENTATION_PLAN, IMPLEMENTATION_PROGRESS, AI_HANDOFF}.md`, `opencode.json` (Figwright MCP).

## 3. Completed application modules

- **P1 Global UI + Dashboard (complete):** design tokens + Inter font in `resources/css/app.css`/`vite.config.ts`; branded 262px sidebar with 5 grouped nav sections (18 items, future modules disabled until their phases ship); 70px topbar with user menu; dashboard page (KPI cards, trend, routine, readiness table, notice) fed by `DashboardController` with **real empty states** at P1 completion. P3 supplies real registered-building counts/rows and P4 adds real monthly status counts. Deviations from the frame are documented in the P1 progress entry.
- **P2 Roles + building permissions + Users & Access (complete, 2026-11-14):** `users.role` + `App\Enums\UserRole`; `buildings` + `user_building` migrations (applied on dev DB, pilot HIGHRISE/TREASURY seeded); `role:` middleware alias (server-enforced 403); role/`roleLabel`/`allowedBuildingIds` shared via `HandleInertiaRequests`; admin seeder (idempotent, credentials via `config/seeding.php`); sidebar role filtering + role box shows actual role; Admin Users & Access page (`users-access`, `role:admin`) per frame `2024:2179` — role cards + permission matrix reference, **no user CRUD/role-assignment UI in the frame** (design gap; role changes via tinker/seeder only). Deviations (demo elements excluded, notice adapted) documented in the P2 progress entry.
- Local dev user `admin@example.com` / `password` exists (id 2, role=admin).
- **P3 registry completion (Existing and verified, 2026-11-14):** Admin can create/update/remove buildings and meters; all writes use `role:admin` + `BuildingPolicy@manage`. Removal soft-deletes rows; a building with non-removed meters cannot be removed. Codes/account references remain reserved, and meter building assignment stays fixed on edit. Registry lists all masked meters, supports building filtering and reset, and hides management controls for read-only roles. Facilities registry **and dashboard** are assigned-building-only (including counts); zero assignment yields empty data. Monthly/verified coverage remains zero until P4. See the P3 completion-audit entry in `IMPLEMENTATION_PROGRESS.md` for exact files and checks.
- **P4 Data Management + verification (Existing and verified):** whole-batch CSV validation or explicit-meter manual entry → Draft; uploader/current editor cannot self-verify; Admin/Energy Manager verification requires bill-confirmation checkbox. Corrections require a reason, preserve before/after in `monthly_record_revisions` and audit history, clear verification and require another reviewer. Billing month can be corrected with duplicate protection; meter assignment stays fixed. Version checks/row locks reject stale actions. Audit events cover successful uploads/verifications/corrections and scoped P4 role/policy denials; global audit viewer remains P8. Demo is a separately labeled, presentation-only preview (no IDs, API writes, persistence or official analytics state). Dashboard/registry use real authorized verified-period counts/latest dates; draft counts are real. Coverage counts distinct periods with verified entries, not cross-meter completeness; forecast readiness remains unassessed until P5/P6.

### Source conflicts resolved in this continuation

- **P7 conflict C5 (new, unresolved):** proposal Table 11/§10.3 names R1–R7 but Figure 5 labels R1–R6 and supplies no numeric trigger contract. External `app/Support/EnergyDss.php` has four conditional messages + fallback, not seven named rules, globally scoped history, and kW-vs-monthly-kWh comparisons at 0.18/0.20. Its 1.15/0.90 demand ratios, 3.5/4/5 solar thresholds and >5% change are reference code, not approved City Hall rules. Do not copy those dimensions/scope/defaults. Reconcile exact rule mapping/threshold policy with the user; tariff R1 remains blocked. Explicit-assumption screening/what-if arithmetic can proceed independently, with no fabricated policy trigger or engineering conclusion.
- **P6 visual adaptation:** Forecasting `2024:1073` demo text says 3 months, Model Evaluation `2024:1181` says backtests after 6, and issued demo `2024:3230` selects a baseline/extra candidates. Implementation retains verified technical guard 16, mandatory Linear primary + 3-month baseline, computed stored evidence, and a non-calibrated empirical band. Prototype values/method choices are not working-policy evidence.
- **P6 working model rule retained:** Linear Regression primary + previous-three-month-average baseline only. Optional Ridge/tree challengers are not silently added; C1 remains for broader model-set confirmation. Technical v1 sufficiency guard is 10 training rows + 3 held-out months after 3 warm-up observations (at least 16 contiguous complete observations with required weather). This is a documented implementation guard, not an approved City Hall confidence policy. Error band uses observed walk-forward absolute errors, not an asserted 95% interval.
- **P5:** NASA POWER is user-approved; live Daily/Monthly units, fill and aggregation verified in `NASA_POWER_DATA_CONTRACT.md`. Daily-derived complete monthly means/totals avoid mislabeling monthly mean rainfall as a total. Old capstone coordinates/units provenance absent, so current public-map City Hall reference is explicitly documented. All registered non-removed meters (including inactive) are required for each period because historical operating windows are not recorded. No coincident building peak is invented by summing meter maxima. Dataset fingerprints include required inventory, verified versions, floor area, cutoff and weather hashes; snapshots remain retained after corrections/source refresh.
- P4 intake adaptation: frame `2024:316` describes a browser-only parser and missing-period errors. The implemented production upload validates on Laravel; gaps within stored/uploaded history produce explicit warnings without filling values or blocking a legitimate late bill. Duplicate/invalid rows reject the whole batch. Automated outlier detection is not claimed (thresholds not specified); human bill review remains required. CSV building-only template maps only when exactly one non-removed meter exists; ambiguous mappings require manual entry with explicit meter selection. All schema numeric bounds/precision and the supported date lower bound (1900-01) are validated before storage.
- The prior P3 ledger said Complete, but its own open items and code lacked edit/delete; §4 still told the next agent to start P3. The user requested the first unfinished P3 task, so those CRUD gaps and associated authorization/visibility defects were completed, then verified. P3 is now complete within its registry scope.
- Figma has no edit/remove/form designs. Existing shadcn dialogs were extended, with an Admin-only Actions column and per-meter actions; these are documented implementation extensions, not claimed Figma designs. Facilities frame confirms read-only controls but shows both demo pilots; the implementation uses only assigned buildings and real counts. Its demo markers are excluded, consistent with prior screens.
- `AGENTS.md`'s “fresh starter / no domain features” paragraph describes the original baseline and is now stale; existing P1–P5 code, the P6 contract checkpoint and verified progress entries are authoritative. Historical snapshots in other docs remain dated; affected current-schema/module sections are updated.

## 4. Exact next recommended task

**Next P7 dependency:** resolve C5 with the user: supply a coherent R1–R7 mapping, dimensionally valid trigger definitions/units, numeric thresholds, evidence requirements and priority mapping. Tariff source/budget cycle also remain unresolved for R1. Then implement and test the evaluator before configuring its policy fingerprint. Do not activate `config/recommendations.php` by assigning an arbitrary hash. The independent decision/action workflow and Solar UI are complete within their stated scope; do not repeat them or NASA/worker setup. P8 action lifecycle/report/audit interfaces remain separate planned work and may proceed only where their dependencies/contracts are available. Real billing import remains unauthorized.

**Tooling notes:** artisan/PHPUnit run via Sail; build regenerates Wayfinder with form variants without deleting generated folders. Do not run artisan `wayfinder:generate` (older format). Build before testing a new Inertia page so the manifest includes it. Migrations through `000010` applied additively on dev DB. App: `http://localhost:8082`; no `public/hot` present. Temp Playwright project: `/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/`; latest script `p7-decisions-smoke.js`, isolated router/fixture `p7-decision-{router,fixture}.php`. Both P7 temporary containers stopped/auto-removed. The browser router activates a synthetic policy only in its testing-DB container; production config remains null. Final PHPUnit run refreshed isolated fixtures. Inertia v3 XHR inspection needs the active `X-Inertia-Version` header.

## 5. Pending tasks (ordered)

**Current tooling:** migrations through `000010` are applied, not pending. The prepared Linux `.venv` is ignored and currently installed in Sail; only re-run `bash python/setup.sh` if the runtime is missing or the container is rebuilt. `composer test:forecast` runs the 7 Python tests; `composer test` includes the real Laravel subprocess tests. Browser artifacts/scripts are in the same temp project; isolated containers are removed. Do not repeat completed NASA ingestion or P6 setup.

1. Resolve C5 rule semantics/thresholds with the user, then implement an evaluated source/policy-tracked generator. Production decisions stay disabled until then.
2. P8 action lifecycle UI, reports/audit/operational enhancements; P9 follow with existing blockers respected. Action creation/assignment is verified in P7; action status/outcome editing is not implemented.

## 6. Known technical blockers

| Blocker | Affects | Needs |
| --- | --- | --- |
| City Hall authorization to use real Meralco data (proposal Decision 1) | Importing 17-month Highrise history; any real analytics | User / City Hall — **Blocked** |
| Treasury dataset coverage unverified (proposal Table 20 #1) | Building Comparison, dual-building forecasts | Capstone team — **Blocked** |
| Tariff source + budget cycle (Table 20 #5) | R1 budget rule, Budget Planning | Budget Officer decision |
| Floor areas + operating hours (Table 20 #6) | kWh/m² analytics | Facilities |
| DPO review of user data handling (RA 10173) | Go-live, user accounts | City Hall DPO — **Blocked** |
| Permission matrix confirmation | Locking role capabilities | City Hall offices |
| Forecasting model set ambiguity (conflict C1) | P6 implementation | **User decision: confirm Linear Regression primary + baselines** |
| DSS rule mapping, triggers and priority contract (C5) | P7 rule evaluation and production decisions | User-reviewed coherent R1–R7 policy; current production policy is unavailable |
| Weather source | P5 ingestion | **Resolved:** user selected NASA POWER, T2M/RH2M/PRECTOTCORR/ALLSKY_SFC_SW_DWN; verified contract documented |

## 7. Important decisions (do not relitigate without user)

- Linear Regression = primary forecast model; baselines for comparison; seasonal/tree models gated on 24–36 months (`docs/ML_AND_DSS_RULES.md` §4).
- Forecast next eligible month only.
- Fortify stays; roles via migration + policies/middleware; never frontend-only authorization.
- Wayfinder action pattern for all new pages/controllers.
- Proposal's "Existing" = capstone (`~/Documents/capstone-dss-clone`), not this repo; port concepts deliberately.
- Synthetic demo data never enters official analytics/forecasts.

## 8. Figma inspection status

| Frame | Node | Status |
| --- | --- | --- |
| Admin / Dashboard | `2024:3` | Verified + **implemented** (P1; full-detail Figwright read 2026-11-13) |
| Admin / Users & Access | `2024:2179` | Verified + **implemented** (P2; full-detail Figwright read 2026-11-14). Contains NO user CRUD/role-assignment UI — future user-management UI needs a new frame |
| Admin / Buildings & Meters | `2024:176` | Verified + implemented; Admin CRUD controls extend the existing design conventions because no edit/remove designs exist |
| Facilities Personnel / Buildings & Meters | `2024:7413` | Full-detail read + component/token/icon maps + complete motion read (no animation), 2026-11-14; read-only assigned-building variant browser-verified |
| Admin / Data Management | `2024:316` | Full-detail + reuse maps + complete motion (165 nodes, 0 animated); implemented/browser-verified; server/parser/manual/demo adaptations documented |
| Admin / Verification Queue | `2024:481` | Full-detail + reuse maps + complete motion (126 nodes, 0 animated); implemented/browser-verified; review/correction/history dialogs extend shadcn conventions |
| Admin / Decision Support | `2024:1332` | Full-detail + reuse maps + complete motion (133 nodes, 0 animated); scoped page/decision dialog/browser checks verified. Demo R7/3-month/high-priority example replaced with real empty/policy states |
| Other Energy Manager / Facilities / Budget / Viewer screens | unknown | Pending inspection |
| Figwright MCP | — | Server + plugin verified working (v0.7.0, 2026-11-13); project `opencode.json` configured |

## 9. Latest verified test results

2026-11-14 (P7 independent workflow checkpoint): `composer test` **187 total / 184 passed / 3 existing skips / 1534 assertions**, Pint/PHPStan clean. New workflow suite **9 passed / 200 assertions**; Python **7 passed**; types/build/scoped lint/diff checks passed. Migration `000010` reviewed with pretend and applied additively. Browser `p7-decisions-smoke.js` passed production-default policy block, synthetic testing-only contract activation, accept/defer/reject, explicit owner/due date, exactly one action, retained decision history, Facilities read-only, 1440px/390px/no overflow/JS errors. Both temporary P7 containers stopped/auto-removed before full suite. No generator/threshold/tariff policy invented. Development source counts remain 22 weather / 0 billing / 0 datasets / 0 forecasts / 0 recommendations / 0 actions.

2026-11-14 (P7 scenario fix/checkpoint): remaining `SolarScenarioTest` failure traced to comparing Eloquent create-time raw Python floats/JSON against MySQL-hydrated DECIMAL/canonical JSON. Baseline now reads the persisted row **before** the scenario request; strict full-row `assertSame` remains unchanged. No production code changed for the fix. Target test **1 passed / 21 assertions**; scenario feature+math suites **10 passed / 93 assertions**. Existing Solar UI build/types/lint already passed; browser `p7-scenarios-smoke.js` passed explicit-pair validation, 378-kWh conservation/240-kWh solar arithmetic on a 420-kWh test source, independent scenarios, null cost, unchanged issued point, stale suppression, 390px/no JS errors. Tests/fixtures use isolated testing DB; full-suite update pending the next feature checkpoint.

2026-11-14 (P6 complete): `composer test` **168 total / 165 passed / 3 existing skips / 1241 assertions**, Pint/PHPStan clean; Python **7 passed**; types/build/scoped lint/diff checks passed. Migration `000009` applied additively. Browser `p6-workflow-smoke.js` on isolated testing DB: real issuance/reuse, computed evidence, draft blocks advancement, verified actual/error, next target after verified actual/weather/reprocess, correction retracts dependent run/error, independent reverify updates error, revised run retains history, Facilities privacy/read-only, 390px/no JS errors. Chart target visibility adjustment separately browser-verified on desktop/mobile (`p6-chart-check.js`). Dev confirmed **22 weather / 0 billing / 0 datasets / 0 forecasts**; no real data imported. Temporary browser container stopped after checks; fixtures remain only in testing until the next suite refresh.

2026-11-14 (P6 contract checkpoint): `composer test:forecast` **7 Python tests passed**; final `composer test` **149 total / 146 passed / 3 existing skips / 1027 assertions**, Pint/PHPStan clean. Five new Laravel integration tests invoke the actual prepared worker, enforce current dataset/role/latest-period gates and cover worker failure without source mutations. Setup completed in Sail: Python 3.12.3, scikit-learn 1.6.1 and pinned NumPy/SciPy/joblib/threadpoolctl in ignored `.venv`. No operational forecast endpoint/storage was added. P5 frontend build/types/lint/browser checks above remain verified; no P6 frontend changes yet.

2026-11-14 (P5 complete): final `composer test` **144 total / 141 passed / 3 pre-existing skips / 1011 assertions**, Pint/PHPStan passed. Build/types/scoped lint and diff checks clean. `000008` applied additively. Browser on temporary **testing DB** instance: build/reuse datasets, inspect full lineage, invalid weather-range form, current charts/MoM, common-period comparison/ranks, demo isolation, correction→stale suppression→new version retaining old snapshots, Facilities scope/cost/lineage denials; 1440px/390px, no overflow/JS errors. Found/fixed a projected-building metadata fingerprint bug; added regression assertion. Temp container removed, final suite refreshes isolated fixtures. Dev counts: 22 public weather records, **0 billing records / 0 datasets**.

2026-11-14 (P5 weather checkpoint): Pint/PHPStan passed; full `composer test` **122 total / 119 passed / 3 pre-existing skips / 783 assertions**. Eight new weather tests cover aggregation/units, leap-day missing/fill/null/zero, idempotency/source changes, metadata/network failure atomicity, range validation and CLI. Migration `000007` applied additively. Live sync Jan 2025–Oct 2026 created 22 complete source-tracked monthly rows; repeat January unchanged/no duplicate. Development billing count 0.

2026-11-14 (P4 complete): `composer test` — Pint passed, PHPStan 0 errors, PHPUnit **114 total / 111 passed / 3 pre-existing 2FA skips / 735 assertions**. Types/build/scoped frontend lint passed. Two additive reversible migrations applied. Full Playwright workflow on isolated `testing` DB: Facilities CSV/manual Draft intake, role denial, isolated demo/reset with unchanged official totals, Admin verification, correction→Draft with retained history and editor self-verification disabled, Energy Manager re-verification, real dashboard counts. 1440px/390px screenshots reviewed; no page overflow or JS errors. Billing-month correction/collision and Asia/Manila completed-month boundary covered by PHPUnit. Development monthly records/revisions both 0. Temp test container removed. `git diff --check` clean; prior work preserved, no commit created.

2026-11-14 (P4 intake checkpoint): `composer test` — Pint/PHPStan passed; **99 total / 96 passed / 3 pre-existing skips / 533 assertions**. Types/build passed; scoped frontend lint passed. Sail migration `000005` applied additively. Playwright Admin: dashboard Upload navigation, exact CSV template download, server row-2 validation error, real empty table; 1440px/390px screenshots reviewed, no page overflow or JS errors. No monthly records imported into dev DB; valid CSV/manual behavior tested against isolated `testing` DB.

2026-11-14 (P3 completion audit): `composer test` — Pint passed, PHPStan 0 errors, PHPUnit **82 total / 79 passed / 3 pre-existing 2FA skips / 394 assertions**. `npm run types:check` clean; `npm run build` passed (Wayfinder regenerated). Scoped `vp lint` passed for registry page/types. Playwright: Admin create/edit building, register/edit masked meter, blocked removal with meters, filter/reset, soft-removal; Facilities read-only registry/dashboard with one assigned building; desktop 1440px + mobile 390px (no page overflow), no Admin browser JS errors. Temporary Facilities user removed; temporary registry configuration `P3_UI_1794627021180` and its test meter soft-removed (no monthly/billing records created). `git diff --check` passed.

2026-11-14 (P2): Pint passed · PHPStan 0 errors · PHPUnit 54 tests / 51 passed / 3 skipped (pre-existing 2FA skips) · 144 assertions · `npm run types:check` clean · `npm run build` ✓ · Playwright visual check vs frames `2024:3` + `2024:2179` — match.

2026-11-13 (P1): Pint passed · PHPStan 0 errors · PHPUnit 39 tests / 36 passed / 3 skipped · `npm run types:check` clean · `npm run build` ✓ · Playwright visual check vs frame `2024:3` — match.

## 10. How to continue (any future session)

1. Read `AGENTS.md` → this file → relevant docs for your task.
2. Check §4 next task; check §6 blockers before touching affected areas.
3. Implement ONE planned task; run checks (`composer test`, `npm run types:check`, `npm run build`).
4. Append verified results to `docs/IMPLEMENTATION_PROGRESS.md`; update this file's §1/§4/§9.
5. If interrupted: finish the current file, record the exact next action in §4, stop.

**Rule:** on the instruction "continue", resume from the next incomplete documentation/implementation task listed here — do not regenerate completed docs.
