# IMPLEMENTATION_PLAN.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

Incremental, phase-gated plan. **No phase is complete because its Figma design exists.** Completion requires code + passing checks + verified progress entry. Status: `Not started` unless marked otherwise.

## P0 — Project audit and environment verification

- **Objective:** prove the dev environment and checks actually run.
- **Existing:** starter kit verified by audit (`docs/PROJECT_CONTEXT.md` §4); `.env` configured for Sail MySQL (forwarded port 3309, DB `capstone_qc_beneficiary`).
- **Tasks:** 1) `./vendor/bin/sail up -d` and confirm MySQL connectivity. 2) `php artisan migrate:fresh` **on local dev only** (starter data only). 3) Run `composer test`, `npm run types:check`, `npm run build` and record results in `docs/IMPLEMENTATION_PROGRESS.md`. 4) Seed an initial admin user (seeder, documented credentials path).
- **Acceptance:** all checks pass with recorded output; no red baseline.
- **Status:** Not started

## P1 — Shared UI layout, branding, and Figma design system

- **Objective:** starter shell matches the verified Dashboard frame (node `2024:3`).
- **Existing:** shadcn sidebar/header shell (`resources/js/components/`), placeholder dashboard page.
- **Tasks:** 1) Map tokens: navy `#173A7A`, background `#F5F7FB`, Inter, 262px sidebar, 70px topbar → Tailwind 4 theme in `resources/css/app.css`. 2) Brand sidebar/header ("QC Energy DSS"; fix APP_NAME typo `DSS Enegy QC` — needs user go-ahead, touches `.env`). 3) Adapt dashboard page structure to the frame (KPI card grid, readiness table, buttons) with **real empty states**. 4) Confirm grouping pattern for nav (role-driven later).
- **Depends on:** P0. **Testing:** `npm run types:check`, build, visual check vs frame.
- **Status:** Not started

## P2 — Authentication, role-based access, and building-level permissions

- **Objective:** five roles + building-level access, server-enforced.
- **Existing:** Fortify auth fully working (starter tests cover it) — preserve.
- **Tasks:** 1) `role` column migration + `App\Enums\UserRole` + reversible `down()`. 2) `buildings` + `user_building` migrations (`docs/DATABASE_DESIGN.md` §2.1). 3) Policies/middleware per `docs/ROLES_AND_PERMISSIONS.md` §3. 4) Users & Access admin UI (Figma frame pending inspection — inspect first). 5) Role/allowed-buildings shared via `HandleInertiaRequests`.
- **Depends on:** P1. **Testing:** feature tests for each permission pair (allowed/denied, server-side); `composer test`.
- **Status:** Not started

## P3 — Building and meter registry

- **Tasks:** `meters` migration (restricted account reference), CRUD controllers (Wayfinder pattern), registry UI (frame pending inspection), pilot seeds HIGHRISE/TREASURY.
- **Depends on:** P2. **Testing:** CRUD + authorization tests.
- **Status:** Not started

## P4 — Data Management, validation, and verification

- **Tasks:** 1) CSV upload per proposal Appendix A template (columns: building_code, month, kwh, billed_demand_kw, cost_php, power_factor, notes). 2) Row-level validation; duplicate/missing month detection. 3) Draft→verified workflow + `monthly_record_revisions` audit. 4) `audit_logs` table + events. 5) Synthetic Demo loader, isolated + flagged. 6) UI (frames pending inspection).
- **Depends on:** P3. **Testing:** validation edge cases (invalid, duplicate, missing, late bill), permission tests, correction-preserves-history test.
- **Status:** Not started

## P5 — Preprocessing and Energy Analytics

- **Tasks:** 1) `weather_records` + ingestion (source recorded; NASA POWER/PAGASA — user picks). 2) `datasets`/`processed_records` versioned preprocessing from verified months only. 3) Analytics services: trends, MoM/YoY, change indicators; proposed: cost analysis, kWh/m², load factor, comparison. 4) UI (frames pending inspection).
- **Depends on:** P4. **Testing:** analytics unit tests with fixture data; "verified-only" leakage tests.
- **Status:** Not started

## P6 — ML forecasting and model evaluation

- **Tasks:** 1) Python service scaffold (subprocess; pandas/numpy/scikit-learn; JSON contract). 2) Feature building + walk-forward evaluation. 3) **Linear Regression primary** per `docs/ML_AND_DSS_RULES.md` §4; baselines for comparison. 4) `forecasts`/`forecast_results`/`forecast_errors` storage with model version. 5) Eligibility gate (verified month + sufficiency) → data-sufficiency notice instead of number. 6) UI (frames pending inspection).
- **Depends on:** P5. **Testing:** Python tests ported deliberately from capstone `Python/test_predict_*.py`; Laravel contract tests; **no fabricated metrics**.
- **Status:** Not started

## P7 — Decision Support and renewable-energy pre-assessment

- **Tasks:** 1) Rules R1–R7 (reconcile count against capstone first — conflict C1). 2) Evidence display + accept/reject/defer workflow + `recommendations`/`actions`. 3) Solar pre-assessment (screening-level only). 4) What-if arithmetic. 5) Configurable thresholds (if approved). 6) UI (frames pending inspection).
- **Depends on:** P6. **Testing:** rule unit tests; decision audit tests.
- **Status:** Not started

## P8 — Reports, Audit Logs, and operational enhancements

- **Tasks:** reports (executive summary PDF, Excel export — **verify before treating as complete**, proposal Table 20 #9), alerts/notifications (Table 12), action tracker UI, settings (thresholds/tariffs/targets — tariff source is an open item), audit log viewer, system health.
- **Depends on:** P4–P7 subsets. **Status:** Not started

## P9 — Integration, security, testing, and final review

- **Tasks:** end-to-end monthly cycle test (§11.1 8 steps), security pass (HTTPS/least-privilege/backups = production decisions; several are Blocked on City Hall choices), accessibility + responsive checks, full `composer test` + types + build, documentation refresh.
- **Status:** Not started

## Dependency graph

```
P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8 → P9
             └───────────────┴─ (roles gate every UI action)
```

## Cross-references

- Verified progress per phase → `docs/IMPLEMENTATION_PROGRESS.md`
- Current handoff state → `docs/AI_HANDOFF.md`
