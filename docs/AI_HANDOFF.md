# AI_HANDOFF.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13 · Read this file FIRST, every session.

## 1. Current status

- **Project stage:** P0 + P1 complete (2026-11-13). P2 next.
- **Codebase:** Laravel 13 + React 19 starter kit with branded shell + admin dashboard implemented to Figma frame `2024:3` (see `docs/IMPLEMENTATION_PROGRESS.md` P1 entry). Auth pages, settings, shadcn UI kit; **no domain features yet** (buildings/meters/records land in P2/P3+).
- **Knowledge base:** all 11 documentation files exist and are consistent (validated 2026-11-13).
- **Figma inspection:** Admin Dashboard frame `2024:3` verified twice + full-detail Figwright read used for P1 (tokens + layout). All other role frames **pending inspection**.

## 2. Completed documentation

`AGENTS.md`, `README.md`, `docs/{PROJECT_CONTEXT, SYSTEM_ARCHITECTURE, ROLES_AND_PERMISSIONS, FIGMA_DESIGN_GUIDE, MODULES_AND_FEATURES, DATABASE_DESIGN, ML_AND_DSS_RULES, IMPLEMENTATION_PLAN, IMPLEMENTATION_PROGRESS, AI_HANDOFF}.md`, `opencode.json` (Figwright MCP).

## 3. Completed application modules

- **P1 Global UI + Dashboard (complete):** design tokens + Inter font in `resources/css/app.css`/`vite.config.ts`; branded 262px sidebar with 5 grouped nav sections (18 items, non-Dashboard disabled until phases ship); 70px topbar with user menu; dashboard page (KPI cards, trend, routine, readiness table, notice) fed by `DashboardController` with **real empty states** (no buildings/records exist yet — nothing hardcoded). Deviations from the frame are documented in the P1 progress entry.
- Local dev user `admin@example.com` / `password` exists (id 2, tinker-created for visual verification). Formal admin seeder still deferred to P2.

## 4. Exact next recommended task

**P2 — Authentication, role-based access, and building-level permissions** (`docs/IMPLEMENTATION_PLAN.md` §P2): `role` column migration + `App\Enums\UserRole` (reversible `down()`); `buildings` + `user_building` migrations per `docs/DATABASE_DESIGN.md` §2.1; policies/middleware per `docs/ROLES_AND_PERMISSIONS.md` §3 (server-enforced, never frontend-only); role/allowed-buildings shared via `HandleInertiaRequests`; admin user seeder; wire sidebar role filtering (P1 `NavGroups` already role-ready) and swap the sidebar role-workspace box from user name to actual role. Users & Access admin UI requires a Figma frame inspection first (pending). Feature tests for each permission pair; `composer test`.

**Tooling notes for every session:** artisan/PHPUnit must run via `./vendor/bin/sail`; regenerate Wayfinder by deleting `resources/js/{actions,routes}` + `npm run build` (artisan `wayfinder:generate` produces old format without `.form()`); run `composer test` = Pint + PHPStan + Sail PHPUnit. Playwright+Chromium is cached at `~/Library/Caches/ms-playwright` (temp project `/var/folders/.../T/opencode/pw/shot.js` pattern: login via `/login` form, screenshot dashboard) for visual checks.

## 5. Pending tasks (ordered)

1. P2 roles + building permissions (roles migration, enums, policies/middleware, seeder, Users & Access UI — inspect Figma frame first).
2. Wire P1 hooks: sidebar role filtering + role-workspace box showing actual role; dashboard controller swaps empty-state placeholders for real queries (P3); enable Upload/demo buttons (P4).
3. Inspect pending Figma frames via Figwright before building each UI (run Figwright `ping`).
4. P3–P9 per plan.

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
| Energy Manager / Facilities / Budget / Viewer frames | unknown | Pending inspection |
| Figwright MCP | — | Server + plugin verified working (v0.7.0, 2026-11-13); project `opencode.json` configured |

## 9. Latest verified test results

2026-11-13 (P1): Pint passed · PHPStan 0 errors · PHPUnit 39 tests / 36 passed / 3 skipped · `npm run types:check` clean · `npm run build` ✓ · Playwright visual check vs frame `2024:3` — match.

## 10. How to continue (any future session)

1. Read `AGENTS.md` → this file → relevant docs for your task.
2. Check §4 next task; check §6 blockers before touching affected areas.
3. Implement ONE planned task; run checks (`composer test`, `npm run types:check`, `npm run build`).
4. Append verified results to `docs/IMPLEMENTATION_PROGRESS.md`; update this file's §1/§4/§9.
5. If interrupted: finish the current file, record the exact next action in §4, stop.

**Rule:** on the instruction "continue", resume from the next incomplete documentation/implementation task listed here — do not regenerate completed docs.
