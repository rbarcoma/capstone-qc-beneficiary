# AI_HANDOFF.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13 · Read this file FIRST, every session.

## 1. Current status

- **Project stage:** documentation/knowledge-base complete; **zero application features implemented.**
- **Codebase:** fresh Laravel 13 + React 19 starter kit (audit: `docs/PROJECT_CONTEXT.md` §4). Auth pages, settings, placeholder dashboard only.
- **Knowledge base:** all 11 documentation files exist and are consistent (validated 2026-11-13).
- **Figma inspection:** Admin Dashboard frame `2024:3` verified (recorded from prior Codex session). All other role frames **pending inspection**.

## 2. Completed documentation

`AGENTS.md`, `README.md`, `docs/{PROJECT_CONTEXT, SYSTEM_ARCHITECTURE, ROLES_AND_PERMISSIONS, FIGMA_DESIGN_GUIDE, MODULES_AND_FEATURES, DATABASE_DESIGN, ML_AND_DSS_RULES, IMPLEMENTATION_PLAN, IMPLEMENTATION_PROGRESS, AI_HANDOFF}.md`, `opencode.json` (Figwright MCP).

## 3. Completed application modules

None. Starter-kit scaffolding only (Fortify auth, settings, layouts, shadcn UI kit).

## 4. Exact next recommended task

**P0 — Environment verification** (`docs/IMPLEMENTATION_PLAN.md`):
1. `./vendor/bin/sail up -d` → confirm MySQL (port 3309, `capstone_qc_beneficiary`).
2. Run `composer test`, `npm run types:check`, `npm run build`; record real results in `docs/IMPLEMENTATION_PROGRESS.md`.
3. Then start **P1** (layout/branding to match Dashboard frame `2024:3`).

Do not start P2+ before P0/P1 are verified.

## 5. Pending tasks (ordered)

1. P0 env verification (above).
2. P1 Figma layout system + branding.
3. P2 roles + building permissions (roles migration, policies, Users & Access UI).
4. Inspect pending Figma frames via Figwright before building each UI (restart the OpenCode session first so MCP tools load; run Figwright `ping`).
5. P3–P9 per plan.

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
| Admin / Dashboard | `2024:3` | Verified (prior Codex session; specs in `docs/FIGMA_DESIGN_GUIDE.md` §2.1) |
| Energy Manager / Facilities / Budget / Viewer frames | unknown | Pending inspection |
| Figwright MCP | — | Server verified 2026-11-13; project `opencode.json` added; **new session required** for tools |

## 9. Latest verified test results

None yet for application code (documentation session). P0 will establish the baseline.

## 10. How to continue (any future session)

1. Read `AGENTS.md` → this file → relevant docs for your task.
2. Check §4 next task; check §6 blockers before touching affected areas.
3. Implement ONE planned task; run checks (`composer test`, `npm run types:check`, `npm run build`).
4. Append verified results to `docs/IMPLEMENTATION_PROGRESS.md`; update this file's §1/§4/§9.
5. If interrupted: finish the current file, record the exact next action in §4, stop.

**Rule:** on the instruction "continue", resume from the next incomplete documentation/implementation task listed here — do not regenerate completed docs.
