# IMPLEMENTATION_PLAN.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-14

Incremental, phase-gated plan. **No phase is complete because its Figma design exists.** Completion requires code + passing checks + verified progress entry. Status: `Not started` unless marked otherwise.

## P0 — Project audit and environment verification

- **Objective:** prove the dev environment and checks actually run.
- **Existing:** starter kit verified by audit (`docs/PROJECT_CONTEXT.md` §4); `.env` configured for Sail MySQL (forwarded port 3309, DB `capstone_qc_beneficiary`).
- **Tasks:** 1) `./vendor/bin/sail up -d` and confirm MySQL connectivity. 2) `php artisan migrate:fresh` **on local dev only** (starter data only). 3) Run `composer test`, `npm run types:check`, `npm run build` and record results in `docs/IMPLEMENTATION_PROGRESS.md`. 4) Seed an initial admin user (seeder, documented credentials path).
- **Acceptance:** all checks pass with recorded output; no red baseline.
- **Status:** Complete (2026-11-13; verified results in `docs/IMPLEMENTATION_PROGRESS.md`; initial Admin seeding landed in P2)

## P1 — Shared UI layout, branding, and Figma design system

- **Objective:** starter shell matches the verified Dashboard frame (node `2024:3`).
- **Existing:** shadcn sidebar/header shell (`resources/js/components/`), placeholder dashboard page.
- **Tasks:** 1) Map tokens: navy `#173A7A`, background `#F5F7FB`, Inter, 262px sidebar, 70px topbar → Tailwind 4 theme in `resources/css/app.css`. 2) Brand sidebar/header ("QC Energy DSS"; fix APP_NAME typo `DSS Enegy QC` — needs user go-ahead, touches `.env`). 3) Adapt dashboard page structure to the frame (KPI card grid, readiness table, buttons) with **real empty states**. 4) Confirm grouping pattern for nav (role-driven later).
- **Depends on:** P0. **Testing:** `npm run types:check`, build, visual check vs frame.
- **Status:** Complete (2026-11-13; see `docs/IMPLEMENTATION_PROGRESS.md` P1 entry)

## P2 — Authentication, role-based access, and building-level permissions

- **Objective:** five roles + building-level access, server-enforced.
- **Existing:** Fortify auth fully working (starter tests cover it) — preserve.
- **Tasks:** 1) `role` column migration + `App\Enums\UserRole` + reversible `down()`. 2) `buildings` + `user_building` migrations (`docs/DATABASE_DESIGN.md` §2.1). 3) Policies/middleware per `docs/ROLES_AND_PERMISSIONS.md` §3. 4) Users & Access admin UI (Figma frame pending inspection — inspect first). 5) Role/allowed-buildings shared via `HandleInertiaRequests`.
- **Depends on:** P1. **Testing:** feature tests for each permission pair (allowed/denied, server-side); `composer test`.
- **Status:** Complete (2026-11-14; see `docs/IMPLEMENTATION_PROGRESS.md` P2 entry. Note: the frame is a role/matrix reference page — user CRUD/role-assignment UI does not exist in Figma and was not built; entity-level policies land with P3+ entities.)

## P3 — Building and meter registry

- **Tasks:** `meters` migration (restricted account reference), CRUD controllers (Wayfinder pattern), registry UI (Admin `2024:176`, Facilities `2024:7413` inspected), pilot seeds HIGHRISE/TREASURY.
- **Depends on:** P2. **Testing:** CRUD + authorization tests.
- **Status:** Complete (2026-11-14, re-verified by P3 completion audit). Admin building/meter create/update/soft-remove, blocked building removal while meters remain, masked multi-meter listing, working registry filter, Facilities read-only variant, and assigned-building dashboard queries verified. The earlier completion entry lacked edit/delete; the continuation closes those gaps. Modal/edit/remove designs are absent: shadcn dialogs and Admin-only table actions are documented extensions. Monthly records and verified coverage queries belong to P4.

## P4 — Data Management, validation, and verification

- **Tasks:** 1) CSV upload per proposal Appendix A template (columns: building_code, month, kwh, billed_demand_kw, cost_php, power_factor, notes). 2) Row-level validation; duplicate/missing month detection. 3) Draft→verified workflow + `monthly_record_revisions` audit. 4) `audit_logs` table + events. 5) Synthetic Demo loader, isolated + flagged. 6) UI (frames pending inspection).
- **Depends on:** P3. **Testing:** validation edge cases (invalid, duplicate, missing, late bill), permission tests, correction-preserves-history test.
- **Status:** **Complete (2026-11-14)** — CSV/manual intake, atomic validation, duplicate rejection/gap warnings, Draft→independently verified workflow, reasoned corrections with revisions/audits, stale-version guards, presentation-only isolated synthetic demo, real summary counts and UI (`2024:316` + `2024:481`) verified. Full suite 114 total / 111 passed / 3 pre-existing skips; types/build/lint and test-database browser workflow passed. Automated outlier thresholds, overdue timing, preprocessing and forecast eligibility are not P4 claims.

## P5 — Preprocessing and Energy Analytics

- **Tasks:** 1) NASA POWER weather provenance/ingestion (user approved T2M/RH2M/PRECTOTCORR/ALLSKY_SFC_SW_DWN). 2) Versioned verified-only datasets. 3) Trends/MoM/YoY, actual costs, intensity, conditional load factor and common-period comparison. 4) UI: `2024:607`, `2024:776`, `2024:938` inspected.
- **Depends on:** P4. **Testing:** analytics unit tests with fixture data; "verified-only" leakage tests.
- **Status:** **Complete (2026-11-14)** — weather contract/live ingestion, retained lineage, complete-building validation, past-only lags, stale-data suppression, analytics/comparison and UI verified. Final backend 144 total / 141 passed / 3 existing skips, types/build/lint/browser checks green. No real billing history imported.

## P6 — ML forecasting and model evaluation

- **Tasks:** 1) Python service scaffold (subprocess; pandas/numpy/scikit-learn; JSON contract). 2) Feature building + walk-forward evaluation. 3) **Linear Regression primary** per `docs/ML_AND_DSS_RULES.md` §4; baselines for comparison. 4) `forecasts`/`forecast_results`/`forecast_errors` storage with model version. 5) Eligibility gate (verified month + sufficiency) → data-sufficiency notice instead of number. 6) UI (frames pending inspection).
- **Depends on:** P5. **Testing:** Python tests ported deliberately from capstone `Python/test_predict_*.py`; Laravel contract tests; **no fabricated metrics**.
- **Status:** **Complete (2026-11-14)** — worker retained; versioned storage, reviewed/final locked issuance checks, idempotency, finite/source-matched output validation, realized-error retraction/reconciliation and scoped UI verified. Backend168 /165 passed/3 existing skips, Python7, types/build/lint/browser checks passed. Optional model promotion/scheduling remain separately proposed; see worker README for guard/band semantics.

## P7 — Decision Support and renewable-energy pre-assessment

- **Tasks:** 1) Rules R1–R7 (reconcile count/units/triggers — conflict C5). 2) Evidence display + accept/reject/defer workflow + `recommendations`/`actions`. 3) Solar pre-assessment (screening-level only). 4) What-if arithmetic. 5) Configurable thresholds (if approved). 6) UI (Solar `2024:1584`, Decision Support `2024:1332` inspected).
- **Depends on:** P6. **Testing:** rule unit tests; decision audit tests.
- **Status:** **Partially Existing and verified; rule-policy blocked (2026-11-14).** Explicit-assumption scenarios and audited Energy Manager decision/action workflow + scoped UI verified. Full backend187 /184 passed/3 existing skips; Python7, types/build/lint/browser checks passed. Default-null policy configuration prevents production decisions even on stored evaluated rows. Exact C5 rule/threshold/evidence/priority contract and tariff inputs unresolved; no generator or policy invented. Action outcome/lifecycle UI stays P8.

## P8 — Reports, Audit Logs, and operational enhancements

- **Tasks:** reports (executive summary PDF, Excel export — **verify before treating as complete**, proposal Table 20 #9), alerts/notifications (Table 12), action tracker UI, settings (thresholds/tariffs/targets — tariff source is an open item), audit log viewer, system health.
- **Depends on:** P4–P7 subsets. **Status:** **Unblocked scope Existing and verified; gated enhancements pending (2026-11-15)** — audit viewer, scoped minimal Action Tracker, INTERNAL PDF/Excel Reports, current-source in-app Notifications, Admin measured System Health and Admin/EM read-only System Settings verified. Final backend243 /240 passed/3 existing skips, Python7, frontend/browser checks passed. No migration after `000012`. Numeric settings/SMTP/scheduled notifications/overdue/error-tolerance and optional delegated lifecycle retain their contract/dependency gates. C5 generator disabled, no automatic savings attribution or production-readiness claim.

## P9 — Integration, security, testing, and final review

- **Tasks:** end-to-end monthly cycle test (§11.1 8 steps), security pass (HTTPS/least-privilege/backups = production decisions; several are Blocked on City Hall choices), accessibility + responsive checks, full `composer test` + types + build, documentation refresh.
- **Status:** **In progress — local technical review verified; reviewer/production acceptance pending (2026-11-15)**. Retained monthly-cycle real-worker/default-null-C5 integration1/165, full243 tests/Python7 and clean dependency checkpoints. Expanded local accessibility adds73 passing core/dialog/error/auth/settings/mobile cases; prior24 P8 cases regression-checked for shared token/focus fixes. Scoped inconclusive items reviewed with178 computed-color samples, keyboard containment/scrolling and accessibility-tree/empty-table evidence. Two new security cases verify real CSRF middleware on19 mutation routes and registration privilege-injection resistance; no application backend change. Final frontend/Pint/PHPStan checks passed. Human-operated assistive-technology review and City Hall policy/privacy/hosting/backup/TLS acceptance remain pending; coverage is not universal WCAG certification. See `docs/P9_VERIFICATION.md`.

## Dependency graph

```
P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8 → P9
             └───────────────┴─ (roles gate every UI action)
```

## Cross-references

- Verified progress per phase → `docs/IMPLEMENTATION_PROGRESS.md`
- Current handoff state → `docs/AI_HANDOFF.md`
